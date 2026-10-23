<?php
/** bnBookingUrl — адрес страницы бронирования /booking (компонент booking); без компонента — форма заявки на главной */
$id = (int)$modx->getOption('booking.form_page');
$page = $id ? $modx->getObject(\MODX\Revolution\modResource::class, ['id' => $id, 'published' => 1, 'deleted' => 0]) : null;
return $page ? $modx->makeUrl($id, '', '', 'abs') : $modx->makeUrl((int)$modx->getOption('site_start', null, 1), '', '', 'abs') . '#order';
