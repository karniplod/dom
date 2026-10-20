<?php
/** bnReviews — блок отзывов (раздел «Отзывы»): &house — id дома, только его отзывы */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::reviews((int)($house ?? 0));
