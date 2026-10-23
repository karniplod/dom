<?php
namespace Booking\Processors\Availability;

use Booking\Model\Addon;
use Booking\Model\BlockedSlot;
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
            $busy = $b->availability()->addonBusy($addon, $date);
            // день занят: Дом закрыт администратором или не осталось окна на минимум часов
            $dayBusy = $b->availability()->blockedNights($house->get('id'), $date, $next) || !$b->availability()->addonHasWindow($addon, $busy);
            $slots = array_map(fn($s) => ['from' => $s['from'], 'to' => $s['to']] + (!empty($s['house']) ? ['house' => true] : []), $busy);
            return [
                'house' => $house->get('code'),
                'date' => $date,
                'day_busy' => $dayBusy,
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
        // ночи, которым мешают подтверждённые сеансы бани (не успевают до заезда или после выезда с уборкой)
        $sessions = $b->availability()->addonBlockedNights($house, $from, $to);
        $out = [
            'house' => $house->get('code'),
            'from' => $from,
            'to' => $to,
            'today' => $b->today(),
            'busy' => array_values(array_unique(array_merge($cal['nights_busy'], $cal['nights_blocked'], $sessions))),
        ];
        sort($out['busy']);
        // для бани отдельно: день красный, если Дом закрыт администратором или у бани не осталось окна на минимум часов
        // (гости Дома с заезда и до выезда, сеансы, закрытия бани — с перерывами на уборку); иначе зелёный — снять по часам
        $addon = $house->get('addon_bookable_separately') ? $this->modx->getObject(Addon::class, ['house_id' => $house->get('id'), 'active' => 1]) : null;
        if ($addon) {
            $bath = $cal['nights_blocked'];
            foreach (Availability::nightsBetween($from, $to) as $d) {
                if (!in_array($d, $bath, true) && !$b->availability()->addonHasWindow($addon, $b->availability()->addonBusy($addon, $d))) {
                    $bath[] = $d;
                }
            }
        } else {
            $bath = array_merge($cal['nights_busy'], $cal['nights_blocked']);
        }
        $bath = array_values(array_unique($bath));
        sort($bath);
        $out['busy_bath'] = $bath;
        if ($b->isAdmin()) {
            $out['nights_busy'] = $cal['nights_busy'];
            $out['nights_blocked'] = $cal['nights_blocked'];
        }
        return $out;
    }
}
