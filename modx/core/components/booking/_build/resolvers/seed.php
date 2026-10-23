<?php
/**
 * Резолвер: стартовые дома, цены и бани — только при первой установке (если домов ещё нет).
 * Цены — как на сайте (docs/03); баня у каждого дома есть, отдельно не бронируется (цена не задана).
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */
use xPDO\Transport\xPDOTransport;

$modx = $transport->xpdo;
if (!in_array($options[xPDOTransport::PACKAGE_ACTION], [xPDOTransport::ACTION_INSTALL, xPDOTransport::ACTION_UPGRADE], true)) {
    return true;
}
if ($modx->getCount('Booking\\Model\\House') > 0) {
    return true;
}
$houses = [
    ['code' => 'en', 'name' => 'Английский Дом', 'slug' => 'angliyskij-dom', 'address' => 'ул. Космонавта Беляева, 111',
        'capacity_sleep' => 12, 'capacity_party' => 20, 'checkin_time' => '15:00:00', 'checkout_time' => '12:00:00', 'sort' => 1,
        'prices' => [18000, 22000, 27000, 19000], 'limit' => 10],
    ['code' => 'ru', 'name' => 'Русский Дом', 'slug' => 'russkij-dom', 'address' => 'ул. Космонавта Беляева, 111/1',
        'capacity_sleep' => 12, 'capacity_party' => 20, 'checkin_time' => '17:00:00', 'checkout_time' => '14:00:00', 'sort' => 2,
        'prices' => [18000, 22000, 27000, 19000], 'limit' => 10],
    ['code' => 'sv', 'name' => 'Дом на Свиязева', 'slug' => 'dom-na-sviyazeva', 'address' => 'ул. Архитектора Свиязева, 59',
        'capacity_sleep' => 8, 'capacity_party' => 15, 'checkin_time' => '14:00:00', 'checkout_time' => '11:00:00', 'sort' => 3,
        'prices' => [15000, 20000, 25000, 17000], 'limit' => 8],
];
$types = ['weekday', 'friday', 'saturday', 'sunday'];
foreach ($houses as $h) {
    $res = $modx->getObject(\MODX\Revolution\modResource::class, ['alias' => $h['slug']]);
    $house = $modx->newObject('Booking\\Model\\House');
    $house->fromArray([
        'code' => $h['code'], 'name' => $h['name'], 'slug' => $h['slug'], 'address' => $h['address'],
        'resource_id' => $res ? $res->get('id') : null,
        'capacity_sleep' => $h['capacity_sleep'], 'capacity_party' => $h['capacity_party'],
        'checkin_time' => $h['checkin_time'], 'checkout_time' => $h['checkout_time'], 'cleanup_minutes' => 60,
        'addon_bookable_separately' => 0, 'active' => 1, 'sort' => $h['sort'],
    ]);
    $house->save();
    foreach ($types as $i => $t) {
        $r = $modx->newObject('Booking\\Model\\PriceRule');
        $r->fromArray(['house_id' => $house->get('id'), 'day_type' => $t, 'price' => $h['prices'][$i],
            'extra_guest_price' => 500, 'extra_guest_limit' => $h['limit'], 'pet_price' => 1000]);
        $r->save();
    }
    $a = $modx->newObject('Booking\\Model\\Addon');
    $a->fromArray(['house_id' => $house->get('id'), 'name' => 'Баня', 'price_per_hour' => null, 'min_hours' => 2, 'buffer_minutes' => 60, 'active' => 1]);
    $a->save();
}
$modx->log(\xPDO\xPDO::LOG_LEVEL_INFO, 'booking: стартовые Дома и цены записаны');
return true;
