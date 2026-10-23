<?php
namespace Booking\Service;

use Booking\Model\Addon;
use Booking\Model\Booking as BookingRow;
use Booking\Model\House;

/**
 * Общие правила заявки и брони: проверка входа, расчёт, создание, подтверждение, вывод.
 * Используется процессорами гостя и администратора — проверки одни и те же.
 */
class Bookings
{
    public const STATUS_LABELS = ['new' => 'Новая', 'confirmed' => 'Подтверждена', 'cancelled' => 'Отменена', 'completed' => 'Завершена'];
    public const DEPOSIT_LABELS = ['not_set' => 'Не назначен', 'pending' => 'Ожидается', 'paid' => 'Внесён', 'returned' => 'Возвращён'];
    public const DEPOSIT_MIN = 5000;
    public const DEPOSIT_MAX = 20000;
    public const MAX_RANGE_DAYS = 92;

    protected Booking $booking;

    /**
     * Текст гостя без тегов MODX: «[[…]]» в имени, комментарии или причине отмены иначе выполнится,
     * когда значение попадёт в чанк письма (MODX разбирает подставленные плейсхолдеры повторно).
     * В API-режиме (api.php, SendIt) MODX сам запрос не очищает.
     */
    public static function clean(string $s): string
    {
        $s = strip_tags($s);
        // повторяем, пока меняется: удаление «]]» в «[]][» само даёт новый «[[»
        do {
            $before = $s;
            $s = str_replace(['[[', ']]'], '', $s);
        } while ($s !== $before);
        return trim($s);
    }

    /** Значение для чанка (письма): HTML и служебные символы MODX — как сущности, чтобы парсер их не тронул */
    public static function forChunk($s): string
    {
        return str_replace(['[', ']', '`', '{', '}'], ['&#91;', '&#93;', '&#96;', '&#123;', '&#125;'], htmlspecialchars((string)$s));
    }

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /**
     * Проверка и нормализация данных заявки.
     * $p: house (code), addon (1 — баня отдельно), date_from, date_to, time_from, time_to, guests, pets, name, phone, comment.
     * $contacts — проверять имя и телефон; $pastAllowed — администратору можно вносить прошедшие даты.
     */
    public function input(array $p, bool $contacts = true, bool $pastAllowed = false): array
    {
        $b = $this->booking;
        $house = $p['house'] instanceof House ? $p['house'] : $b->house(trim((string)($p['house'] ?? '')), !$pastAllowed);
        $addon = null;
        $from = $b->date($p['date_from'] ?? null);
        if (!$pastAllowed && $from < $b->today()) {
            throw BookingError::field('dates', 'Дата заезда уже прошла');
        }
        $in = ['house' => $house, 'addon' => null, 'date_from' => $from, 'time_from' => null, 'time_to' => null];

        if (!empty($p['addon']) && $p['addon'] !== '0') {
            // баня отдельно — только если администратор включил это у дома (FR-030)
            $addon = $b->modx->getObject(Addon::class, ['house_id' => $house->get('id'), 'active' => 1]);
            if (!$addon || !$house->get('addon_bookable_separately')) {
                throw BookingError::field('addon', 'Баню в этом доме отдельно не бронируют');
            }
            $tf = $b->time($p['time_from'] ?? null, 'time');
            $tt = $b->time($p['time_to'] ?? null, 'time');
            $mins = Availability::minutes($tf, $tt);
            if ($mins < (int)$addon->get('min_hours') * 60) {
                throw BookingError::field('time', 'Баня — от ' . Pricing::hours((int)$addon->get('min_hours')));
            }
            if ($mins % 30 !== 0) {
                throw BookingError::field('time', 'Время — с шагом 30 минут');
            }
            $in['addon'] = $addon;
            $in['date_to'] = $from;
            $in['time_from'] = $tf;
            $in['time_to'] = $tt;
        } else {
            $to = $b->date($p['date_to'] ?? null);
            if ($to <= $from) {
                throw BookingError::field('dates', 'Дата выезда должна быть позже даты заезда');
            }
            if (count(Availability::nightsBetween($from, $to)) > 60) {
                throw BookingError::field('dates', 'Слишком долгий срок — позвоните администратору');
            }
            $in['date_to'] = $to;
        }

        $guests = (int)($p['guests'] ?? 0);
        $max = (int)$house->get('capacity_party');
        if ($guests < 1) {
            throw BookingError::field('guests', 'Укажите число гостей');
        }
        if ($guests > $max) {
            throw BookingError::field('guests', 'В доме «' . $house->get('name') . '» не больше ' . $max . ' гостей');
        }
        $pets = (int)($p['pets'] ?? 0);
        if ($pets < 0 || $pets > 10) {
            throw BookingError::field('pets', 'Неверное число питомцев');
        }
        $in['guests'] = $guests;
        $in['pets'] = $pets;

        if ($contacts) {
            $name = mb_substr(self::clean((string)($p['name'] ?? '')), 0, 100);
            if (mb_strlen($name) < 2) {
                throw BookingError::field('name', 'Как к вам обращаться?');
            }
            $in['name'] = $name;
            $in['phone'] = $b->phone($p['phone'] ?? '');
            $in['comment'] = mb_substr(self::clean((string)($p['comment'] ?? '')), 0, 2000);
        }
        return $in;
    }

    public function quote(array $in, bool $discount): array
    {
        return $this->booking->pricing()->quote($in['house'], $in['date_from'], $in['date_to'], $in['guests'], $in['pets'],
            $discount, $in['addon'], $in['time_from'], $in['time_to']);
    }

    /** Свободно ли сейчас (без блокировки) — для заявки гостя и календаря */
    public function assertFree(array $in, int $except = 0): void
    {
        $av = $this->booking->availability();
        if ($in['addon']) {
            $av->assertAddonFree($in['addon'], $in['date_from'], $in['time_from'], $in['time_to'], $except);
        } else {
            $av->assertHouseFree($in['house'], $in['date_from'], $in['date_to'], $except);
        }
    }

    /** Создать запись брони. Возвращает [BookingRow, код гостя] */
    public function create(array $in, array $quote, string $status, string $source, array $extra = []): array
    {
        $b = $this->booking;
        $code = Access::guestCode();
        /** @var BookingRow $row */
        $row = $b->modx->newObject(BookingRow::class);
        $row->fromArray(array_merge([
            'house_id' => $in['house']->get('id'),
            'addon_id' => $in['addon'] ? $in['addon']->get('id') : null,
            'date_from' => $in['date_from'],
            'date_to' => $in['date_to'],
            'time_from' => $in['time_from'],
            'time_to' => $in['time_to'],
            'guests_count' => $in['guests'],
            'pets_count' => $in['pets'],
            'guest_name' => $in['name'],
            'guest_phone' => $in['phone'],
            'guest_comment' => $in['comment'] ?? '',
            'quote_total' => $quote['total'],
            'quote_discount' => $quote['discount'],
            'quote_lines' => $quote['lines'],
            'status' => $status,
            'deposit_amount' => null,
            'deposit_status' => 'not_set',
            'source' => $source,
            'guest_token_hash' => Access::hash($code),
            'created_at' => $b->now(),
            'updated_at' => $b->now(),
            'updated_by' => $b->actor()['name'],
        ], $extra));
        if (!$row->save()) {
            throw new BookingError('Не удалось сохранить заявку', 500);
        }
        return [$row, $code];
    }

    /** Проверка суммы залога: NULL или 5 000…20 000 (ст. 4) */
    public function depositAmount($value): ?int
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }
        if (!is_scalar($value) || !preg_match('~^\d+$~', trim((string)$value))) {
            throw BookingError::field('deposit_amount', 'Залог — от 5 000 до 20 000 ₽');
        }
        $n = (int)$value;
        if ($n < self::DEPOSIT_MIN || $n > self::DEPOSIT_MAX) {
            throw BookingError::field('deposit_amount', 'Залог — от 5 000 до 20 000 ₽');
        }
        return $n;
    }

    /**
     * Занять даты брони: блокировка строки дома, проверка пересечений, запись ночей.
     * Вызывать внутри транзакции.
     */
    public function occupy(BookingRow $row): void
    {
        $b = $this->booking;
        $av = $b->availability();
        $house = $b->modx->getObject(House::class, (int)$row->get('house_id'));
        $av->lockHouse((int)$row->get('house_id'));
        if ($row->get('addon_id')) {
            $addon = $b->modx->getObject(Addon::class, (int)$row->get('addon_id'));
            $av->assertAddonFree($addon, $row->get('date_from'), $row->get('time_from'), $row->get('time_to'), (int)$row->get('id'));
        } else {
            $av->assertHouseFree($house, $row->get('date_from'), $row->get('date_to'), (int)$row->get('id'));
            $av->occupy($row);
        }
    }

    public function touch(BookingRow $row): void
    {
        $row->set('updated_at', $this->booking->now());
        $row->set('updated_by', $this->booking->actor()['name']);
    }

    public function get(int $id): BookingRow
    {
        $row = $id ? $this->booking->modx->getObject(BookingRow::class, $id) : null;
        if (!$row) {
            throw new BookingError('Заявка №' . $id . ' не найдена', 404);
        }
        return $row;
    }

    // ---------------------------------------------------------------- вывод

    protected array $houses = [];

    public function houseOf(BookingRow $row): ?House
    {
        $id = (int)$row->get('house_id');
        return $this->houses[$id] ??= $this->booking->modx->getObject(House::class, $id);
    }

    public function datesText(BookingRow $row): string
    {
        $f = fn($d) => date('d.m.Y', strtotime($d));
        if ($row->get('addon_id')) {
            return $f($row->get('date_from')) . ', ' . substr($row->get('time_from'), 0, 5) . '–' . substr($row->get('time_to'), 0, 5);
        }
        return $f($row->get('date_from')) . ' – ' . $f($row->get('date_to'));
    }

    /** Для гостя: без служебных полей */
    public function toGuest(BookingRow $row, string $code = ''): array
    {
        $house = $this->houseOf($row);
        $out = [
            'id' => (int)$row->get('id'),
            'house' => $house ? $house->get('code') : '',
            'house_name' => $house ? $house->get('name') : '',
            'addon' => (bool)$row->get('addon_id'),
            'date_from' => $row->get('date_from'),
            'date_to' => $row->get('date_to'),
            'time_from' => $row->get('time_from') ? substr($row->get('time_from'), 0, 5) : null,
            'time_to' => $row->get('time_to') ? substr($row->get('time_to'), 0, 5) : null,
            'checkin' => $house ? substr($house->get('checkin_time'), 0, 5) : '',
            'checkout' => $house ? substr($house->get('checkout_time'), 0, 5) : '',
            'dates' => $this->datesText($row),
            'guests' => (int)$row->get('guests_count'),
            'pets' => (int)$row->get('pets_count'),
            'total' => (int)$row->get('quote_total'),
            'lines' => $row->get('quote_lines') ?: [],
            'status' => $row->get('status'),
            'status_label' => self::STATUS_LABELS[$row->get('status')] ?? '',
            'cancellation_requested' => (bool)$row->get('cancellation_requested'),
            'deposit_amount' => $row->get('deposit_amount') !== null ? (int)$row->get('deposit_amount') : null,
            'deposit_status' => $row->get('deposit_status'),
            'deposit_label' => self::DEPOSIT_LABELS[$row->get('deposit_status')] ?? '',
        ];
        if ($code !== '') {
            $out['code'] = $code;
            $out['link'] = $this->booking->requestLink($out['id'], $code);
        }
        return $out;
    }

    /** Для администратора: все поля */
    public function toAdmin(BookingRow $row): array
    {
        $out = $this->toGuest($row);
        $out += [
            'house_id' => (int)$row->get('house_id'),
            'addon_id' => $row->get('addon_id') ? (int)$row->get('addon_id') : null,
            'guest_name' => $row->get('guest_name'),
            'guest_phone' => $row->get('guest_phone'),
            'guest_comment' => (string)$row->get('guest_comment'),
            'quote_discount' => (int)$row->get('quote_discount'),
            'cancellation_requested_by' => $row->get('cancellation_requested_by'),
            'cancellation_reason' => (string)$row->get('cancellation_reason'),
            'source' => $row->get('source'),
            'legacy_lead_id' => $row->get('legacy_lead_id') ? (int)$row->get('legacy_lead_id') : null,
            'admin_note' => (string)$row->get('admin_note'),
            'created_at' => $row->get('created_at'),
            'updated_at' => $row->get('updated_at'),
            'updated_by' => $row->get('updated_by'),
        ];
        return $out;
    }

    /** Поля для письма и сообщения в MAX */
    public function placeholders(BookingRow $row): array
    {
        $a = $this->toAdmin($row);
        $lines = '';
        foreach ($a['lines'] as $l) {
            $lines .= '<tr><td>' . self::forChunk($l['label']) . '</td><td style="text-align:right">' . $this->booking->money((int)$l['amount']) . '</td></tr>';
        }
        return [
            'booking_id' => $a['id'],
            'house_name' => self::forChunk($a['house_name']),
            'dates' => self::forChunk($a['dates'] . ($a['addon'] ? ' (баня отдельно)' : '')),
            'guests' => $a['guests'],
            'pets' => $a['pets'],
            'total' => $this->booking->money($a['total']),
            'discount' => $a['quote_discount'] ? $this->booking->money($a['quote_discount']) : '',
            'lines' => $lines,
            'guest_name' => self::forChunk($a['guest_name']),
            'guest_phone' => self::forChunk($a['guest_phone']),
            'guest_comment' => nl2br(self::forChunk($a['guest_comment'])),
            'cancellation_reason' => nl2br(self::forChunk($a['cancellation_reason'])),
            'admin_link' => htmlspecialchars(rtrim($this->booking->modx->getOption('site_url'), '/') . '/'
                . trim($this->booking->modx->getOption('manager_url', null, 'manager/'), '/') . '/?a=index&namespace=booking#id=' . $a['id']),
        ];
    }
}
