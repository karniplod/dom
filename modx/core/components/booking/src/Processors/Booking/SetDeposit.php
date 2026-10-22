<?php
namespace Booking\Processors\Booking;

use Booking\Processors\Base;
use Booking\Service\BookingError;

/**
 * booking_set_deposit — залог: сумма (NULL или 5 000–20 000 ₽) и статус.
 * Переходы: not_set → pending → paid → returned (и назад на шаг — исправить ошибку).
 * Назначается только у подтверждённой брони; у отменённой и завершённой — только движение статуса.
 */
class SetDeposit extends Base
{
    protected const NEXT = [
        'not_set' => ['pending'],
        'pending' => ['paid', 'not_set'],
        'paid' => ['returned', 'pending'],
        'returned' => ['paid'],
    ];

    protected function handle(): array
    {
        $b = $this->booking;
        $bk = $b->bookings();
        $row = $bk->get($this->requireId());
        $status = $row->get('status');
        if ($status === 'new') {
            throw new BookingError('Залог назначают при подтверждении брони', 400);
        }
        $old = ['deposit_amount' => $row->get('deposit_amount'), 'deposit_status' => $row->get('deposit_status')];
        $amountGiven = array_key_exists('deposit_amount', $this->getProperties());
        $amount = $amountGiven ? $bk->depositAmount($this->getProperty('deposit_amount')) : $row->get('deposit_amount');
        $newStatus = $this->str('deposit_status', 20) ?: $row->get('deposit_status');

        if (!isset(self::NEXT[$newStatus])) {
            throw BookingError::field('deposit_status', 'Неверный статус залога');
        }
        if ($amountGiven && $amount !== $old['deposit_amount'] && $status !== 'confirmed') {
            throw new BookingError('Сумму залога меняют только у подтверждённой брони', 400);
        }
        if ($amountGiven && $amount !== null && $old['deposit_status'] === 'not_set' && $newStatus === 'not_set') {
            $newStatus = 'pending';   // назначили сумму — ждём залог
        }
        if ($amount === null) {
            if (in_array($old['deposit_status'], ['paid', 'returned'], true)) {
                throw new BookingError('Залог уже внесён — сумму не убрать', 400);
            }
            $newStatus = 'not_set';
        }
        if ($newStatus !== $old['deposit_status'] && !in_array($newStatus, self::NEXT[$old['deposit_status']], true)) {
            throw BookingError::field('deposit_status', 'Нельзя перейти от «' . \Booking\Service\Bookings::DEPOSIT_LABELS[$old['deposit_status']]
                . '» к «' . \Booking\Service\Bookings::DEPOSIT_LABELS[$newStatus] . '»');
        }
        if ($newStatus !== 'not_set' && $amount === null) {
            throw BookingError::field('deposit_amount', 'Сначала укажите сумму залога');
        }
        $row->set('deposit_amount', $amount);
        $row->set('deposit_status', $newStatus);
        $bk->touch($row);
        $row->save();
        $b->log()->write('booking', (int)$row->get('id'), 'deposit', $old, ['deposit_amount' => $amount, 'deposit_status' => $newStatus]);
        return $bk->toAdmin($row);
    }
}
