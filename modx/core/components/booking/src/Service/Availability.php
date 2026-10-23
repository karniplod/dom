<?php
namespace Booking\Service;

use Booking\Model\Addon;
use Booking\Model\BlockedSlot;
use Booking\Model\Booking as BookingRow;
use Booking\Model\House;
use Booking\Model\Night;

/**
 * Занятость и пересечения. Истина — только сервер (docs/01).
 * Ночь дома — сутки с заездом в эту дату: бронь date_from…date_to занимает ночи date_from…date_to−1,
 * поэтому выезд и заезд в один день не пересекаются (время заезда ≥ времени выезда у всех домов).
 * Даты занимают только подтверждённые брони и закрытые даты; новая заявка ничего не занимает.
 */
class Availability
{
    protected Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    /** Ночи брони: from … to−1 */
    public static function nightsBetween(string $from, string $to): array
    {
        $out = [];
        $d = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        while ($d < $end) {
            $out[] = $d->format('Y-m-d');
            $d = $d->modify('+1 day');
        }
        return $out;
    }

    /** Минуты между ЧЧ:ММ:СС */
    public static function minutes(?string $from, ?string $to): int
    {
        return self::toMin($to) - self::toMin($from);
    }

    public static function toMin(?string $t): int
    {
        [$h, $m] = array_map('intval', explode(':', (string)$t) + [0, 0]);
        return $h * 60 + $m;
    }

    // ---------------------------------------------------------------- занятость дома

    /** Ночи, занятые подтверждёнными бронями: night => booking_id */
    public function bookedNights(int $houseId, string $from, string $to, int $exceptBooking = 0): array
    {
        $q = $this->booking->modx->newQuery(Night::class);
        $q->where(['house_id' => $houseId, 'night:>=' => $from, 'night:<' => $to]);
        if ($exceptBooking) {
            $q->where(['booking_id:!=' => $exceptBooking]);
        }
        $out = [];
        foreach ($this->booking->modx->getIterator(Night::class, $q) as $n) {
            $out[$n->get('night')] = (int)$n->get('booking_id');
        }
        return $out;
    }

    /** Ночи, закрытые администратором у дома (ремонт и пр.): night => reason */
    public function blockedNights(int $houseId, string $from, string $to): array
    {
        $q = $this->booking->modx->newQuery(BlockedSlot::class);
        $q->where(['house_id' => $houseId, 'addon_id:IS' => null, 'date_from:<' => $to, 'date_to:>' => $from]);
        $out = [];
        foreach ($this->booking->modx->getIterator(BlockedSlot::class, $q) as $b) {
            foreach (self::nightsBetween($b->get('date_from'), $b->get('date_to')) as $n) {
                if ($n >= $from && $n < $to) {
                    $out[$n] = $b->get('reason');
                }
            }
        }
        return $out;
    }

    /** Часы работы бани в форме гостя (как в booking.js): сеанс — с 08:00, заканчивается не позже 23:00 */
    public const BATH_OPEN = 8 * 60;
    public const BATH_CLOSE = 23 * 60;

    /** Заезд и выезд дома в минутах и перерыв на уборку бани */
    protected function houseClock(House $house): array
    {
        $addon = $this->booking->modx->getObject(Addon::class, ['house_id' => $house->get('id'), 'active' => 1]);
        return [self::toMin($house->get('checkin_time')), self::toMin($house->get('checkout_time')), $addon ? (int)$addon->get('buffer_minutes') : 30];
    }

    /**
     * Ночи дома, которые нельзя занять из-за подтверждённых сеансов бани: night => booking_id.
     * Сеанс в день D мешает ночи D, если не кончается за перерыв до заезда; мешает ночи D−1, если начинается раньше выезда плюс перерыв.
     * Бронь, в которой день D целиком внутри (ночи D−1 и D), — любой сеанс в этот день мешает.
     */
    public function addonSessionNights(House $house, string $from, string $to, int $exceptBooking = 0): array
    {
        [$in, $out, $buf] = $this->houseClock($house);
        $q = $this->booking->modx->newQuery(BookingRow::class);
        $q->where(['house_id' => $house->get('id'), 'addon_id:!=' => null, 'status' => 'confirmed',
            'date_from:>=' => $from, 'date_from:<=' => $to]);
        if ($exceptBooking) {
            $q->where(['id:!=' => $exceptBooking]);
        }
        $nights = [];
        foreach ($this->booking->modx->getIterator(BookingRow::class, $q) as $b) {
            $d = $b->get('date_from');
            $prev = (new \DateTimeImmutable($d))->modify('-1 day')->format('Y-m-d');
            $inStay = $d >= $from && $d < $to;           // в этот день заезд или гости живут дальше
            $outDay = $d > $from && $d <= $to;           // в этот день выезд из ночи D−1
            $a = self::toMin($b->get('time_from'));
            $z = self::toMin($b->get('time_to'));
            if ($inStay && ($outDay || $z + $buf > $in)) {
                $nights[$d] = $nights[$d] ?? (int)$b->get('id');
            }
            if ($outDay && ($inStay || $a - $buf < $out)) {
                $nights[$prev] = $nights[$prev] ?? (int)$b->get('id');
            }
        }
        ksort($nights);
        return $nights;
    }

    /** Для календаря Дома: ночи, которые сеансы бани делают недоступными (каждая ночь — сама по себе) */
    public function addonBlockedNights(House $house, string $from, string $to): array
    {
        [$in, $out, $buf] = $this->houseClock($house);
        $q = $this->booking->modx->newQuery(BookingRow::class);
        $q->where(['house_id' => $house->get('id'), 'addon_id:!=' => null, 'status' => 'confirmed',
            'date_from:>=' => $from, 'date_from:<=' => $to]);
        $nights = [];
        foreach ($this->booking->modx->getIterator(BookingRow::class, $q) as $b) {
            $d = $b->get('date_from');
            if (self::toMin($b->get('time_to')) + $buf > $in && $d < $to) $nights[$d] = true;
            $prev = (new \DateTimeImmutable($d))->modify('-1 day')->format('Y-m-d');
            if (self::toMin($b->get('time_from')) - $buf < $out && $prev >= $from) $nights[$prev] = true;
        }
        return array_keys($nights);
    }

    /**
     * Гости дома в день $date — интервалы для бани [from, to) без перерыва: заезд в эту дату — с заезда до 24:00,
     * выезд из прошлой ночи — с 00:00 до выезда; живут весь день — 00:00–24:00.
     */
    public function houseSegments(House $house, string $date, int $exceptBooking = 0): array
    {
        $prev = (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $next = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $nights = $this->bookedNights((int)$house->get('id'), $prev, $next, $exceptBooking);
        $p = $nights[$prev] ?? 0;
        $t = $nights[$date] ?? 0;
        if ($p && $t) {
            return [['from' => '00:00', 'to' => '24:00', 'booking_id' => $t, 'house' => true]];
        }
        $out = [];
        if ($p) $out[] = ['from' => '00:00', 'to' => substr($house->get('checkout_time'), 0, 5), 'booking_id' => $p, 'house' => true];
        if ($t) $out[] = ['from' => substr($house->get('checkin_time'), 0, 5), 'to' => '24:00', 'booking_id' => $t, 'house' => true];
        return $out;
    }

    /** Есть ли у бани свободное окно не короче минимума часов (08:00–23:00, перерывы на уборку учтены) */
    public function addonHasWindow(Addon $addon, array $busy): bool
    {
        $buf = (int)$addon->get('buffer_minutes');
        $min = (int)$addon->get('min_hours') * 60;
        $iv = [];
        foreach ($busy as $s) {
            $pad = empty($s['blocked']) ? $buf : 0;
            $iv[] = [self::toMin($s['from']) - $pad, self::toMin($s['to']) + $pad];
        }
        usort($iv, fn($x, $y) => $x[0] <=> $y[0]);
        $t = self::BATH_OPEN;
        foreach ($iv as [$a, $z]) {
            if ($a - $t >= $min) return true;
            $t = max($t, $z);
        }
        return self::BATH_CLOSE - $t >= $min;
    }

    /** Для календаря: занятые и закрытые ночи дома в диапазоне */
    public function houseCalendar(House $house, string $from, string $to): array
    {
        $busy = $this->bookedNights($house->get('id'), $from, $to);
        $blocked = $this->blockedNights($house->get('id'), $from, $to);
        return ['nights_busy' => array_keys($busy), 'nights_blocked' => array_keys($blocked)];
    }

    /**
     * Проверка, что ночи дома свободны. Бросает 409 с номером пересекающейся брони.
     * Сеанс бани своего дома в ночь брони — тоже пересечение.
     */
    public function assertHouseFree(House $house, string $from, string $to, int $exceptBooking = 0): void
    {
        $id = (int)$house->get('id');
        $busy = $this->bookedNights($id, $from, $to, $exceptBooking);
        if ($busy) {
            $first = reset($busy);
            throw new BookingError('Даты заняты: пересечение с бронью №' . $first, 409, [
                'field' => 'dates',
                'conflict' => ['booking_id' => $first, 'nights' => array_keys(array_filter($busy, fn($b) => $b === $first))],
            ]);
        }
        $blocked = $this->blockedNights($id, $from, $to);
        if ($blocked) {
            throw new BookingError('Даты закрыты: ' . reset($blocked), 409, [
                'field' => 'dates', 'conflict' => ['blocked' => true, 'nights' => array_keys($blocked)],
            ]);
        }
        $sessions = $this->addonSessionNights($house, $from, $to, $exceptBooking);
        if ($sessions) {
            $first = reset($sessions);
            throw new BookingError('Даты заняты: в эти дни подтверждён сеанс бани (бронь №' . $first . ') — он не успевает до заезда или после выезда с уборкой', 409, [
                'field' => 'dates', 'conflict' => ['booking_id' => $first, 'nights' => array_keys($sessions)],
            ]);
        }
    }

    // ---------------------------------------------------------------- баня

    /** Занятые интервалы бани на дату (подтверждённые сеансы и закрытия), без буфера */
    public function addonBusy(Addon $addon, string $date, int $exceptBooking = 0): array
    {
        $modx = $this->booking->modx;
        $out = [];
        $q = $modx->newQuery(BookingRow::class);
        $q->where(['addon_id' => $addon->get('id'), 'status' => 'confirmed', 'date_from' => $date]);
        if ($exceptBooking) {
            $q->where(['id:!=' => $exceptBooking]);
        }
        foreach ($modx->getIterator(BookingRow::class, $q) as $b) {
            $out[] = ['from' => substr($b->get('time_from'), 0, 5), 'to' => substr($b->get('time_to'), 0, 5), 'booking_id' => (int)$b->get('id')];
        }
        $q = $modx->newQuery(BlockedSlot::class);
        $q->where(['addon_id' => $addon->get('id'), 'date_from:<=' => $date, 'date_to:>=' => $date]);
        foreach ($modx->getIterator(BlockedSlot::class, $q) as $b) {
            $out[] = ['from' => $b->get('time_from') ? substr($b->get('time_from'), 0, 5) : '00:00',
                'to' => $b->get('time_to') ? substr($b->get('time_to'), 0, 5) : '24:00', 'blocked' => true];
        }
        // гости дома: с заезда и до выезда (перерыв на уборку добавляется при проверке, как между сеансами)
        $house = $modx->getObject(House::class, (int)$addon->get('house_id'));
        if ($house) {
            $out = array_merge($out, $this->houseSegments($house, $date));
        }
        return $out;
    }

    /**
     * Сеанс бани [from, to) не должен заходить в [чужой.from − буфер, чужой.to + буфер)
     * и не может быть в ночь подтверждённой брони своего дома или в закрытую ночь дома.
     */
    public function assertAddonFree(Addon $addon, string $date, string $timeFrom, string $timeTo, int $exceptBooking = 0): void
    {
        $houseId = (int)$addon->get('house_id');
        $next = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        // бронь Дома занимает баню по часам — с заезда и до выезда (см. houseSegments, проверка ниже вместе с сеансами)
        if ($this->blockedNights($houseId, $date, $next)) {
            throw new BookingError('Дата закрыта администратором', 409, ['field' => 'dates', 'conflict' => ['blocked' => true, 'nights' => [$date]]]);
        }
        $buf = (int)$addon->get('buffer_minutes');
        $a = self::toMin($timeFrom);
        $b = self::toMin($timeTo);
        foreach ($this->addonBusy($addon, $date, $exceptBooking) as $s) {
            $pad = empty($s['blocked']) ? $buf : 0;
            $sa = self::toMin($s['from']) - $pad;
            $sb = self::toMin($s['to']) + $pad;
            if ($a < $sb && $b > $sa) {
                $msg = !empty($s['house'])
                    ? 'Баня занята: в Доме гости (бронь №' . $s['booking_id'] . '), ' . ($s['from'] === '00:00' ? 'выезд до ' . $s['to'] : 'заезд с ' . $s['from'])
                      . ', ещё ' . $buf . ' минут на уборку'
                    : (empty($s['blocked'])
                    ? 'Баня занята с ' . $s['from'] . ' до ' . $s['to'] . ' (между сеансами ' . $buf . ' минут на уборку)'
                    : 'Это время закрыто администратором');
                throw new BookingError($msg, 409, ['field' => 'time', 'conflict' => ['booking_id' => $s['booking_id'] ?? null, 'slot' => $s]]);
            }
        }
    }

    // ---------------------------------------------------------------- запись занятости (в транзакции)

    /** Блокировка строки дома: параллельные подтверждения этого дома ждут друг друга (ст. 5.1) */
    public function lockHouse(int $houseId): void
    {
        $modx = $this->booking->modx;
        $stmt = $modx->prepare('SELECT id FROM ' . $modx->getTableName(House::class) . ' WHERE id = ? FOR UPDATE');
        if (!$stmt || !$stmt->execute([$houseId])) {
            throw new BookingError('Не удалось заблокировать Дом для проверки', 500);
        }
        $stmt->closeCursor();
    }

    /** Записать ночи подтверждённой брони; первичный ключ (house_id, night) не даст занять ночь дважды */
    public function occupy(BookingRow $b): void
    {
        if ($b->get('addon_id')) {
            return;  // сеанс бани ночей Дома не занимает
        }
        $modx = $this->booking->modx;
        $table = $modx->getTableName(Night::class);
        $stmt = $modx->prepare("INSERT INTO {$table} (house_id, night, booking_id) VALUES (?, ?, ?)");
        foreach (self::nightsBetween($b->get('date_from'), $b->get('date_to')) as $night) {
            if (!$stmt->execute([(int)$b->get('house_id'), $night, (int)$b->get('id')])) {
                $err = $stmt->errorInfo();
                if (($err[0] ?? '') === '23000') {
                    throw new BookingError('Даты заняты: ночь ' . $night . ' уже подтверждена в другой брони', 409, [
                        'field' => 'dates', 'conflict' => ['nights' => [$night]],
                    ]);
                }
                throw new BookingError('Ошибка записи занятости: ' . ($err[2] ?? ''), 500);
            }
        }
    }

    /** Освободить ночи брони (отмена, смена дат) */
    public function release(BookingRow $b): void
    {
        $this->booking->modx->removeCollection(Night::class, ['booking_id' => (int)$b->get('id')]);
    }
}
