<?php
namespace Booking\Processors\Booking;

use Booking\Processors\Base;
use Booking\Service\BookingError;

/**
 * booking_complete — гости уехали: confirmed → completed. Ночи остаются занятыми (история).
 */
class Complete extends Base
{
    protected function handle(): array
    {
        $bk = $this->booking->bookings();
        $row = $bk->get($this->requireId());
        if ($row->get('status') !== 'confirmed') {
            throw new BookingError('Завершить можно только подтверждённую бронь', 400);
        }
        $row->set('status', 'completed');
        $bk->touch($row);
        $row->save();
        $this->booking->log()->write('booking', (int)$row->get('id'), 'complete', ['status' => 'confirmed'], ['status' => 'completed']);
        return $bk->toAdmin($row);
    }
}
