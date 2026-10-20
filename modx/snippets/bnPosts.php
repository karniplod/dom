<?php
/** bnPosts — карточки статей блога: &limit, &exclude (id), &big=1 — первая крупно */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::posts((int)($limit ?? 0), (int)($exclude ?? 0), !empty($big), $tpl ?? 'bn.tpl.postCard');
