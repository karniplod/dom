<?php
/** bnBlogChips — кнопки рубрик над статьями блога */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::blogChips();
