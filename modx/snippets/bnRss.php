<?php
/** bnRss — лента статей блога rss.xml */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$tid = Bania::templateId('bn-post'); $cats = Bania::cats();
$items = '';
$c = $modx->newQuery(\MODX\Revolution\modResource::class, ['template' => $tid, 'published' => 1, 'deleted' => 0]);
$c->sortby('publishedon', 'DESC');
foreach ($modx->getIterator(\MODX\Revolution\modResource::class, $c) as $r) {
    $u = htmlspecialchars($modx->makeUrl($r->get('id'), '', '', 'full'));
    $items .= '<item><title>' . htmlspecialchars($r->get('pagetitle')) . '</title><link>' . $u . '</link><guid>' . $u . '</guid><pubDate>' . date(DATE_RSS, Bania::ts($r->get('publishedon'))) . '</pubDate><category>' . htmlspecialchars($cats[$r->getTVValue('bn_post_cat')] ?? '') . '</category><description>' . htmlspecialchars($r->get('description')) . '</description></item>';
}
return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0"><channel><title>Блог — ' . htmlspecialchars($modx->getOption('site_name')) . '</title><link>' . htmlspecialchars($modx->getOption('site_url')) . '</link><description>Статьи об отдыхе в домах с баней и бассейном в Перми</description><language>ru</language>' . $items . '</channel></rss>';
