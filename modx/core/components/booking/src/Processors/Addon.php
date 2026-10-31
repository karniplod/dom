<?php
namespace Booking\Processors;

use Booking\Model\Addon as AddonRow;
use Booking\Model\House as HouseRow;
use Booking\Service\BookingError;

/**
 * booking_manage_addon — баня дома: op=list | save.
 * Отдельная бронь бани включается у дома (addon_bookable_separately); при включении нужна цена за час.
 * min_hours ≥ 1; buffer_minutes — перерыв между сеансами (30 по умолчанию).
 */
class Addon extends Base
{
    protected function handle(): array
    {
        if (($this->str('op', 20) ?: 'list') === 'list') {
            $out = [];
            foreach ($this->modx->getIterator(AddonRow::class) as $a) {
                $h = $this->modx->getObject(HouseRow::class, $a->get('house_id'));
                $out[] = $a->toArray() + ['house_code' => $h ? $h->get('code') : '', 'bookable_separately' => $h ? (bool)$h->get('addon_bookable_separately') : false];
            }
            return ['items' => $out];
        }
        $house = $this->modx->getObject(HouseRow::class, ['code' => $this->str('house', 20)]);
        if (!$house) {
            throw BookingError::field('house', 'Дом не найден');
        }
        $a = $this->modx->getObject(AddonRow::class, ['house_id' => $house->get('id')])
            ?: $this->modx->newObject(AddonRow::class, ['house_id' => $house->get('id')]);
        $old = $a->toArray() + ['bookable_separately' => (bool)$house->get('addon_bookable_separately')];

        $separately = $this->bool('bookable_separately');
        $price = $this->int('price_per_hour');
        $min = (int)$this->int('min_hours', (int)($a->get('min_hours') ?: 2));
        $buffer = (int)$this->int('buffer_minutes', (int)($a->get('buffer_minutes') ?: 30));
        if ($min < 1 || $min > 24) {
            throw BookingError::field('min_hours', 'Минимум часов — от 1 до 24');
        }
        if ($buffer < 0 || $buffer > 240) {
            throw BookingError::field('buffer_minutes', 'Перерыв — от 0 до 240 минут');
        }
        if ($price !== null && $price < 1) {
            throw BookingError::field('price_per_hour', 'Цена за час — больше нуля');
        }
        if ($house->get('kind') === 'bath') {
            $separately = true;   // отдельную баню или сауну иначе и не забронировать
        }
        if ($separately && !$price) {   // цена часов вне «часов с другой ценой» (вкладка «Цены»)
            throw BookingError::field('price_per_hour', 'Чтобы бронировать баню отдельно, укажите цену за час');
        }
        $a->fromArray([
            'name' => $this->str('name', 100) ?: 'Баня',
            'price_per_hour' => $price,
            'min_hours' => $min,
            'buffer_minutes' => $buffer,
            'active' => $this->getProperty('active') === null ? 1 : $this->bool('active'),
        ]);
        $a->save();
        $house->set('addon_bookable_separately', $separately);
        $house->save();
        $new = $a->toArray() + ['bookable_separately' => $separately];
        $this->booking->log()->write('house', (int)$house->get('id'), 'addon', $old, $new);
        return $new + ['house_code' => $house->get('code')];
    }
}
