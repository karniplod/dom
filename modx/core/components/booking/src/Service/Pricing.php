<?php
namespace Booking\Service;

use Booking\Model\Addon;
use Booking\Model\Holiday;
use Booking\Model\HolidayPrice;
use Booking\Model\House;
use Booking\Model\PriceRule;

/**
 * Расчёт стоимости. Сервер всегда считает сам — цифрам из браузера не верим (ст. 6).
 */
class Pricing
{
    public const DAY_TYPES = ['weekday', 'friday', 'saturday', 'sunday'];
    public const DAY_LABELS = [
        'weekday' => 'Понедельник – четверг',
        'friday' => 'Пятница',
        'saturday' => 'Суббота',
        'sunday' => 'Воскресенье',
    ];

    /**
     * Правило праздников — в коде, не в БД (docs/03, конституция ст. 6.2):
     * предпраздничный рабочий день = пятница, праздник перед выходным = суббота,
     * праздник перед рабочим днём = воскресенье.
     */
    public const HOLIDAY_RULE = [
        'pre_holiday_workday' => 'friday',
        'holiday_before_dayoff' => 'saturday',
        'holiday_before_workday' => 'sunday',
    ];

    protected Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /** Тип суток по дате заезда этих суток (без учёта праздников): Пт, Сб, Вс, иначе будни */
    public static function weekdayType(string $date): string
    {
        $n = (int)date('N', strtotime($date . ' 12:00:00'));
        return [5 => 'friday', 6 => 'saturday', 7 => 'sunday'][$n] ?? 'weekday';
    }

    /** Правила цен дома: day_type => PriceRule */
    public function rules(int $houseId): array
    {
        $rules = [];
        foreach ($this->booking->modx->getIterator(PriceRule::class, ['house_id' => $houseId]) as $r) {
            $rules[$r->get('day_type')] = $r;
        }
        return $rules;
    }

    /**
     * Цены суток по датам: date => ['type', 'label', 'price', 'holiday'].
     * Особая цена праздника у дома (FR-003a) перекрывает тариф по правилу.
     */
    public function nights(House $house, string $from, string $to): array
    {
        $rules = $this->rules($house->get('id'));
        $dates = Availability::nightsBetween($from, $to);
        $holidays = [];
        if ($dates) {
            $q = $this->booking->modx->newQuery(Holiday::class);
            $q->where(['date:>=' => $dates[0], 'date:<=' => end($dates)]);
            foreach ($this->booking->modx->getIterator(Holiday::class, $q) as $h) {
                $special = $this->booking->modx->getObject(HolidayPrice::class, [
                    'holiday_id' => $h->get('id'), 'house_id' => $house->get('id'),
                ]);
                $holidays[$h->get('date')] = [
                    'type' => self::HOLIDAY_RULE[$h->get('holiday_type')] ?? 'saturday',
                    'comment' => $h->get('comment'),
                    'special' => $special ? (int)$special->get('price') : null,
                ];
            }
        }
        $out = [];
        foreach ($dates as $d) {
            $hol = $holidays[$d] ?? null;
            $type = $hol ? $hol['type'] : self::weekdayType($d);
            if ($hol && $hol['special']) {
                $price = $hol['special'];
                $label = 'Праздник' . ($hol['comment'] !== '' ? ' (' . $hol['comment'] . ')' : '');
            } else {
                if (!isset($rules[$type]) || (int)$rules[$type]->get('price') <= 0) {
                    throw new BookingError('Цена на эти даты не задана — позвоните администратору', 400, ['price_missing' => true, 'field' => 'dates']);
                }
                $price = (int)$rules[$type]->get('price');
                $label = $hol ? 'Праздник — по тарифу «' . self::DAY_LABELS[$type] . '»' : self::DAY_LABELS[$type];
            }
            $out[$d] = ['type' => $type, 'label' => $label, 'price' => $price, 'holiday' => (bool)$hol];
        }
        return $out;
    }

    /**
     * Цена бани за час на дату: особая цена праздника у дома → цена по типу дня (с учётом правила праздников) →
     * базовая цена бани. Возвращает [цена, подпись дня или '' для базовой].
     */
    public function addonPrice(House $house, Addon $addon, string $date): array
    {
        $modx = $this->booking->modx;
        $hol = $modx->getObject(Holiday::class, ['date' => $date]);
        if ($hol) {
            $sp = $modx->getObject(HolidayPrice::class, ['holiday_id' => $hol->get('id'), 'house_id' => $house->get('id')]);
            if ($sp && (int)$sp->get('addon_price') > 0) {
                return [(int)$sp->get('addon_price'), $hol->get('comment') !== '' ? $hol->get('comment') : 'праздник'];   // «(8 Марта)» или «(праздник)»
            }
        }
        $type = $hol ? (self::HOLIDAY_RULE[$hol->get('holiday_type')] ?? 'saturday') : self::weekdayType($date);
        $rules = $this->rules($house->get('id'));
        if (isset($rules[$type]) && (int)$rules[$type]->get('addon_price') > 0) {
            return [(int)$rules[$type]->get('addon_price'), $hol ? 'праздник по тарифу «' . self::DAY_LABELS[$type] . '»' : mb_strtolower(self::DAY_LABELS[$type])];
        }
        return [(int)$addon->get('price_per_hour'), ''];
    }

    /**
     * Сеанс бани по ценам часов суток (Addon.rates): отрезки [from, to, hours, price, day] с одной ценой.
     * Особая цена праздника — на весь сеанс; час вне промежутков rates — цена дня (addonPrice).
     * Промежуток «18:00–09:00» переходит через полночь: вечер этого дня и утро до 09:00.
     */
    public function addonParts(House $house, Addon $addon, string $date, string $timeFrom, string $timeTo): array
    {
        [$base, $day] = $this->addonPrice($house, $addon, $date);
        $hol = $this->booking->modx->getObject(Holiday::class, ['date' => $date]);
        $special = $hol && ($sp = $this->booking->modx->getObject(HolidayPrice::class, ['holiday_id' => $hol->get('id'), 'house_id' => $house->get('id')]))
            && (int)$sp->get('addon_price') > 0;
        $type = $hol ? (self::HOLIDAY_RULE[$hol->get('holiday_type')] ?? 'saturday') : self::weekdayType($date);
        $rates = $special ? [] : array_values(array_filter(self::rates($addon->get('rates')), function ($r) use ($type) { return in_array($type, $r['days'], true); }));
        $a = Availability::toMin($timeFrom);
        $z = Availability::toMin($timeTo);
        $parts = [];
        for ($m = $a; $m < $z; $m += 30) {
            $price = $base;
            foreach ($rates as $r) {   // последний подходящий промежуток главнее
                $f = Availability::toMin($r['from']);
                $t = Availability::toMin($r['to']);
                if ($f < $t ? ($m >= $f && $m < $t) : ($m >= $f || $m < $t)) $price = $r['price'];
            }
            if ($price <= 0) {
                throw new BookingError('Цена бани не задана — позвоните администратору', 400, ['price_missing' => true, 'field' => 'addon']);
            }
            $last = $parts ? count($parts) - 1 : -1;
            if ($last >= 0 && $parts[$last]['price'] === $price) {
                $parts[$last]['end'] = $m + 30;
            } else {
                $parts[] = ['start' => $m, 'end' => $m + 30, 'price' => $price];
            }
        }
        $fmt = function (int $m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); };
        return array_map(function ($p) use ($fmt, $day) {
            return ['from' => $fmt($p['start']), 'to' => $fmt($p['end']), 'hours' => ($p['end'] - $p['start']) / 60, 'price' => $p['price'], 'day' => $day];
        }, $parts);
    }

    /**
     * Расписание цен бани на сутки по типам дней — для админки, прайс-листа и бота:
     * [['days' => [типы], 'label' => 'Понедельник – пятница', 'parts' => [['from','to','price']]]].
     * Одинаковые дни объединены; вечер и утро с одной ценой — одним промежутком через полночь («18:00–09:00»).
     */
    public function schedule(House $house, Addon $addon): array
    {
        $rules = $this->rules((int)$house->get('id'));
        $rates = self::rates($addon->get('rates'));
        $fmt = function (int $m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); };
        $byType = [];
        foreach (self::DAY_TYPES as $t) {
            $base = isset($rules[$t]) && (int)$rules[$t]->get('addon_price') > 0 ? (int)$rules[$t]->get('addon_price') : (int)$addon->get('price_per_hour');
            $own = array_filter($rates, function ($r) use ($t) { return in_array($t, $r['days'], true); });
            $parts = [];
            for ($m = 0; $m < 1440; $m += 30) {
                $price = $base;
                foreach ($own as $r) {
                    $f = Availability::toMin($r['from']);
                    $z = Availability::toMin($r['to']);
                    if ($f < $z ? ($m >= $f && $m < $z) : ($m >= $f || $m < $z)) $price = $r['price'];
                }
                $n = count($parts);
                if ($n && $parts[$n - 1]['price'] === $price) $parts[$n - 1]['end'] = $m + 30;
                else $parts[] = ['start' => $m, 'end' => $m + 30, 'price' => $price];
            }
            $n = count($parts);
            if ($n > 1 && $parts[0]['price'] === $parts[$n - 1]['price']) {   // утро и вечер с одной ценой — через полночь
                $parts[0]['start'] = $parts[$n - 1]['start'];
                array_pop($parts);
                $parts[] = array_shift($parts);
            }
            $byType[$t] = array_map(function ($p) use ($fmt, $n) {
                return ['from' => $n > 1 ? $fmt($p['start']) : '', 'to' => $n > 1 ? $fmt($p['end']) : '', 'price' => $p['price']];
            }, $parts);
        }
        $groups = [];
        foreach ($byType as $t => $parts) {
            $key = json_encode($parts);
            $groups[$key] ??= ['days' => [], 'parts' => $parts];
            $groups[$key]['days'][] = $t;
        }
        return array_map(function ($g) { return $g + ['label' => self::daysLabel($g['days'])]; }, array_values($groups));
    }

    public static function daysLabel(array $days): string
    {
        $known = ['weekday' => 'Понедельник – четверг', 'weekday,friday' => 'Понедельник – пятница', 'saturday,sunday' => 'Суббота и воскресенье',
            'friday,saturday,sunday' => 'Пятница – воскресенье', 'weekday,friday,saturday,sunday' => 'Все дни'];
        $key = implode(',', $days);
        return $known[$key] ?? implode(', ', array_map(function ($t) { return self::DAY_LABELS[$t]; }, $days));
    }

    /**
     * Проверка и нормализация цен бани по часам суток из админки (JSON или массив).
     * Строка: days — типы дней, from/to — ЧЧ:ММ с шагом 30 минут (to может быть 24:00, to ≤ from — через полночь), price > 0.
     */
    public static function rates($value): array
    {
        if (is_string($value)) $value = json_decode($value, true);
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $i => $r) {
            if (!is_array($r)) continue;
            $days = array_values(array_intersect(self::DAY_TYPES, (array)($r['days'] ?? [])));
            $from = (string)($r['from'] ?? '');
            $to = (string)($r['to'] ?? '');
            $price = (int)($r['price'] ?? 0);
            if (!$days && !$from && !$to && !$price) continue;   // пустая строка в админке
            $ok = '~^(([01]\d|2[0-3]):(00|30)|24:00)$~';
            if (!$days || !preg_match($ok, $from) || !preg_match($ok, $to) || $from === '24:00' || $from === $to || $price <= 0) {
                throw BookingError::field('rates', 'Цены бани по часам, строка ' . ($i + 1) . ': дни, время «с» и «до» (шаг 30 минут) и цена больше нуля');
            }
            $out[] = ['days' => $days, 'from' => $from, 'to' => $to, 'price' => $price];
        }
        return $out;
    }

    /**
     * Расчёт брони дома или бани.
     * $discount — скидка booking.vk_discount, %. Акция ВКонтакте отменена (решение владельца 27.10.2026): настройки нет,
     * скидка 0; старые брони со скидкой при правке пересчитываются без неё.
     * Ответ: lines [{label, qty, amount}], subtotal, discount, discount_percent, total, nights.
     */
    public function quote(House $house, string $from, string $to, int $guests, int $pets, bool $discount = true,
                          ?Addon $addon = null, ?string $timeFrom = null, ?string $timeTo = null): array
    {
        $lines = [];
        $nights = [];
        if ($addon) {
            $hours = Availability::minutes($timeFrom, $timeTo) / 60;
            $parts = $this->addonParts($house, $addon, $from, $timeFrom, $timeTo);
            foreach ($parts as $p) {
                // «Баня, 3 часа × 1 800 ₽ (суббота)» или по часам суток: «Баня, 2 часа × 2 000 ₽ (18:00–20:00)»
                $note = count($parts) > 1 ? $p['from'] . '–' . $p['to'] : $p['day'];
                $label = $addon->get('name') . ', ' . self::hours($p['hours']) . ' × ' . $this->booking->money($p['price']) . ($note !== '' ? ' (' . $note . ')' : '');
                $lines[] = ['label' => $label, 'qty' => $p['hours'], 'amount' => (int)round($p['hours'] * $p['price'])];
            }
            $limit = (int)$addon->get('guest_limit');
            $extraPrice = (int)$addon->get('extra_guest_price');
            $extra = $limit > 0 ? max(0, $guests - $limit) : 0;
            if ($extra > 0 && $extraPrice > 0) {
                $perHour = $addon->get('extra_guest_per') !== 'booking';
                $lines[] = ['label' => 'Гости сверх ' . $limit . ': ' . $extra . ' × ' . $this->booking->money($extraPrice) . ($perHour ? ' × ' . self::hours($hours) : ''),
                    'qty' => $extra, 'amount' => (int)round($extra * $extraPrice * ($perHour ? $hours : 1))];
            }
        } else {
            $nights = $this->nights($house, $from, $to);
            // одинаковые тарифы складываем в одну строку
            $groups = [];
            foreach ($nights as $n) {
                $key = $n['label'] . '|' . $n['price'];
                $groups[$key] ??= ['label' => $n['label'], 'qty' => 0, 'price' => $n['price'], 'amount' => 0];
                $groups[$key]['qty']++;
                $groups[$key]['amount'] += $n['price'];
            }
            foreach ($groups as $g) {
                $lines[] = ['label' => $g['label'] . ' × ' . $g['qty'], 'qty' => $g['qty'], 'amount' => $g['amount']];
            }
            // доплаты берутся из строки будней: в админке они одинаковы во всех строках дома
            $rules = $this->rules($house->get('id'));
            $base = $rules['weekday'] ?? reset($rules);
            $limit = (int)$base->get('extra_guest_limit');
            $extra = max(0, $guests - $limit);
            if ($extra > 0) {
                // доплата за гостя сверх нормы — один раз за всё время отдыха
                $lines[] = ['label' => 'Гости сверх ' . $limit . ': ' . $extra . ' × ' . $this->booking->money((int)$base->get('extra_guest_price')),
                    'qty' => $extra, 'amount' => $extra * (int)$base->get('extra_guest_price')];
            }
            if ($pets > 0) {
                // питомец — один раз за всё время отдыха
                $lines[] = ['label' => 'Питомцы: ' . $pets . ' × ' . $this->booking->money((int)$base->get('pet_price')),
                    'qty' => $pets, 'amount' => $pets * (int)$base->get('pet_price')];
            }
        }
        $subtotal = array_sum(array_column($lines, 'amount'));
        $percent = $discount ? max(0, min(100, (int)$this->booking->modx->getOption('booking.vk_discount', null, 0))) : 0;
        $disc = (int)round($subtotal * $percent / 100);
        if ($disc > 0) {
            $lines[] = ['label' => 'Скидка ' . $percent . '% при бронировании через ВКонтакте', 'qty' => 1, 'amount' => -$disc];
        }
        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $disc,
            'discount_percent' => $percent,
            'total' => $subtotal - $disc,
            'nights' => $nights,
            'checkin' => substr($house->get('checkin_time'), 0, 5),
            'checkout' => substr($house->get('checkout_time'), 0, 5),
        ];
    }

    public static function hours(float $h): string
    {
        $n = (int)$h;
        $word = ($n % 10 === 1 && $n % 100 !== 11) ? 'час' : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 'часа' : 'часов');
        return ($h == $n ? $n : str_replace('.', ',', (string)$h)) . ' ' . ($h == $n ? $word : 'часа');
    }
}
