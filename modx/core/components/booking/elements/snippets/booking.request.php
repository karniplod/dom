<?php
/**
 * booking.request — страница «Моя заявка»: по ссылке ?id=&t= или по номеру и телефону.
 * Данные загружает booking.js через api.php (booking_get_request), отмену — booking_request_cancel.
 * @var \MODX\Revolution\modX $modx
 * @var array $scriptProperties
 */
/** @var \Booking\Service\Booking $booking */
$booking = $modx->services->has('booking') ? $modx->services->get('booking') : null;
if (!$booking) {
    return '';
}
static $bookingAssets = false;
if (!$bookingAssets) {
    $bookingAssets = true;
    $v = '?v=' . \Booking\Service\Booking::VERSION;
    $modx->regClientCSS($booking->config['assetsUrl'] . 'css/booking.css' . $v);
    $modx->regClientScript('<script src="' . $booking->config['assetsUrl'] . 'js/booking.js' . $v . '" defer></script>', true);
}
$id = (int)($_GET['id'] ?? 0);
$t = preg_match('~^[a-f0-9]{32}$~', (string)($_GET['t'] ?? '')) ? $_GET['t'] : '';
return $modx->getChunk($scriptProperties['tpl'] ?? 'booking.request.tpl', [
    'api' => $booking->config['apiUrl'],
    'id' => $id ?: '',
    't' => $t,
    'form_url' => (int)$modx->getOption('booking.form_page') ? $modx->makeUrl((int)$modx->getOption('booking.form_page'), '', '', 'abs') : '',   // от корня: страница заявки вложена в /booking/
]);
