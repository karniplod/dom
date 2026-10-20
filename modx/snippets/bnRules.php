<?php
/** bnRules — правила домов из поля «Правила» главной; &house — id дома: добавить его строки */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::rules((int)($house ?? 0));
