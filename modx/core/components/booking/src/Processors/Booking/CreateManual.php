<?php
namespace Booking\Processors\Booking;

use Booking\Processors\Base;
use Booking\Service\BookingError;

/**
 * booking_create_manual — бронь по телефону: администратор заводит её за гостя (решение владельца 22.10.2026).
 * status=confirmed (по умолчанию) — сразу подтверждённая, даты занимаются; status=new — заявка, даты не заняты,
 * подтвердить после предоплаты обычной кнопкой. Та же проверка пересечений; скидка ВКонтакте — только по галочке.
 * В ответе — личная ссылка гостя (link): её отправляют гостю, по ней он видит заявку и может попросить отмену.
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
        $status = $this->str('status', 10) === 'new' ? 'new' : 'confirmed';
        if ($status === 'new') {
            $deposit = null;   // залог назначают при подтверждении
        }

        $res = $this->transaction(function () use ($b, $bk, $in, $quote, $deposit, $note, $status) {
            $b->availability()->lockHouse((int)$in['house']->get('id'));
            $bk->assertFree($in);
            [$row, $code] = $bk->create($in, $quote, $status, 'admin', [
                'deposit_amount' => $deposit,
                'deposit_status' => $deposit !== null ? 'pending' : 'not_set',
                'admin_note' => $note,
            ]);
            if ($status === 'confirmed') {
                $b->availability()->occupy($row);
            }
            $b->log()->write('booking', (int)$row->get('id'), 'create_manual', null,
                ['status' => $status, 'total' => $quote['total'], 'deposit_amount' => $deposit]);
            return [$row, $code];
        });
        if (!$res) {
            throw new BookingError('Не удалось создать бронь', 500);
        }
        [$row, $code] = $res;
        return $bk->toAdmin($row) + ['link' => $b->requestLink((int)$row->get('id'), $code)];
    }
}
