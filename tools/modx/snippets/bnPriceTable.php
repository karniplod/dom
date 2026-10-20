<?php
/** bnPriceTable — таблица сравнения домов и цен на главной */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::priceTable();
