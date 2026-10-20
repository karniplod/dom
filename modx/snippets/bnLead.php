<?php
/** bnLead — приём заявки с сайта (страница «API: заявка»): сохраняет в «Заявки», шлёт письмо и в Telegram, отвечает JSON */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); return json_encode(['ok' => false, 'message' => 'Только POST'], JSON_UNESCAPED_UNICODE); }
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$res = Bania::acceptLead($_POST);
http_response_code((int)($res['code'] ?? 200));
unset($res['code']);
return json_encode($res, JSON_UNESCAPED_UNICODE);
