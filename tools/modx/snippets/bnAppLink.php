<?php
/** bnAppLink — ссылка на приложение бронирования (/booking/app компонента booking); приложения нет — ничего */
$fp = (int)$modx->getOption('booking.form_page');
$app = $fp ? $modx->getObject(\MODX\Revolution\modResource::class, ['alias' => 'app', 'parent' => $fp, 'published' => 1, 'deleted' => 0]) : null;
if (!$app) return '';
return '<p><a class="link" href="' . htmlspecialchars($modx->makeUrl($app->get('id'), '', '', 'abs'), ENT_QUOTES, 'UTF-8') . '">Приложение для бронирования на телефон</a></p>';
