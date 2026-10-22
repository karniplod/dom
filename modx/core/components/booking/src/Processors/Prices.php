<?php
namespace Booking\Processors;

use Booking\Model\House as HouseRow;
use Booking\Model\PriceRule;
use Booking\Service\BookingError;
use Booking\Service\Pricing;

/**
 * booking_manage_prices — цены суток дома по типам дней и доплаты.
 * op=list — все дома; op=save — house, rules{weekday,friday,saturday,sunday → цена > 0},
 * extra_guest_price, extra_guest_limit, pet_price (одинаковые во всех строках дома).
 * После сохранения сбрасывается кеш страниц — booking.prices покажет новые цены (ст. 6.3).
 */
class Prices extends Base
{
    protected function handle(): array
    {
        if (($this->str('op', 20) ?: 'list') === 'list') {
            $q = $this->modx->newQuery(HouseRow::class);
            $q->sortby('sort', 'ASC');
            $out = [];
            foreach ($this->modx->getIterator(HouseRow::class, $q) as $h) {
                $out[] = $this->forHouse($h);
            }
            return ['items' => $out, 'labels' => Pricing::DAY_LABELS];
        }
        $h = $this->modx->getObject(HouseRow::class, ['code' => $this->str('house', 20)]);
        if (!$h) {
            throw BookingError::field('house', 'Дом не найден');
        }
        $rules = $this->arr('rules');
        $prices = [];
        foreach (Pricing::DAY_TYPES as $t) {
            $v = $rules[$t] ?? $this->getProperty('price_' . $t);
            if (!is_scalar($v) || !preg_match('~^\d+$~', trim((string)$v)) || (int)$v <= 0) {
                throw BookingError::field('price_' . $t, 'Цена «' . Pricing::DAY_LABELS[$t] . '» — целое число больше нуля');
            }
            $prices[$t] = (int)$v;
        }
        $extra = (int)$this->int('extra_guest_price', 500);
        $limit = (int)$this->int('extra_guest_limit', 10);
        $pet = (int)$this->int('pet_price', 1000);
        if ($extra < 0 || $pet < 0 || $limit < 1) {
            throw new BookingError('Доплаты не могут быть отрицательными, норма гостей — от 1', 400);
        }
        if ($limit > (int)$h->get('capacity_party')) {
            throw BookingError::field('extra_guest_limit', 'Норма гостей больше, чем вмещает Дом');
        }
        $old = $this->forHouse($h);
        foreach ($prices as $t => $p) {
            $r = $this->modx->getObject(PriceRule::class, ['house_id' => $h->get('id'), 'day_type' => $t])
                ?: $this->modx->newObject(PriceRule::class, ['house_id' => $h->get('id'), 'day_type' => $t]);
            $r->fromArray(['price' => $p, 'extra_guest_price' => $extra, 'extra_guest_limit' => $limit, 'pet_price' => $pet]);
            $r->save();
        }
        $new = $this->forHouse($h);
        $this->booking->log()->write('price', (int)$h->get('id'), 'update', $old, $new);
        $this->modx->cacheManager->refresh(['resource' => []]);
        return $new;
    }

    protected function forHouse(HouseRow $h): array
    {
        $rules = $this->booking->pricing()->rules($h->get('id'));
        $out = ['house' => $h->get('code'), 'name' => $h->get('name'), 'rules' => []];
        foreach (Pricing::DAY_TYPES as $t) {
            $out['rules'][$t] = isset($rules[$t]) ? (int)$rules[$t]->get('price') : null;
        }
        $base = $rules['weekday'] ?? reset($rules) ?: null;
        $out['extra_guest_price'] = $base ? (int)$base->get('extra_guest_price') : 500;
        $out['extra_guest_limit'] = $base ? (int)$base->get('extra_guest_limit') : 10;
        $out['pet_price'] = $base ? (int)$base->get('pet_price') : 1000;
        return $out;
    }
}
