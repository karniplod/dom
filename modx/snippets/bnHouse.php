<?php
/** bnHouse — данные дома для шаблона: &part (name, min_price, prepay_url, facts, gallery, comfort, prices, hinfo, rules, faq…), &id — по умолчанию текущий дом */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$id = (int)($id ?? 0) ?: (int)$modx->resource->get('id');
$h = Bania::houseById($id);
if (!$h) return '';
$part = (string)($part ?? 'name');
switch ($part) {
    case 'facts': return Bania::facts($h);
    case 'gallery': return Bania::gallery($h);
    case 'comfort': return Bania::comfort($h);
    case 'prices': return Bania::priceDays($h);
    case 'hinfo': return Bania::hinfo($h);
    case 'tour2': return Bania::tour2Btn($h);
    case 'rules': return Bania::rules($h['id']);
    case 'faq': return Bania::faqHtml($h['faq']);
    case 'reviews': return Bania::reviews($h['id']);
    case 'others': return Bania::listHouses('bn.tpl.houseCard', (string)$h['id']);
    case 'hero_img': return Bania::img($h['hero'], $h['name'] . ', ' . ($h['hero_alt'] ?? 'фото'), 'dhero__img', false);
}
return isset($h[$part]) && !is_array($h[$part]) ? (string)$h[$part] : '';
