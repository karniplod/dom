<?php
/** bnImg — фото с WebP-копиями (<picture>), как на статичном сайте: &src (путь из поля, например assets/img/ru/hero-2.jpg), &alt, &cls, &lazy=`0` для первого экрана, &sizes */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$src = Bania::src($modx->getOption('src', $scriptProperties, ''));
if ($src === '') return '';
return Bania::img($src, (string)$modx->getOption('alt', $scriptProperties, ''), (string)$modx->getOption('cls', $scriptProperties, ''),
    (bool)$modx->getOption('lazy', $scriptProperties, 1), (string)$modx->getOption('sizes', $scriptProperties, '(max-width: 760px) 100vw, 420px'));
