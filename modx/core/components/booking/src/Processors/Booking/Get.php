<?php
namespace Booking\Processors\Booking;

use Booking\Model\Booking as BookingRow;
use Booking\Processors\Base;

/**
 * booking_get — полная карточка брони с журналом и пересекающимися заявками.
 */
class Get extends Base
{
    protected function handle(): array
    {
        $bk = $this->booking->bookings();
        $row = $bk->get($this->requireId());
        $out = $bk->toAdmin($row);
        $out['log'] = $this->booking->log()->forEntity('booking', (int)$row->get('id'));
        // другие активные заявки на те же даты — чтобы администратор видел, кого придётся отклонить
        $q = $this->modx->newQuery(BookingRow::class, [
            'house_id' => $row->get('house_id'), 'id:!=' => $row->get('id'), 'status:IN' => ['new', 'confirmed'],
        ]);
        if ($row->get('addon_id')) {
            // сеансы бани в тот же день
            $q->where(['addon_id' => $row->get('addon_id'), 'date_from' => $row->get('date_from')]);
        } else {
            $q->where(['addon_id:IS' => null, 'date_from:<' => $row->get('date_to'), 'date_to:>' => $row->get('date_from')]);
        }
        $out['overlaps'] = [];
        foreach ($this->modx->getIterator(BookingRow::class, $q) as $o) {
            $out['overlaps'][] = ['id' => (int)$o->get('id'), 'status' => $o->get('status'), 'guest' => $o->get('guest_name'), 'dates' => $bk->datesText($o)];
        }
        return $out;
    }
}
