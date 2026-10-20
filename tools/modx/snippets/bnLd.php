<?php
/** bnLd — разметка schema.org для текущей страницы (организация, дом, вопросы, статья, крошки) */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
return Bania::ld($modx->resource);
