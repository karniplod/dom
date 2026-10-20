<?php
/** bnPost — части статьи: &part = toc | body | meta | cover | houses | share_url | share_title */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$r = $modx->resource;
switch ((string)($part ?? 'body')) {
    case 'toc': return Bania::postParts($r)['toc'];
    case 'body': return Bania::postParts($r)['body'];
    case 'meta':
        $cats = Bania::cats(); $cat = (string)$r->getTVValue('bn_post_cat'); $t = Bania::ts($r->get('publishedon')) ?: time();
        return '<p class="pmeta"><a href="' . Bania::url($r->get('parent')) . '">' . Bania::e($cats[$cat] ?? $cat) . '</a><time datetime="' . date('Y-m-d', $t) . '">' . Bania::ruDate($t) . '</time><span>' . Bania::readMin($r) . ' мин чтения</span></p>';
    case 'cover':
        $src = Bania::src($r->getTVValue('bn_cover')); $alt = (string)$r->getTVValue('bn_cover_alt');
        return $src ? '<figure class="post__cover">' . Bania::img($src, $alt, '', false) . '<figcaption>' . Bania::e($alt) . '</figcaption></figure>' : '';
    case 'houses':
        $ids = array_values(array_filter(array_map('trim', preg_split('~\|\||,~', Bania::tvRaw($r, 'bn_post_houses')))));
        if (!$ids) return '';
        $n = count($ids);
        return '<div class="houses' . ($n === 2 ? ' houses--2' : ($n === 1 ? ' houses--1' : '')) . '">' . Bania::listHouses('bn.tpl.houseCard', '', implode(',', $ids)) . '</div>';
    case 'share_url': return rawurlencode(Bania::full(Bania::url($r->get('id'))));
    case 'share_title': return rawurlencode((string)$r->get('pagetitle'));
}
return '';
