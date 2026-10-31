<?php
namespace Booking\Processors\Request;

use Booking\Model\Booking as BookingRow;
use Booking\Processors\Base;
use Booking\Service\Access;
use Booking\Service\BookingError;

/**
 * booking_get_request — гость смотрит свою заявку: по номеру и коду из ссылки, по номеру и телефону
 * или сразу после отправки формы (mine=1, сессия booking.last).
 */
class Get extends Base
{
    public static string $role = 'guest';

    protected function handle(): array
    {
        // после отправки формы: заявки в сессии может не быть (ошибка формы) — это не ошибка запроса
        if ($this->getProperty('mine') && empty($_SESSION['booking.last']['id'])) {
            return ['found' => false];
        }
        [$row, $code] = self::find($this);
        $out = $this->booking->bookings()->toGuest($row, $code) + ['found' => true];
        if ($pp = $this->booking->bookings()->prepay($row)) {
            $out['prepay'] = $pp;   // окно «сначала позвоните, потом предоплата» — только гостю
        }
        if ($this->getProperty('mine')) {
            $out['age'] = time() - (int)($_SESSION['booking.last']['ts'] ?? 0);   // секунд с отправки формы
        }
        return $out;
    }

    /** Найти заявку гостя или бросить 403. Возвращает [BookingRow, код для ссылки или ''] */
    public static function find(Base $p): array
    {
        $modx = $p->modx;
        $code = '';
        if ($p->getProperty('mine')) {
            $last = $_SESSION['booking.last'] ?? null;
            if (!is_array($last) || empty($last['id'])) {
                throw new BookingError('Заявка не найдена', 403);
            }
            $id = (int)$last['id'];
            $code = (string)$last['code'];
            $row = $modx->getObject(BookingRow::class, $id);
            if (!$row || !hash_equals($row->get('guest_token_hash'), Access::hash($code))) {
                throw new BookingError('Заявка не найдена', 403);
            }
            return [$row, $code];
        }
        $id = (int)$p->getProperty('id');
        $row = $id ? $modx->getObject(BookingRow::class, $id) : null;
        $token = trim((string)$p->getProperty('t', $p->getProperty('token', '')));
        $phone = trim((string)$p->getProperty('phone', ''));
        if ($row && $token !== '' && preg_match('~^[a-f0-9]{32}$~', $token)
            && hash_equals($row->get('guest_token_hash'), Access::hash($token))) {
            return [$row, $token];
        }
        if ($row && $phone !== '') {
            try {
                $norm = $p->booking()->phone($phone);
            } catch (BookingError $e) {
                $norm = '';
            }
            if ($norm !== '' && hash_equals($row->get('guest_phone'), $norm)) {
                return [$row, ''];
            }
        }
        // одинаковый ответ для «нет такой» и «чужая» — номера заявок не перебрать
        throw new BookingError('Заявка не найдена: проверьте номер и телефон', 403);
    }
}
