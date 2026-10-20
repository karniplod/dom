<?php
/** bnStrip — лента «Посмотрите дома изнутри» из поля bn_strip главной */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::strip();
