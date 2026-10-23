<?php
/** bnMenu — меню сайта из страниц: верхний уровень, опубликованные, с галочкой «Показывать в меню», по «Позиции в меню». &place=`nav` (шапка; Дома — выпадающим списком), `drawer` (выдвижное меню), `foot` (подвал); Дома в drawer и foot выводятся отдельными списками */
use MODX\Revolution\modResource;

$place = $modx->getOption('place', $scriptProperties, 'nav');
$houseTpl = $modx->getObject(\MODX\Revolution\modTemplate::class, ['templatename' => 'bn-house']);
$houseTplId = $houseTpl ? (int)$houseTpl->get('id') : -1;
$start = (int)$modx->getOption('site_start', null, 1);
$cur = $modx->resource ? (int)$modx->resource->get('id') : 0;
$curParents = $cur ? $modx->getParentIds($cur, 10, ['context' => 'web']) : [];
$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$c = $modx->newQuery(modResource::class, ['parent' => 0, 'published' => 1, 'deleted' => 0, 'hidemenu' => 0, 'context_key' => 'web']);
$c->select(['id', 'pagetitle', 'menutitle', 'template', 'menuindex']);
$c->sortby('menuindex', 'ASC');
$c->sortby('id', 'ASC');
$items = [];
$housesAt = null;
foreach ($modx->getIterator(modResource::class, $c) as $r) {
    $id = (int)$r->get('id');
    if ((int)$r->get('template') === $houseTplId) {   // Дома — одним пунктом-списком на месте первого Дома
        if ($housesAt === null) { $housesAt = count($items); $items[] = 'houses'; }
        continue;
    }
    $items[] = ['id' => $id, 'title' => $r->get('menutitle') ?: $r->get('pagetitle'), 'url' => $modx->makeUrl($id, '', '', 'abs')];
}
// «Бронирование» есть всегда: без компонента booking — форма заявки на главной
$formPage = (int)$modx->getOption('booking.form_page');
if (!$formPage || !$modx->getCount(modResource::class, ['id' => $formPage, 'published' => 1, 'deleted' => 0])) {
    $after = 0;
    foreach ($items as $i => $it) if ($it !== 'houses' && $it['id'] === $start) $after = $i + 1;
    if ($housesAt !== null && $housesAt >= $after) $after = $housesAt + 1;
    array_splice($items, $after, 0, [['id' => 0, 'title' => 'Бронирование', 'url' => $modx->makeUrl($start, '', '', 'abs') . '#order']]);
}

$out = '';
foreach ($items as $it) {
    if ($it === 'houses') {
        if ($place !== 'nav') continue;
        $inHouse = false;
        foreach (array_merge([$cur], $curParents) as $pid) {
            if ($pid && ($p = $modx->getObject(modResource::class, $pid)) && (int)$p->get('template') === $houseTplId) { $inHouse = true; break; }
        }
        $out .= "\n    " . '<div class="nav__drop"><button class="nav__btn' . ($inHouse ? ' is-current' : '') . '" type="button" aria-expanded="false" aria-controls="nav-houses">Дома<svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>'
            . "\n      " . '<div class="nav__panel" id="nav-houses">' . $modx->runSnippet('bnHouses', ['tpl' => 'bn.tpl.navHouse'])
            . '<a class="nav__all" href="' . $e($modx->makeUrl($start, '', '', 'abs')) . '#prices">Сравнить Дома и цены</a></div></div>';
        continue;
    }
    $aria = $it['id'] && $it['id'] === $cur ? ' aria-current="page"' : ($it['id'] && $it['id'] !== $start && in_array($it['id'], $curParents, true) ? ' aria-current="true"' : '');
    $a = '<a href="' . $e($it['url']) . '"' . ($place === 'nav' ? $aria : '') . '>' . $e($it['title']) . '</a>';
    $out .= $place === 'nav' ? "\n    " . $a : $a;
}
return $out . ($place === 'nav' ? "\n  " : '');
