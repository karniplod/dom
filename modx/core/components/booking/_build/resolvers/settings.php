<?php
/**
 * Резолвер: системные настройки booking.* (значения не перезаписываются при обновлении),
 * право booking_admin в шаблоне политик администратора и в политике Administrator,
 * пункт меню «Пакеты → Бронирование».
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */
use MODX\Revolution\modAccessPermission;
use MODX\Revolution\modAccessPolicy;
use MODX\Revolution\modAccessPolicyTemplate;
use MODX\Revolution\modMenu;
use MODX\Revolution\modSystemSetting;
use xPDO\Transport\xPDOTransport;

$modx = $transport->xpdo;
$action = $options[xPDOTransport::PACKAGE_ACTION];

if ($action === xPDOTransport::ACTION_UNINSTALL) {
    if ($menu = $modx->getObject(modMenu::class, ['text' => 'booking'])) {
        $menu->remove();
    }
    $modx->removeCollection(modSystemSetting::class, ['namespace' => 'booking']);
    return true;
}

$settings = [
    'booking.admin_email' => ['', 'textfield'],
    'booking.vk_url' => ['https://vk.com/russianbath59', 'textfield'],
    'booking.request_link_page' => ['0', 'numberfield'],
    'booking.form_page' => ['0', 'numberfield'],
    'booking.timezone' => ['Asia/Yekaterinburg', 'textfield'],   // Пермь (UTC+5): «сегодня», прошедшие даты, время заявок
    'booking.rate_limit' => ['30', 'numberfield'],
    'booking.bot_enabled' => ['0', 'combo-boolean'],   // бот MAX отвечает гостям на вопросы (токен — maxnotify3.max_token)
];
foreach ($settings as $key => [$value, $xtype]) {
    if ($modx->getObject(modSystemSetting::class, ['key' => $key])) {
        continue;
    }
    $s = $modx->newObject(modSystemSetting::class);
    $s->fromArray(['key' => $key, 'value' => $value, 'xtype' => $xtype, 'namespace' => 'booking', 'area' => 'booking'], '', true);
    $s->save();
}
// часовой пояс версии 1.0 по умолчанию был московский — Дома в Перми (UTC+5): нетронутое значение исправляем
$tz = $modx->getObject(modSystemSetting::class, ['key' => 'booking.timezone']);
if ($tz && $tz->get('value') === 'Europe/Moscow') {
    $tz->set('value', 'Asia/Yekaterinburg');
    $tz->save();
}
// скидка на сайте отменена (решение владельца 22.10.2026) — прежняя настройка больше не нужна
$modx->removeCollection(modSystemSetting::class, ['key' => 'booking.site_discount']);
// акция «−5% через ВКонтакте» отменена (решение владельца 27.10.2026) — группа ВКонтакте осталась
$modx->removeCollection(modSystemSetting::class, ['key' => 'booking.vk_discount']);

// право booking_admin: в шаблоне политик администратора и включено в политике Administrator
$tpl = $modx->getObject(modAccessPolicyTemplate::class, ['name' => 'AdministratorTemplate']);
if ($tpl && !$modx->getObject(modAccessPermission::class, ['template' => $tpl->get('id'), 'name' => 'booking_admin'])) {
    $p = $modx->newObject(modAccessPermission::class);
    $p->fromArray(['template' => $tpl->get('id'), 'name' => 'booking_admin', 'description' => 'booking:booking_admin', 'value' => true]);
    $p->save();
}
$policy = $modx->getObject(modAccessPolicy::class, ['name' => 'Administrator']);
if ($policy) {
    $data = $policy->get('data');
    $data = is_array($data) ? $data : (json_decode((string)$data, true) ?: []);
    if (empty($data['booking_admin'])) {
        $data['booking_admin'] = true;
        $policy->set('data', $data);
        $policy->save();
    }
}

// меню
$menu = $modx->getObject(modMenu::class, ['text' => 'booking']) ?: $modx->newObject(modMenu::class);
$menu->fromArray([
    'text' => 'booking', 'parent' => 'components', 'action' => 'index', 'namespace' => 'booking',
    'description' => 'booking.menu_desc', 'icon' => '', 'menuindex' => 0, 'params' => '', 'handler' => '',
    'permissions' => 'booking_admin',
], '', true, true);
$menu->save();

$modx->cacheManager->flushPermissions();
$modx->cacheManager->refresh(['menu' => [], 'system_settings' => []]);
return true;
