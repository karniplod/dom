<?php
namespace Booking\Processors;

use Booking\Model\Addon;
use Booking\Model\House as HouseRow;

/**
 * booking_houses — активные дома для формы: вместимость, время заезда и выезда, баня отдельно.
 */
class Houses extends Base
{
    public static string $role = 'guest';

    protected function handle(): array
    {
        $q = $this->modx->newQuery(HouseRow::class, ['active' => 1]);
        $q->sortby('sort', 'ASC');
        $out = [];
        foreach ($this->modx->getIterator(HouseRow::class, $q) as $h) {
            $addon = $h->get('addon_bookable_separately')
                ? $this->modx->getObject(Addon::class, ['house_id' => $h->get('id'), 'active' => 1])
                : null;
            $out[] = [
                'code' => $h->get('code'),
                'name' => $h->get('name'),
                'capacity_sleep' => (int)$h->get('capacity_sleep'),
                'capacity_party' => (int)$h->get('capacity_party'),
                'checkin' => substr($h->get('checkin_time'), 0, 5),
                'checkout' => substr($h->get('checkout_time'), 0, 5),
                'addon' => $addon && (int)$addon->get('price_per_hour') > 0 ? [
                    'name' => $addon->get('name'),
                    'price_per_hour' => (int)$addon->get('price_per_hour'),
                    'min_hours' => (int)$addon->get('min_hours'),
                    'buffer_minutes' => (int)$addon->get('buffer_minutes'),
                ] : null,
            ];
        }
        return ['houses' => $out];
    }
}
