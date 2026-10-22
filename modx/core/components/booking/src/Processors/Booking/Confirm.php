<?php
namespace Booking\Processors\Booking;

use Booking\Model\Booking as BookingRow;
use Booking\Processors\Base;
use Booking\Service\BookingError;

/**
 * booking_confirm — подтвердить заявку (только new → confirmed).
 * В транзакции: блокировка строки дома, проверка пересечений, запись занятых ночей (ст. 5.1).
 * Пересечение — 409 с номером брони. Необязательный залог 5 000–20 000 ₽ (ст. 4).
 */
class Confirm extends Base
{
    protected function handle(): array
    {
        $b = $this->booking;
        $bk = $b->bookings();
        $id = $this->requireId();
        $deposit = $bk->depositAmount($this->getProperty('deposit_amount'));

        $row = $this->transaction(function () use ($b, $bk, $id, $deposit) {
            $row = $bk->get($id);
            $b->availability()->lockHouse((int)$row->get('house_id'));
            // перечитываем после блокировки: параллельный запрос мог уже подтвердить или отменить
            $row = $this->modx->getObject(BookingRow::class, $id, false);
            if ($row->get('status') !== 'new') {
                throw new BookingError('Заявка №' . $id . ' уже ' . mb_strtolower(\Booking\Service\Bookings::STATUS_LABELS[$row->get('status')] ?? $row->get('status')), 400);
            }
            $bk->occupy($row);
            $row->set('status', 'confirmed');
            if ($deposit !== null) {
                $row->set('deposit_amount', $deposit);
                $row->set('deposit_status', 'pending');
            }
            $bk->touch($row);
            if (!$row->save()) {
                throw new BookingError('Не удалось сохранить', 500);
            }
            $b->log()->write('booking', $id, 'confirm', ['status' => 'new'], ['status' => 'confirmed', 'deposit_amount' => $deposit]);
            return $row;
        });
        return $bk->toAdmin($row);
    }
}
