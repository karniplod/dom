<?php
namespace Booking\Processors;

use Booking\Model\Addon as AddonRow;
use Booking\Model\BlockedSlot;
use Booking\Model\Booking as BookingRow;
use Booking\Model\House as HouseRow;
use Booking\Service\Availability;
use Booking\Service\BookingError;

/**
 * booking_manage_block — закрытые даты: op=list | save | delete.
 * Дом: закрыты ночи date_from … date_to−1 (как у брони). Баня (addon=1): дни date_from … date_to, время — по желанию.
 * Причина обязательна. Пересечение с подтверждённой бронью — 409, молча не закрываем.
 */
class Block extends Base
{
    protected function handle(): array
    {
        switch ($this->str('op', 20) ?: 'list') {
            case 'save':
                return $this->save();
            case 'delete':
                $s = $this->modx->getObject(BlockedSlot::class, (int)$this->getProperty('id'));
                if (!$s) {
                    throw new BookingError('Закрытие не найдено', 404);
                }
                $old = $s->toArray();
                $s->remove();
                $this->booking->log()->write('block', (int)$old['id'], 'delete', $old, null);
                return ['id' => (int)$old['id']];
            default:
                $q = $this->modx->newQuery(BlockedSlot::class);
                $q->where(['date_to:>=' => date('Y-m-d', strtotime('-30 days'))]);
                $q->sortby('date_from', 'ASC');
                $out = [];
                foreach ($this->modx->getIterator(BlockedSlot::class, $q) as $s) {
                    $h = $this->modx->getObject(HouseRow::class, $s->get('house_id'));
                    $out[] = $s->toArray() + ['house' => $h ? $h->get('code') : '', 'house_name' => $h ? $h->get('name') : ''];
                }
                return ['items' => $out];
        }
    }

    protected function save(): array
    {
        $b = $this->booking;
        $house = $this->modx->getObject(HouseRow::class, ['code' => $this->str('house', 20)]);
        if (!$house) {
            throw BookingError::field('house', 'Выберите дом');
        }
        $from = $b->date($this->str('date_from', 10), 'date_from');
        $to = $b->date($this->str('date_to', 10), 'date_to');
        $reason = $this->str('reason', 255);
        if ($reason === '') {
            throw BookingError::field('reason', 'Укажите причину');
        }
        $addon = null;
        $tf = $tt = null;
        if ($this->bool('addon')) {
            $addon = $this->modx->getObject(AddonRow::class, ['house_id' => $house->get('id')]);
            if (!$addon) {
                throw BookingError::field('addon', 'У дома нет бани');
            }
            if ($to < $from) {
                throw BookingError::field('date_to', 'Конец раньше начала');
            }
            if ($this->str('time_from', 5) !== '') {
                $tf = $b->time($this->str('time_from', 5), 'time_from');
                $tt = $b->time($this->str('time_to', 5), 'time_to');
                if ($tt <= $tf) {
                    throw BookingError::field('time_to', 'Конец раньше начала');
                }
            }
        } elseif ($to <= $from) {
            throw BookingError::field('date_to', 'Последняя закрытая ночь — накануне этой даты; дата должна быть позже начала');
        }

        $slot = $this->transaction(function () use ($b, $house, $addon, $from, $to, $tf, $tt, $reason) {
            $b->availability()->lockHouse((int)$house->get('id'));
            if ($addon) {
                $q = $this->modx->newQuery(BookingRow::class, ['addon_id' => $addon->get('id'), 'status' => 'confirmed',
                    'date_from:>=' => $from, 'date_from:<=' => $to]);
                foreach ($this->modx->getIterator(BookingRow::class, $q) as $r) {
                    if ($tf === null || (Availability::toMin($r->get('time_from')) < Availability::toMin($tt)
                            && Availability::toMin($r->get('time_to')) > Availability::toMin($tf))) {
                        throw new BookingError('Нельзя закрыть: подтверждён сеанс бани (бронь №' . $r->get('id') . ')', 409,
                            ['conflict' => ['booking_id' => (int)$r->get('id')]]);
                    }
                }
            } else {
                $busy = $b->availability()->bookedNights((int)$house->get('id'), $from, $to);
                if ($busy) {
                    throw new BookingError('Нельзя закрыть: эти даты в подтверждённой брони №' . reset($busy) . ' — сначала отмените её', 409,
                        ['conflict' => ['booking_id' => reset($busy), 'nights' => array_keys($busy)]]);
                }
            }
            $s = $this->modx->newObject(BlockedSlot::class);
            $s->fromArray(['house_id' => $house->get('id'), 'addon_id' => $addon ? $addon->get('id') : null,
                'date_from' => $from, 'date_to' => $to, 'time_from' => $tf, 'time_to' => $tt, 'reason' => $reason,
                'created_by' => $b->actor()['name'], 'created_at' => $b->now()]);
            $s->save();
            return $s;
        });
        $this->booking->log()->write('block', (int)$slot->get('id'), 'create', null, $slot->toArray());
        return $slot->toArray() + ['house' => $house->get('code'), 'house_name' => $house->get('name')];
    }
}
