<?php
namespace Booking\Processors\Request;

use Booking\Processors\Base;
use Booking\Service\BookingError;
use Booking\Service\Notify;

/**
 * booking_request_cancel — гость просит отменить. Ставится отметка, статус и занятость не меняются;
 * отменяет только администратор. Администратору — письмо и сообщение в MAX.
 */
class Cancel extends Base
{
    public static string $role = 'guest';

    protected function handle(): array
    {
        [$row, $code] = Get::find($this);
        // по номеру и телефону — только посмотреть: телефон знают и чужие люди, отмену просят по личной ссылке (код) или сразу после формы
        if ($code === '') {
            throw new BookingError('Отменить можно по ссылке на заявку из письма или сообщения — или позвоните администратору', 403);
        }
        if (!in_array($row->get('status'), ['new', 'confirmed'], true)) {
            throw new BookingError('Заявка уже ' . ($row->get('status') === 'cancelled' ? 'отменена' : 'завершена'), 400);
        }
        if ($row->get('cancellation_requested')) {
            return $this->booking->bookings()->toGuest($row, $code);
        }
        $reason = $this->str('reason', 1000);
        $row->fromArray([
            'cancellation_requested' => 1,
            'cancellation_requested_by' => 'guest',
            'cancellation_reason' => $reason,
        ]);
        $this->booking->bookings()->touch($row);
        $row->set('updated_by', 'гость');
        $row->save();
        $this->booking->log()->write('booking', (int)$row->get('id'), 'request_cancel', null, ['reason' => $reason]);

        $ph = $this->booking->bookings()->placeholders($row);
        $this->booking->notify()->admin(
            'Гость просит отменить бронь №' . $ph['booking_id'],
            'booking.email.cancel',
            $ph,
            "Гость просит отменить бронь №{$ph['booking_id']}\n{$ph['house_name']}, {$ph['dates']}\n"
            . Notify::plain(html_entity_decode($ph['guest_name'])) . ", {$ph['guest_phone']}" . ($reason !== '' ? "\nПричина: " . Notify::plain($reason) : '')
        );
        return $this->booking->bookings()->toGuest($row, $code);
    }
}
