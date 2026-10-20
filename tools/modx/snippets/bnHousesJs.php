<?php
/** bnHousesJs — данные домов для расчёта стоимости в форме (window.HOUSES) */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::housesJs();
