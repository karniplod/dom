<?php
namespace Booking\Processors\Request;

use Booking\Processors\Base;

/**
 * booking_create_request — заявка гостя. Вызывается хуком SendIt booking.hook (ст. 8.1), не напрямую из api.php.
 * Заявка «новая» и даты не занимает; на занятые и закрытые даты не принимается.
 * Цена считается заново на сервере, статус и залог задаёт только сервер.
 */
class Create extends Base
{
    public static string $role = 'guest';

    protected function handle(): array
    {
        $b = $this->booking;
        $bk = $b->bookings();
        $in = $bk->input($this->getProperties(), true);
        $bk->assertFree($in);
        $quote = $bk->quote($in, false);   // без скидки
        $interface = $b->actor()['interface'];
        $source = in_array($interface, ['pwa', 'max'], true) ? $interface : 'site';
        [$row, $code] = $bk->create($in, $quote, 'new', $source);
        $b->log()->write('booking', (int)$row->get('id'), 'create', null, ['status' => 'new', 'total' => $quote['total'], 'source' => $source]);
        return $bk->toGuest($row, $code);
    }
}
