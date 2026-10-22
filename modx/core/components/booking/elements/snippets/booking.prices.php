<?php
/**
 * booking.prices — цены суток дома по типам дней из БД (ст. 6.3).
 * [[booking.prices? &house=`en` &tpl=`booking.prices.tpl`]] — та же разметка, что блок «Цена за сутки» сайта (.price-days).
 * Кэшируемый: кэш сбрасывается при изменении цен в админке.
 * @var \MODX\Revolution\modX $modx
 * @var array $scriptProperties
 */
/** @var \Booking\Service\Booking $booking */
$booking = $modx->services->has('booking') ? $modx->services->get('booking') : null;
if (!$booking) {
    return '';
}
$code = (string)($scriptProperties['house'] ?? '');
$house = $modx->getObject(\Booking\Model\House::class, ['code' => $code]);
if (!$house) {
    return '';
}
$rules = $booking->pricing()->rules($house->get('id'));
$items = [];
foreach (\Booking\Service\Pricing::DAY_TYPES as $t) {
    if (isset($rules[$t])) {
        $items[$t] = (int)$rules[$t]->get('price');
    }
}
if (!$items) {
    return '';
}
$hot = array_search(max($items), $items, true);   // самый дорогой день подсвечен, как на сайте
$tpl = $scriptProperties['tpl'] ?? 'booking.prices.tpl';
$out = '';
foreach ($items as $t => $price) {
    $out .= $modx->getChunk($tpl, [
        'type' => $t,
        'label' => \Booking\Service\Pricing::DAY_LABELS[$t],
        'price' => $price,
        'price_text' => $booking->money($price),
        'cls' => $t === $hot ? ' class="hot"' : '',
    ]);
}
return $out;
