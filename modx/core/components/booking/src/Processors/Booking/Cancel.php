<?php
namespace Booking\Processors\Booking;

use Booking\Processors\Base;
use Booking\Service\BookingError;

/**
 * booking_cancel — отменить заявку или бронь (new/confirmed → cancelled). Причина обязательна.
 * Ночи освобождаются, запись не удаляется, статус залога не меняется.
 */
class Cancel extends Base
{
    protected function handle(): array
    {
        $b = $this->booking;
        $bk = $b->bookings();
        $id = $this->requireId();
        $reason = $this->str('reason', 1000);
        if ($reason === '') {
            throw BookingError::field('reason', 'Укажите причину отмены');
        }
        $row = $this->transaction(function () use ($b, $bk, $id, $reason) {
            $row = $bk->get($id);
            $b->availability()->lockHouse((int)$row->get('house_id'));
            $row = $this->modx->getObject(\Booking\Model\Booking::class, $id, false);
            $old = $row->get('status');
            if (!in_array($old, ['new', 'confirmed'], true)) {
                throw new BookingError('Отменить можно только новую или подтверждённую', 400);
            }
            $b->availability()->release($row);
            $row->set('status', 'cancelled');
            if (!$row->get('cancellation_requested_by')) {
                $row->set('cancellation_requested_by', 'admin');
            }
            $prev = (string)$row->get('cancellation_reason');
            $row->set('cancellation_reason', $prev !== '' && $prev !== $reason ? $prev . "\nАдминистратор: " . $reason : $reason);
            $bk->touch($row);
            $row->save();
            $b->log()->write('booking', $id, 'cancel', ['status' => $old], ['status' => 'cancelled', 'reason' => $reason]);
            return $row;
        });
        return $bk->toAdmin($row);
    }
}
