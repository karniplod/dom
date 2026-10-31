<?php
/**
 * Резолвер 1.2.15 (решение владельца 01.11.2026), один раз — флаг booking.objects_1_2_15:
 * — «Сауна» — отдельная от Домов (kind=bath), только по часам; «Номер» — посуточно. Оба выключены, без адреса:
 *   где они, на сайте пока не пишем; владелец включает сам («Дома и бани» → «показывать в форме»);
 * — баня Дома на Свиязева по часам суток: Пн–Пт до 18:00 — 1 500 ₽, с 18:00 до 09:00 и Сб, Вс — 2 000 ₽;
 *   в цене до 8 гостей, каждый следующий +300 ₽ в час. Цены по часам, которые уже заданы, не трогаем.
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */
use xPDO\Transport\xPDOTransport;
use MODX\Revolution\modSystemSetting;

$modx = $transport->xpdo;
if (!in_array($options[xPDOTransport::PACKAGE_ACTION], [xPDOTransport::ACTION_INSTALL, xPDOTransport::ACTION_UPGRADE], true)) {
    return true;
}
if ($modx->getObject(modSystemSetting::class, ['key' => 'booking.objects_1_2_15'])) {
    return true;
}
$H = 'Booking\\Model\\House';
$A = 'Booking\\Model\\Addon';
$bathRates = [
    ['days' => ['weekday', 'friday'], 'from' => '18:00', 'to' => '09:00', 'price' => 2000],
    ['days' => ['saturday', 'sunday'], 'from' => '00:00', 'to' => '24:00', 'price' => 2000],
];
$sort = 0;
foreach ($modx->getIterator($H) as $x) {
    $sort = max($sort, (int)$x->get('sort'));
}

if (!$modx->getObject($H, ['code' => 'sauna'])) {
    $h = $modx->newObject($H);
    $h->fromArray(['code' => 'sauna', 'name' => 'Сауна', 'slug' => 'sauna', 'kind' => 'bath', 'address' => '',
        'capacity_sleep' => 12, 'capacity_party' => 12, 'checkin_time' => '15:00:00', 'checkout_time' => '12:00:00', 'cleanup_minutes' => 0,
        'addon_bookable_separately' => 1, 'active' => 0, 'sort' => ++$sort]);
    $h->save();
    $a = $modx->newObject($A);
    $a->fromArray(['house_id' => $h->get('id'), 'name' => 'Сауна', 'price_per_hour' => 1500, 'min_hours' => 2, 'buffer_minutes' => 60, 'active' => 1,
        'rates' => $bathRates, 'guest_limit' => 8, 'extra_guest_price' => 300, 'extra_guest_per' => 'hour']);
    $a->save();
}
if (!$modx->getObject($H, ['code' => 'room'])) {
    // вместимость и цены суток владелец задаст перед включением (без цен заявка не посчитается)
    $h = $modx->newObject($H);
    $h->fromArray(['code' => 'room', 'name' => 'Номер', 'slug' => 'nomer', 'kind' => 'house', 'address' => '',
        'capacity_sleep' => 2, 'capacity_party' => 2, 'checkin_time' => '15:00:00', 'checkout_time' => '12:00:00', 'cleanup_minutes' => 60,
        'addon_bookable_separately' => 0, 'active' => 0, 'sort' => ++$sort]);
    $h->save();
}
$sv = $modx->getObject($H, ['code' => 'sv']);
$a = $sv ? $modx->getObject($A, ['house_id' => $sv->get('id')]) : null;
if ($a && !$a->get('rates')) {
    $a->fromArray(['rates' => $bathRates, 'guest_limit' => 8, 'extra_guest_price' => 300, 'extra_guest_per' => 'hour']);
    if (!(int)$a->get('price_per_hour')) {
        $a->set('price_per_hour', 1500);
    }
    $a->save();
}
$s = $modx->newObject(modSystemSetting::class);
$s->fromArray(['key' => 'booking.objects_1_2_15', 'value' => '1', 'xtype' => 'textfield', 'namespace' => 'booking', 'area' => 'booking'], '', true);
$s->save();
$modx->log(\xPDO\xPDO::LOG_LEVEL_INFO, 'booking: Сауна и Номер (выключены), цены бани Свиязева по часам');
return true;
