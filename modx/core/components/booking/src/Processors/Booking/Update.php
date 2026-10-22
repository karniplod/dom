<?php
namespace Booking\Processors\Booking;

use Booking\Model\Booking as BookingRow;
use Booking\Processors\Base;
use Booking\Service\BookingError;

/**
 * booking_update — правка брони администратором: заметка, гости, питомцы, контакты, даты.
 * Для подтверждённой брони смена дат — с проверкой пересечений в транзакции; цена пересчитывается.
 */
class Update extends Base
{
    protected function handle(): array
    {
        $b = $this->booking;
        $bk = $b->bookings();
        $id = $this->requireId();
        $row = $bk->get($id);
        if (!in_array($row->get('status'), ['new', 'confirmed'], true)) {
            // у закрытых броней меняется только заметка
            $row->set('admin_note', $this->str('admin_note', 2000));
            $bk->touch($row);
            $row->save();
            $b->log()->write('booking', $id, 'update', null, ['admin_note' => true]);
            return $bk->toAdmin($row);
        }
        $house = $bk->houseOf($row);
        $p = $this->getProperties();
        $in = $bk->input([
            'house' => $house,
            'addon' => $row->get('addon_id') ? 1 : 0,
            'date_from' => $p['date_from'] ?? $row->get('date_from'),
            'date_to' => $p['date_to'] ?? $row->get('date_to'),
            'time_from' => $p['time_from'] ?? substr((string)$row->get('time_from'), 0, 5),
            'time_to' => $p['time_to'] ?? substr((string)$row->get('time_to'), 0, 5),
            'guests' => $p['guests_count'] ?? ($p['guests'] ?? $row->get('guests_count')),
            'pets' => $p['pets_count'] ?? ($p['pets'] ?? $row->get('pets_count')),
            'name' => $p['guest_name'] ?? $row->get('guest_name'),
            'phone' => $p['guest_phone'] ?? $row->get('guest_phone'),
            'comment' => $p['guest_comment'] ?? $row->get('guest_comment'),
        ], true, true);
        // скидка сохраняется, если она была при заявке
        $quote = $bk->quote($in, (int)$row->get('quote_discount') > 0);
        $old = $bk->toAdmin($row);
        unset($old['lines']);

        $row = $this->transaction(function () use ($b, $bk, $id, $in, $quote) {
            $b->availability()->lockHouse((int)$in['house']->get('id'));
            $row = $this->modx->getObject(BookingRow::class, $id, false);
            $row->fromArray([
                'date_from' => $in['date_from'], 'date_to' => $in['date_to'],
                'time_from' => $in['time_from'], 'time_to' => $in['time_to'],
                'guests_count' => $in['guests'], 'pets_count' => $in['pets'],
                'guest_name' => $in['name'], 'guest_phone' => $in['phone'], 'guest_comment' => $in['comment'],
                'quote_total' => $quote['total'], 'quote_discount' => $quote['discount'], 'quote_lines' => $quote['lines'],
                'admin_note' => $this->getProperty('admin_note') !== null ? $this->str('admin_note', 2000) : $row->get('admin_note'),
            ]);
            if ($row->get('status') === 'confirmed') {
                $b->availability()->release($row);
                $bk->occupy($row);
            }
            $bk->touch($row);
            if (!$row->save()) {
                throw new BookingError('Не удалось сохранить', 500);
            }
            return $row;
        });
        $new = $bk->toAdmin($row);
        unset($new['lines']);
        $b->log()->write('booking', $id, 'update', array_diff_assoc(array_map('strval', array_filter($old, 'is_scalar')), array_map('strval', array_filter($new, 'is_scalar'))),
            array_diff_assoc(array_map('strval', array_filter($new, 'is_scalar')), array_map('strval', array_filter($old, 'is_scalar'))));
        return $new;
    }
}
