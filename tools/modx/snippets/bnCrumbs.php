<?php
/** bnCrumbs — хлебные крошки текущей страницы */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::crumbs($modx->resource)['html'];
