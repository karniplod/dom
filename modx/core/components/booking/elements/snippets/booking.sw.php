<?php
/**
 * booking.sw — Service Worker приложения, отдаётся страницей /booking/sw.js (область /booking/).
 * Код — файл assets/components/booking/app/sw.js. Кеширует только статику; api.php — только сеть (ст. 5.2).
 * @var \MODX\Revolution\modX $modx
 */
$booking = $modx->services->has('booking') ? $modx->services->get('booking') : null;
if (!$booking) {
    return '';
}
// область по умолчанию — каталог файла (/booking/), отдельный заголовок Service-Worker-Allowed не нужен
header('Cache-Control: no-cache');
$file = $modx->getOption('assets_path') . 'components/booking/app/sw.js';
$code = is_file($file) ? file_get_contents($file) : '';
return str_replace(['{{assets}}', '{{version}}'], [$booking->config['assetsUrl'], \Booking\Service\Booking::VERSION], $code);
