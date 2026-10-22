<?php
namespace Booking\Processors;

use Booking\Model\Addon;
use Booking\Model\House as HouseRow;
use Booking\Service\BookingError;

/**
 * booking_manage_house — дома: op=list | save | toggle.
 * Правила: capacity_party ≥ capacity_sleep > 0; время заезда ≥ времени выезда
 * (иначе выезд и заезд в один день пересекаются).
 */
class House extends Base
{
    protected function handle(): array
    {
        switch ($this->str('op', 20) ?: 'list') {
            case 'save':
                return $this->save();
            case 'toggle':
                $h = $this->find();
                $h->set('active', !$h->get('active'));
                $h->save();
                $this->booking->log()->write('house', (int)$h->get('id'), 'toggle', null, ['active' => (bool)$h->get('active')]);
                $this->modx->cacheManager->refresh(['resource' => []]);
                return $this->row($h);
            default:
                return ['items' => $this->all()];
        }
    }

    protected function find(): HouseRow
    {
        $h = $this->modx->getObject(HouseRow::class, (int)$this->getProperty('id'));
        if (!$h) {
            throw new BookingError('Дом не найден', 404);
        }
        return $h;
    }

    protected function all(): array
    {
        $q = $this->modx->newQuery(HouseRow::class);
        $q->sortby('sort', 'ASC');
        $out = [];
        foreach ($this->modx->getIterator(HouseRow::class, $q) as $h) {
            $out[] = $this->row($h);
        }
        return $out;
    }

    protected function row(HouseRow $h): array
    {
        $a = $h->toArray();
        $a['checkin_time'] = substr($a['checkin_time'], 0, 5);
        $a['checkout_time'] = substr($a['checkout_time'], 0, 5);
        $addon = $this->modx->getObject(Addon::class, ['house_id' => $h->get('id')]);
        $a['addon'] = $addon ? $addon->toArray() : null;
        return $a;
    }

    protected function save(): array
    {
        $b = $this->booking;
        $id = (int)$this->getProperty('id');
        $h = $id ? $this->find() : $this->modx->newObject(HouseRow::class);
        $old = $id ? $h->toArray() : null;

        $code = $id ? $h->get('code') : strtolower($this->str('code', 20));
        if (!preg_match('~^[a-z][a-z0-9_-]{0,19}$~', $code)) {
            throw BookingError::field('code', 'Код дома — латиница, до 20 знаков');
        }
        $name = $this->str('name', 100);
        if ($name === '') {
            throw BookingError::field('name', 'Укажите название');
        }
        $slug = $this->str('slug', 100) ?: $code;
        $sleep = (int)$this->int('capacity_sleep', 0);
        $party = (int)$this->int('capacity_party', 0);
        if ($sleep < 1) {
            throw BookingError::field('capacity_sleep', 'Сколько гостей ночует — больше нуля');
        }
        if ($party < $sleep) {
            throw BookingError::field('capacity_party', 'Сколько гостей отдыхает — не меньше, чем ночует');
        }
        $in = $b->time($this->str('checkin_time', 8), 'checkin_time');
        $out = $b->time($this->str('checkout_time', 8), 'checkout_time');
        if ($in < $out) {
            throw BookingError::field('checkin_time', 'Заезд не раньше выезда — иначе соседние брони пересекутся');
        }
        foreach (['code' => $code, 'slug' => $slug] as $f => $v) {
            $dup = $this->modx->getObject(HouseRow::class, [$f => $v, 'id:!=' => $id]);
            if ($dup) {
                throw BookingError::field($f, 'Уже есть дом с таким ' . ($f === 'code' ? 'кодом' : 'адресом страницы'));
            }
        }
        $h->fromArray([
            'code' => $code, 'name' => $name, 'slug' => $slug,
            'description' => $this->str('description', 5000),
            'address' => $this->str('address', 255),
            'resource_id' => $this->int('resource_id') ?: null,
            'capacity_sleep' => $sleep, 'capacity_party' => $party,
            'checkin_time' => $in, 'checkout_time' => $out,
            'sort' => (int)$this->int('sort', (int)$h->get('sort')),
            'active' => $this->getProperty('active') === null ? ($id ? $h->get('active') : 1) : $this->bool('active'),
        ]);
        if (!$h->save()) {
            throw new BookingError('Не удалось сохранить дом', 500);
        }
        if (!$id) {
            // у нового дома — баня (выключена отдельно) и пустые правила цен
            $addon = $this->modx->newObject(Addon::class, ['house_id' => $h->get('id'), 'name' => 'Баня', 'min_hours' => 2, 'buffer_minutes' => 30, 'active' => 1]);
            $addon->save();
        }
        $b->log()->write('house', (int)$h->get('id'), $id ? 'update' : 'create', $old, $h->toArray());
        $this->modx->cacheManager->refresh(['resource' => []]);
        return $this->row($h);
    }
}
