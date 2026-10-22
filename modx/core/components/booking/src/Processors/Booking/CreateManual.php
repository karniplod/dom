<?php
namespace Booking\Processors\Booking;

use Booking\Processors\Base;
use Booking\Service\BookingError;

/**
 * booking_create_manual — бронь по телефону: администратор заводит её за гостя, сразу подтверждённой
 * (решение владельца 22.10.2026). Та же проверка пересечений в транзакции; скидка ВКонтакте — только по галочке.
 */
class CreateManual extends Base
{
    protected function handle(): array
    {
        $b = $this->booking;
        $bk = $b->bookings();
        $props = $this->getProperties();
        $props['name'] = $props['guest_name'] ?? ($props['name'] ?? '');
        $props['phone'] = $props['guest_phone'] ?? ($props['phone'] ?? '');
        $props['comment'] = $props['guest_comment'] ?? ($props['comment'] ?? '');
        $in = $bk->input($props, true, true);
        $deposit = $bk->depositAmount($this->getProperty('deposit_amount'));
        $quote = $bk->quote($in, $this->bool('apply_discount'));
        $note = $this->str('admin_note', 2000);

        $row = $this->transaction(function () use ($b, $bk, $in, $quote, $deposit, $note) {
            $b->availability()->lockHouse((int)$in['house']->get('id'));
            $bk->assertFree($in);
            [$row] = $bk->create($in, $quote, 'confirmed', 'admin', [
                'deposit_amount' => $deposit,
                'deposit_status' => $deposit !== null ? 'pending' : 'not_set',
                'admin_note' => $note,
            ]);
            $b->availability()->occupy($row);
            $b->log()->write('booking', (int)$row->get('id'), 'create_manual', null,
                ['status' => 'confirmed', 'total' => $quote['total'], 'deposit_amount' => $deposit]);
            return $row;
        });
        if (!$row) {
            throw new BookingError('Не удалось создать бронь', 500);
        }
        return $bk->toAdmin($row);
    }
}
