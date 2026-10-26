<?php
namespace Booking\Processors\Booking;

use Booking\Processors\Base;
use Booking\Service\Access;
use Booking\Service\BookingError;

/**
 * booking_guest_link — новая личная ссылка гостя на его заявку (заявку заводил администратор или гость потерял письмо).
 * В базе хранится только хеш кода, поэтому прежнюю ссылку показать нельзя: выдаётся новая, старая перестаёт работать.
 */
class GuestLink extends Base
{
    protected function handle(): array
    {
        $b = $this->booking;
        $bk = $b->bookings();
        $row = $bk->get($this->requireId());
        if (!in_array($row->get('status'), ['new', 'confirmed'], true)) {
            throw new BookingError('Ссылку выдают только для новой или подтверждённой брони', 400);
        }
        $code = Access::guestCode();
        $row->set('guest_token_hash', Access::hash($code));
        $bk->touch($row);
        if (!$row->save()) {
            throw new BookingError('Не удалось сохранить', 500);
        }
        $b->log()->write('booking', (int)$row->get('id'), 'guest_link', null, null);
        return $bk->toAdmin($row) + ['link' => $b->requestLink((int)$row->get('id'), $code)];
    }
}
