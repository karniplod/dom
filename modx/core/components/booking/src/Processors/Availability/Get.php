<?php
namespace Booking\Processors\Availability;

use Booking\Model\Addon;
use Booking\Processors\Base;
use Booking\Service\Availability;
use Booking\Service\BookingError;
use Booking\Service\Bookings;

/**
 * booking_get_availability — занятые даты дома (или интервалы бани на дату).
 * Гость видит один список busy: подтверждённые брони и закрытые даты (ст. 5.2). Ответ не кешируется.
 */
class Get extends Base
{
    public static string $role = 'guest';

    protected function handle(): array
    {
        $b = $this->booking;
        $house = $b->house($this->str('house', 20));

        if ($this->bool('addon')) {
            $addon = $this->modx->getObject(Addon::class, ['house_id' => $house->get('id'), 'active' => 1]);
            if (!$addon || !$house->get('addon_bookable_separately')) {
                throw BookingError::field('addon', 'Баню в этом доме отдельно не бронируют');
            }
            $date = $b->date($this->str('date', 10));
            $next = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
            $houseBusy = $b->availability()->bookedNights($house->get('id'), $date, $next)
                || $b->availability()->blockedNights($house->get('id'), $date, $next);
            $slots = array_map(fn($s) => ['from' => $s['from'], 'to' => $s['to']], $b->availability()->addonBusy($addon, $date));
            return [
                'house' => $house->get('code'),
                'date' => $date,
                'day_busy' => (bool)$houseBusy,
                'slots_busy' => $slots,
                'buffer_minutes' => (int)$addon->get('buffer_minutes'),
                'min_hours' => (int)$addon->get('min_hours'),
                'price_per_hour' => (int)$addon->get('price_per_hour'),
            ];
        }

        $from = $b->date($this->str('from', 10), 'from');
        $to = $b->date($this->str('to', 10), 'to');
        if ($to <= $from || count(Availability::nightsBetween($from, $to)) > Bookings::MAX_RANGE_DAYS) {
            throw new BookingError('Диапазон — не больше ' . Bookings::MAX_RANGE_DAYS . ' дней', 400);
        }
        $cal = $b->availability()->houseCalendar($house, $from, $to);
        // сутки, в которые подтверждён сеанс бани дома, для брони дома тоже заняты
        $sessions = array_keys($b->availability()->addonSessionDates($house->get('id'), $from, $to));
        $out = [
            'house' => $house->get('code'),
            'from' => $from,
            'to' => $to,
            'today' => $b->today(),
            'busy' => array_values(array_unique(array_merge($cal['nights_busy'], $cal['nights_blocked'], $sessions))),
        ];
        sort($out['busy']);
        // для бани отдельно: день занят только ночью брони Дома или закрытием — сеансы бани проверяются по часам
        $bath = array_values(array_unique(array_merge($cal['nights_busy'], $cal['nights_blocked'])));
        sort($bath);
        $out['busy_bath'] = $bath;
        if ($b->isAdmin()) {
            $out['nights_busy'] = $cal['nights_busy'];
            $out['nights_blocked'] = $cal['nights_blocked'];
        }
        return $out;
    }
}
