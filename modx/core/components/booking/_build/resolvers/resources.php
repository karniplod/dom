<?php
/**
 * Резолвер: страницы компонента — только если их ещё нет (страницы сайта не меняются).
 *  /booking          — форма [[!booking.form? &mode=`full`]] на шаблоне текстовой страницы сайта (bn-text, если есть);
 *  /booking/zayavka  — «Моя заявка» [[!booking.request]];
 *  /booking/app      — приложение (PWA, мини-приложение MAX) на своём шаблоне booking.app;
 *  /booking/sw.js    — Service Worker приложения (область /booking/).
 * Скрыты из меню; /booking/app и sw.js — не для поиска.
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */
use MODX\Revolution\modContentType;
use MODX\Revolution\modDocument;
use MODX\Revolution\modResource;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modTemplate;
use xPDO\Transport\xPDOTransport;

$modx = $transport->xpdo;
if (!in_array($options[xPDOTransport::PACKAGE_ACTION], [xPDOTransport::ACTION_INSTALL, xPDOTransport::ACTION_UPGRADE], true)) {
    return true;
}

$setting = function ($key, $value) use ($modx) {
    $s = $modx->getObject(modSystemSetting::class, ['key' => $key]);
    if ($s && (int)$s->get('value') === 0) {
        $s->set('value', (string)$value);
        $s->save();
    }
};
$exists = function ($id) use ($modx) {
    return $id > 0 && $modx->getObject(modResource::class, ['id' => $id, 'deleted' => 0]);
};
$make = function (array $f) use ($modx) {
    $r = $modx->newObject(modDocument::class);
    $r->fromArray(array_merge([
        'class_key' => modDocument::class, 'published' => 1, 'hidemenu' => 1, 'searchable' => 1, 'cacheable' => 1,
        'richtext' => 0, 'context_key' => 'web', 'content_type' => 1, 'publishedon' => time(), 'createdon' => time(),
    ], $f));
    $r->save();
    return $r;
};

$textTpl = $modx->getObject(modTemplate::class, ['templatename' => 'bn-text']);
$tplId = $textTpl ? $textTpl->get('id') : (int)$modx->getOption('default_template');

$formPage = (int)$modx->getOption('booking.form_page');
if (!$exists($formPage)) {
    $page = $modx->getObject(modResource::class, ['alias' => 'booking', 'parent' => 0, 'deleted' => 0]) ?: $make([
        'pagetitle' => 'Бронирование', 'longtitle' => 'Забронировать Дом с баней', 'alias' => 'booking', 'parent' => 0,
        'isfolder' => 1, 'template' => $tplId, 'cacheable' => 0,
        'description' => 'Выберите Дом и даты: календарь занятости, расчёт стоимости и заявка онлайн. Администратор перезвонит и подтвердит бронь.',
        'content' => '[[!booking.form? &mode=`full`]]',
    ]);
    $formPage = (int)$page->get('id');
    $setting('booking.form_page', $formPage);
}

$reqPage = (int)$modx->getOption('booking.request_link_page');
if (!$exists($reqPage)) {
    $page = $modx->getObject(modResource::class, ['alias' => 'zayavka', 'parent' => $formPage, 'deleted' => 0]) ?: $make([
        'pagetitle' => 'Моя заявка', 'alias' => 'zayavka', 'parent' => $formPage, 'template' => $tplId,
        'searchable' => 0, 'cacheable' => 0, 'content' => '[[!booking.request]]',
    ]);
    $setting('booking.request_link_page', $page->get('id'));
}

$appTpl = $modx->getObject(modTemplate::class, ['templatename' => 'booking.app']);
if ($appTpl && !$modx->getObject(modResource::class, ['alias' => 'app', 'parent' => $formPage, 'deleted' => 0])) {
    $make([
        'pagetitle' => 'Приложение', 'longtitle' => 'Бронирование — приложение', 'alias' => 'app', 'parent' => $formPage,
        'template' => $appTpl->get('id'), 'searchable' => 0, 'cacheable' => 0, 'content' => '',
    ]);
}
$js = $modx->getObject(modContentType::class, ['mime_type' => 'text/javascript'])
    ?: $modx->getObject(modContentType::class, ['mime_type' => 'application/javascript']);
if ($js && !$modx->getObject(modResource::class, ['alias' => 'sw', 'parent' => $formPage, 'deleted' => 0])) {
    $make([
        'pagetitle' => 'sw.js', 'alias' => 'sw', 'parent' => $formPage, 'template' => 0, 'searchable' => 0, 'cacheable' => 0,
        'content_type' => $js->get('id'), 'content_dispo' => 0, 'content' => '[[!booking.sw]]',
    ]);
}
$modx->cacheManager->refresh();
return true;
