<?php
namespace Booking\Processors\Booking;

use Booking\Model\Addon;
use Booking\Model\BlockedSlot;
use Booking\Model\Booking as BookingRow;
use Booking\Model\House;
use Booking\Processors\Base;
use Booking\Service\Availability;
use Booking\Service\BookingError;
use Booking\Service\Bookings;

/**
 * booking_grid — сетка «дома и бани × даты» для календаря администратора (CMP и PWA).
 * Ячейка: подтверждённая бронь (id, гость, отметка отмены), новые заявки на эту ночь, закрытие.
 */
class Grid extends Base
{
    protected function handle(): array
    {
        $b = $this->booking;
        $from = $b->date($this->str('from', 10), 'from');
        $to = $b->date($this->str('to', 10), 'to');
        $dates = Availability::nightsBetween($from, $to);
        if (!$dates || count($dates) > Bookings::MAX_RANGE_DAYS) {
            throw new BookingError('Диапазон — от 1 до ' . Bookings::MAX_RANGE_DAYS . ' дней', 400);
        }
        $modx = $this->modx;
        $hq = $modx->newQuery(House::class);
        $hq->sortby('sort', 'ASC');
        $rows = [];
        foreach ($modx->getIterator(House::class, $hq) as $h) {
            $hid = (int)$h->get('id');
            $cells = [];
            // брони дома (не баня), пересекающие диапазон
            $q = $modx->newQuery(BookingRow::class);
            $q->where(['house_id' => $hid, 'addon_id:IS' => null, 'status:IN' => ['new', 'confirmed', 'completed'],
                'date_from:<' => $to, 'date_to:>' => $from]);
            $q->sortby('id', 'ASC');
            foreach ($modx->getIterator(BookingRow::class, $q) as $r) {
                foreach (Availability::nightsBetween($r->get('date_from'), $r->get('date_to')) as $n) {
                    if ($n < $from || $n >= $to) {
                        continue;
                    }
                    $item = ['id' => (int)$r->get('id'), 'guest' => $r->get('guest_name'), 'guests' => (int)$r->get('guests_count'),
                        'status' => $r->get('status'), 'cancel' => (bool)$r->get('cancellation_requested'),
                        'start' => $n === $r->get('date_from')];
                    if ($r->get('status') === 'new') {
                        $cells[$n]['new'][] = $item;
                    } else {
                        $cells[$n]['booking'] = $item;
                    }
                }
            }
            foreach ($b->availability()->blockedNights($hid, $from, $to) as $n => $reason) {
                $cells[$n]['blocked'] = $reason;
            }
            $bath = $h->get('kind') === 'bath';   // отдельная баня или сауна — только строка часов
            if (!$bath) {
                $rows[] = ['type' => 'house', 'id' => $hid, 'code' => $h->get('code'), 'name' => $h->get('name'),
                    'active' => (bool)$h->get('active'), 'cells' => (object)$cells];
            }

            // строка бани — если у дома включена отдельная бронь
            $addon = $modx->getObject(Addon::class, ['house_id' => $hid, 'active' => 1]);
            if ($addon && $h->get('addon_bookable_separately')) {
                $ac = [];
                $q = $modx->newQuery(BookingRow::class);
                $q->where(['addon_id' => $addon->get('id'), 'status:IN' => ['new', 'confirmed', 'completed'],
                    'date_from:>=' => $from, 'date_from:<' => $to]);
                $q->sortby('time_from', 'ASC');
                foreach ($modx->getIterator(BookingRow::class, $q) as $r) {
                    $ac[$r->get('date_from')]['sessions'][] = ['id' => (int)$r->get('id'), 'guest' => $r->get('guest_name'),
                        'status' => $r->get('status'), 'cancel' => (bool)$r->get('cancellation_requested'),
                        'from' => substr($r->get('time_from'), 0, 5), 'to' => substr($r->get('time_to'), 0, 5)];
                }
                $q = $modx->newQuery(BlockedSlot::class, ['addon_id' => $addon->get('id'), 'date_from:<=' => end($dates), 'date_to:>=' => $from]);
                foreach ($modx->getIterator(BlockedSlot::class, $q) as $s) {
                    foreach ($dates as $d) {
                        if ($d >= $s->get('date_from') && $d <= $s->get('date_to')) {
                            $ac[$d]['blocked'] = $s->get('reason');
                        }
                    }
                }
                $rows[] = ['type' => 'addon', 'id' => (int)$addon->get('id'), 'house_id' => $hid, 'code' => $h->get('code'),
                    'name' => $bath ? $h->get('name') : $addon->get('name') . ' — ' . $h->get('name'), 'cells' => (object)$ac];
            }
        }
        return ['from' => $from, 'to' => $to, 'dates' => $dates, 'today' => $b->today(), 'rows' => $rows];
    }
}
