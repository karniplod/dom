<?php
/**
 * Вебхук бота MAX: сюда MAX присылает сообщения гостей (POST, JSON-событие, заголовок x-max-bot-api-secret).
 * Бот отвечает в личном чате по данным сайта (Booking\Service\Faq). Подключение — cli/bot.php subscribe <адрес этого файла>.
 * Всегда отвечаем 200 быстро: иначе MAX повторяет событие. Без секрета или с выключенным ботом — ничего не делаем.
 */
define('MODX_API_MODE', true);
require_once dirname(__FILE__, 4) . '/index.php';

/** @var \MODX\Revolution\modX $modx */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$done = function (int $code, string $result) {
    http_response_code($code);
    echo json_encode(['ok' => $code === 200, 'result' => $result]);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $done(405, 'method');
}
/** @var \Booking\Service\Booking $booking */
$booking = $modx->services->has('booking') ? $modx->services->get('booking') : null;
if (!$booking) {
    $done(200, 'off');
}
$bot = $booking->maxBot();
if (!$bot->checkSecret((string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET'] ?? ''))) {
    $done(403, 'secret');
}
$update = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($update)) {
    $done(200, 'empty');
}
if (!$bot->enabled()) {   // ответы выключены — только запоминаем группы и каналы бота (для «Найти группы и каналы»)
    $bot->observe($update);
    $done(200, 'off');
}
$booking->setActor(['role' => 'guest', 'name' => 'гость', 'interface' => 'max']);
try {
    $done(200, $bot->handle($update));
} catch (\Throwable $e) {
    $modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR, '[booking] бот MAX: ' . $e->getMessage());
    $done(200, 'error');
}
