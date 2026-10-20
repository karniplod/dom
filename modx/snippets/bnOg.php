<?php
/** bnOg — картинка для превью ссылки (og:image) с размерами: &tv — поле с картинкой */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$v = (string)$modx->resource->getTVValue($tv ?? 'bn_hero');
if ($v === '' && $modx->resource->get('parent')) { $p = $modx->getObject(\MODX\Revolution\modResource::class, $modx->resource->get('parent')); if ($p) $v = (string)$p->getTVValue('bn_hero'); }
if ($v === '') $v = (string)$modx->getOption('bn_og_default', null, 'assets/img/ru/hero-2.jpg');
$path = ltrim($v, '/');
$base = trim((string)$modx->getOption('base_url'), '/');
if ($base !== '' && strpos($path, $base . '/') === 0) $path = substr($path, strlen($base) + 1);
$u = Bania::full($path);
$wh = @getimagesize(MODX_BASE_PATH . $path);
$alt = Bania::e($modx->resource->get('pagetitle'));
return '<meta property="og:image" content="' . $u . '"><meta property="og:image:secure_url" content="' . $u . '"><meta property="og:image:type" content="image/jpeg">'
    . ($wh ? '<meta property="og:image:width" content="' . $wh[0] . '"><meta property="og:image:height" content="' . $wh[1] . '">' : '')
    . '<meta property="og:image:alt" content="' . $alt . '"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="' . $u . '"><link rel="image_src" href="' . $u . '">';
