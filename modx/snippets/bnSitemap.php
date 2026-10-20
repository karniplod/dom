<?php
/** bnSitemap — карта сайта sitemap.xml: опубликованные страницы для поиска, без служебных */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$skip = array_values(array_filter([Bania::templateId('bn-section'), Bania::templateId('bn-review'), Bania::templateId('bn-prepay')]));
$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
$c = $modx->newQuery(\MODX\Revolution\modResource::class, ['published' => 1, 'deleted' => 0, 'searchable' => 1, 'context_key' => 'web', 'class_key' => \MODX\Revolution\modDocument::class, 'template:!=' => 0]);
if ($skip) $c->where(['template:NOT IN' => $skip]);
$c->sortby('menuindex', 'ASC');
foreach ($modx->getIterator(\MODX\Revolution\modResource::class, $c) as $r) {
    $out .= '<url><loc>' . htmlspecialchars($modx->makeUrl($r->get('id'), '', '', 'full')) . '</loc><lastmod>' . date('Y-m-d', Bania::ts($r->get('editedon') ?: $r->get('publishedon') ?: $r->get('createdon'))) . '</lastmod></url>';
}
return $out . '</urlset>';
