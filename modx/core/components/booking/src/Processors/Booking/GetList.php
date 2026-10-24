<?php
namespace Booking\Processors\Booking;

use Booking\Model\Booking as BookingRow;
use Booking\Model\House;
use Booking\Processors\Base;

/**
 * booking_list — заявки и брони с фильтрами: status, house, from, to, q (имя, телефон, номер), page; all=1 — все строки (выгрузка в Excel).
 */
class GetList extends Base
{
    public const PER_PAGE = 50;
    public const EXPORT_MAX = 5000;

    protected function handle(): array
    {
        $modx = $this->modx;
        $q = $modx->newQuery(BookingRow::class);
        $status = $this->str('status', 20);
        if ($status === 'cancel_requested') {
            $q->where(['cancellation_requested' => 1, 'status:IN' => ['new', 'confirmed']]);
        } elseif ($status !== '') {
            $q->where(['status:IN' => array_intersect(explode(',', $status), ['new', 'confirmed', 'cancelled', 'completed'])]);
        }
        $house = $this->str('house', 20);
        if ($house !== '') {
            $h = $modx->getObject(House::class, ['code' => $house]);
            $q->where(['house_id' => $h ? $h->get('id') : 0]);
        }
        if ($this->str('from', 10) !== '') {
            $q->where(['date_to:>=' => $this->booking->date($this->str('from', 10), 'from')]);
        }
        if ($this->str('to', 10) !== '') {
            $q->where(['date_from:<=' => $this->booking->date($this->str('to', 10), 'to')]);
        }
        $search = $this->str('q', 100);
        if ($search !== '') {
            $digits = preg_replace('~\D+~', '', $search);
            $or = ['guest_name:LIKE' => '%' . $search . '%'];
            if ($digits !== '') {
                $or['OR:guest_phone:LIKE'] = '%' . substr($digits, -10) . '%';
                $or['OR:id:='] = (int)$digits;
            }
            $q->where([$or]);
        }
        $total = $modx->getCount(BookingRow::class, $q);
        $page = max(1, (int)$this->int('page', 1));
        $q->sortby($this->str('sort', 20) === 'date' ? 'date_from' : 'id', 'DESC');
        // all=1 — выгрузка в Excel: все строки по фильтру (не больше EXPORT_MAX), без постраничности
        $all = (int)$this->int('all', 0) === 1;
        $q->limit($all ? self::EXPORT_MAX : self::PER_PAGE, $all ? 0 : ($page - 1) * self::PER_PAGE);
        $bk = $this->booking->bookings();
        $items = [];
        foreach ($modx->getIterator(BookingRow::class, $q) as $row) {
            $a = $bk->toAdmin($row);
            unset($a['lines']);
            $items[] = $a;
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE];
    }
}
