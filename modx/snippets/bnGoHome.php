<?php
/** bnGoHome — служебные разделы (отзывы) открываются как главная: постоянное перенаправление */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$modx->sendRedirect($modx->makeUrl((int)$modx->getOption('site_start'), '', '', 'full'), ['responseCode' => 'HTTP/1.1 301 Moved Permanently']);
return '';
