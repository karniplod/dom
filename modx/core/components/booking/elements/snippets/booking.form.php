<?php
/**
 * booking.form — единая форма бронирования (ст. 7.3).
 * [[!booking.form? &mode=`full|compact` &house=`en`]]
 *  mode=full    — страница /booking: выбор дома, календарь на 2 месяца, гости, питомцы, баня отдельно, расчёт, контакты;
 *  mode=compact — главная и места прежней формы: дом, даты по клику, гости, расчёт, контакты;
 *  house        — код дома, выбранного заранее (страница дома).
 * Отправка — SendIt, пресет booking_request (ст. 8.1). Некэшируемый: токен SendIt и защита от ботов.
 * @var \MODX\Revolution\modX $modx
 * @var array $scriptProperties
 */
/** @var \Booking\Service\Booking $booking */
$booking = $modx->services->has('booking') ? $modx->services->get('booking') : null;
if (!$booking) {
    return '';
}
$mode = ($scriptProperties['mode'] ?? 'full') === 'compact' ? 'compact' : 'full';
$houseCode = preg_replace('~[^a-z0-9_-]~', '', strtolower((string)($scriptProperties['house'] ?? '')));
$tpl = $scriptProperties['tpl'] ?? ($mode === 'compact' ? 'booking.form.compact.tpl' : 'booking.form.tpl');

// дома — из того же процессора, что и для приложения
$booking->setActor(['role' => 'guest', 'name' => 'гость', 'interface' => 'site']);
$res = $booking->runAction('booking_houses', []);
$houses = $res['success'] ? ((array)$res['data'])['houses'] : [];
if (!$houses) {
    return '';
}
$options = '';
foreach ($houses as $h) {
    $options .= '<option value="' . htmlspecialchars($h['code']) . '"' . ($h['code'] === $houseCode ? ' selected' : '') . '>'
        . htmlspecialchars($h['name']) . '</option>';
}

// пресет SendIt — в сессию посетителя, как штатный сниппет RenderForm
$preset = include $booking->config['corePath'] . 'presets/booking_request.inc.php';
$sessionClass = '\\SendIt\\Session\\SessionManager';
$autoload = $modx->getOption('core_path') . 'components/sendit/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}
if (class_exists($sessionClass)) {
    $sm = new $sessionClass($modx);
    $session = $sm->get();
    $session['presets']['booking_request'] = $preset['booking_request'];
    $sm->set($session);
} else {
    $modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR, '[booking] SendIt не установлен — форма бронирования не отправится');
}

// стили и модуль календаря — один раз на страницу
static $bookingAssets = false;
if (!$bookingAssets) {
    $bookingAssets = true;
    $v = '?v=' . \Booking\Service\Booking::VERSION;
    $modx->regClientCSS($booking->config['assetsUrl'] . 'css/booking.css' . $v);
    $modx->regClientScript('<script src="' . $booking->config['assetsUrl'] . 'js/booking.js' . $v . '" defer></script>', true);
    // SendIt строит карту защиты только из файловых пресетов; наш пресет — сессионный, добавляем его в карту,
    // чтобы браузер посчитал доказательство вычислений и подпись, которые сервер проверяет по пресету
    $p = $preset['booking_request'];
    $modx->regClientScript('<script>window.siConfig&&(window.siConfig.protectionMap=window.siConfig.protectionMap||{},'
        . 'window.siConfig.protectionMap.booking_request=' . json_encode(['pow' => (bool)$p['usePoW'], 'sign' => (bool)$p['useBehaviorSign'],
            'powDifficulty' => (int)$p['powDifficulty']]) . ');</script>', true);
}

// документы сайта по 152-ФЗ (страницы politika и soglasie); нет страницы — ссылка на главную
$doc = function ($alias) use ($modx) {
    $r = $modx->getObject(\MODX\Revolution\modResource::class, ['alias' => $alias, 'published' => 1, 'deleted' => 0]);
    return $r ? $modx->makeUrl($r->get('id')) : $modx->makeUrl((int)$modx->getOption('site_start'));
};

static $n = 0;
$n++;
return $modx->getChunk($tpl, [
    'uid' => 'bk' . $n,
    'mode' => $mode,
    'house' => $houseCode,
    'options' => $options,
    'houses' => htmlspecialchars(json_encode($houses, JSON_UNESCAPED_UNICODE)),
    'api' => $booking->config['apiUrl'],
    'discount' => (int)$modx->getOption('booking.vk_discount', null, 5),
    'vk_url' => htmlspecialchars((string)$modx->getOption('booking.vk_url', null, 'https://vk.com/russianbath59')),
    'today' => $booking->today(),
    'client' => htmlspecialchars((string)($scriptProperties['client'] ?? 'site')),
    'politika_url' => $doc('politika'),
    'soglasie_url' => $doc('soglasie'),
]);
