<?php
/** bnMinPrice — самая низкая цена за сутки среди домов, например «15 000 ₽» */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::minPrice();
