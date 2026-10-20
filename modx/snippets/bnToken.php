<?php
/** bnToken — защитный код формы заявки (вызывать некэшированным: [[!bnToken]]) */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (empty($_SESSION['bn_token'])) $_SESSION['bn_token'] = bin2hex(random_bytes(16));
return $_SESSION['bn_token'];
