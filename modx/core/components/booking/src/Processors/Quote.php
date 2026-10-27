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
        $q = $bk->quote($in, false);   // без скидки
        unset($q['nights']);
        return $q;
    }
}
