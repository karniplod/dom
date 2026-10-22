<?php
namespace Booking\Processors;

/**
 * booking_quote — расчёт стоимости для формы. Сервер считает сам; итог в заявке пересчитывается заново.
 */
class Quote extends Base
{
    public static string $role = 'guest';

    protected function handle(): array
    {
        $bk = $this->booking->bookings();
        $in = $bk->input($this->getProperties(), false);
        $q = $bk->quote($in, false);   // скидка — только через ВКонтакте (решение владельца 22.10.2026)
        unset($q['nights']);
        return $q;
    }
}
