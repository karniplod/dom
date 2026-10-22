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
     * Расчёт брони дома или бани.
     * $discount — применять скидку ВКонтакте (booking.vk_discount, %): только в брони администратора по галочке;
     * заявки с сайта, из приложения и MAX — без скидки (решение владельца 22.10.2026).
     * Ответ: lines [{label, qty, amount}], subtotal, discount, discount_percent, total, nights.
     */
    public function quote(House $house, string $from, string $to, int $guests, int $pets, bool $discount = true,
                          ?Addon $addon = null, ?string $timeFrom = null, ?string $timeTo = null): array
    {
        $lines = [];
        $nights = [];
        if ($addon) {
            $hours = Availability::minutes($timeFrom, $timeTo) / 60;
            $pph = (int)$addon->get('price_per_hour');
            if ($pph <= 0) {
                throw new BookingError('Цена бани не задана — позвоните администратору', 400, ['price_missing' => true, 'field' => 'addon']);
            }
            $lines[] = ['label' => $addon->get('name') . ', ' . self::hours($hours), 'qty' => $hours, 'amount' => (int)round($hours * $pph)];
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
        $percent = $discount ? max(0, min(100, (int)$this->booking->modx->getOption('booking.vk_discount', null, 5))) : 0;
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
