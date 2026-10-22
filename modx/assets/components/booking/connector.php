<?php
/**
 * Коннектор админки MODX (CMP «Бронирование»): сессия менеджера + HTTP_MODAUTH, право booking_admin.
 * Вызывает те же процессоры, что и api.php (ст. 3.3, 7.7).
 */
require_once dirname(__FILE__, 4) . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$modx = \MODX\Revolution\modX::getInstance(null, [\xPDO\xPDO::OPT_CONN_INIT => [\xPDO\xPDO::OPT_CONN_MUTABLE => true]]);
$modx->initialize('mgr');

function booking_cmp_out(array $r): void
{
    http_response_code($r['status']);
    unset($r['status']);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @session_write_close();
    exit;
}

/** @var \Booking\Service\Booking $booking */
$booking = $modx->services->get('booking');
$auth = $_SERVER['HTTP_MODAUTH'] ?? ($_REQUEST['HTTP_MODAUTH'] ?? '');
if (!$modx->user->isAuthenticated('mgr') || !is_string($auth) || $auth === '' || !hash_equals((string)$modx->user->getUserToken('mgr'), $auth)) {
    booking_cmp_out($booking->reply(401, false, 'Войдите в админку MODX'));
}
$actor = $booking->access()->fromManagerSession('cmp');
if (!$actor) {
    booking_cmp_out($booking->reply(403, false, 'Нет права booking_admin'));
}
$booking->setActor($actor);

$params = $_POST + $_GET;
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    $params = array_merge($params, is_array($json) ? $json : []);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    booking_cmp_out($booking->reply(405, false, 'Нужен POST'));
}
$action = is_string($params['action'] ?? null) ? $params['action'] : '';
booking_cmp_out($booking->runAction($action, $params));
