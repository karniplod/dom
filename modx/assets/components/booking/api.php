<?php
/**
 * API компонента booking: один вход для сайта, PWA и мини-приложения MAX.
 * Роль: Authorization: Bearer <токен> → администратор (владелец токена), иначе гость.
 * Те же процессоры, что и в админке MODX (ст. 3.3, 7.7). Ответ: {success, message, data}, без кеша (ст. 5.2).
 */
define('MODX_API_MODE', true);
require_once dirname(__FILE__, 4) . '/index.php';

/** @var \MODX\Revolution\modX $modx */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

function booking_out(array $r): void
{
    http_response_code($r['status']);
    unset($r['status']);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @session_write_close();
    exit;
}

/** @var \Booking\Service\Booking $booking */
$booking = $modx->services->has('booking') ? $modx->services->get('booking') : null;
if (!$booking) {
    booking_out(['status' => 503, 'success' => false, 'message' => 'Бронирование временно недоступно', 'data' => (object)[]]);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
$params = $_GET;
if ($method === 'POST') {
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== false) {
        $json = json_decode((string)file_get_contents('php://input'), true);
        $params = array_merge($params, is_array($json) ? $json : []);
    } else {
        $params = array_merge($params, $_POST);
    }
}
$action = is_string($params['action'] ?? null) ? $params['action'] : '';

// откуда запрос: сайт, приложение или мини-приложение MAX (только метка для журнала и источника заявки)
$client = in_array($params['client'] ?? '', ['site', 'pwa', 'max'], true) ? $params['client'] : 'site';
unset($params['client']);

$actor = $booking->access()->fromBearer($client === 'site' ? 'api' : $client);
$isAdmin = $actor !== null;
if (!$isAdmin && (preg_match('~^Bearer\s~i', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')
        || !empty($_SERVER['HTTP_X_BOOKING_TOKEN']))) {
    // неверный ключ — в общий лимит адреса: перебор ключей упирается в 429 (подобрать ключ из 40 случайных знаков и так нельзя)
    if (!$booking->access()->rateLimit($_SERVER['REMOTE_ADDR'] ?? '')) {
        booking_out($booking->reply(429, false, 'Слишком много запросов. Подождите минуту'));
    }
    booking_out($booking->reply(401, false, 'Токен недействителен или отозван'));
}
$booking->setActor($actor ?? ['role' => 'guest', 'name' => 'гость', 'interface' => $client]);

if (!$isAdmin && !$booking->access()->rateLimit($_SERVER['REMOTE_ADDR'] ?? '')) {
    booking_out($booking->reply(429, false, 'Слишком много запросов, подождите минуту'));
}

// заявка гостя создаётся только формой через SendIt (ст. 8.1)
if ($action === 'booking_create_request') {
    booking_out($booking->reply(400, false, 'Заявка отправляется формой бронирования'));
}

// изменения — только POST
$readOnly = ['booking_get_availability', 'booking_quote', 'booking_get_request', 'booking_houses', 'booking_list', 'booking_grid', 'booking_get'];
if ($method !== 'POST' && !in_array($action, $readOnly, true)) {
    booking_out($booking->reply(405, false, 'Нужен POST'));
}

booking_out($booking->runAction($action, $params));
