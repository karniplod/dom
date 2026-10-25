<?php
/**
 * Сборка транспортного пакета booking-<версия>.transport.zip для «Пакеты → Установщик».
 * Запуск под локальным MODX: php _build/build.transport.php [путь к config.core.php] [--install]
 *  --install — сразу поставить собранный пакет на этот MODX (стенд разработки).
 * Исходники — modx/core/components/booking и modx/assets/components/booking (ст. 7.1); _build в пакет не входит.
 */
use MODX\Revolution\modCategory;
use MODX\Revolution\modChunk;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modX;
use MODX\Revolution\Transport\modPackageBuilder;
use MODX\Revolution\Transport\modTransportPackage;
use MODX\Revolution\Transport\modTransportProvider;
use xPDO\Transport\xPDOTransport;

$t0 = microtime(true);
$args = array_slice($argv, 1);
$install = in_array('--install', $args, true);
$args = array_values(array_diff($args, ['--install']));
$configCore = $args[0] ?? 'C:/cloud/modx-bania/www/config.core.php';

require $configCore;
require MODX_CORE_PATH . 'vendor/autoload.php';
$_SESSION = [];
$modx = new modX();
$modx->initialize('mgr');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');

const PKG = 'booking';
const PKG_VERSION = '1.2.4';
const PKG_RELEASE = 'pl';

$root = dirname(__DIR__, 4) . '/';                       // modx/
$core = $root . 'core/components/booking/';
$assets = $root . 'assets/components/booking/';

// ---------------------------------------------------------------- копия исходников без _build
$stage = sys_get_temp_dir() . '/booking-stage-' . getmypid() . '/';
$copy = function ($src, $dst, array $skip = []) use (&$copy) {
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..' || in_array($f, $skip, true)) {
            continue;
        }
        is_dir($src . $f) ? $copy($src . $f . '/', $dst . $f . '/') : copy($src . $f, $dst . $f);
    }
};
$copy($core, $stage . 'core/booking/', ['_build']);
$copy($assets, $stage . 'assets/booking/');

// ---------------------------------------------------------------- пакет
$builder = new modPackageBuilder($modx);
$builder->directory = MODX_CORE_PATH . 'packages/';
$builder->createPackage(PKG, PKG_VERSION, PKG_RELEASE);
$builder->registerNamespace(PKG, false, true, '{core_path}components/' . PKG . '/', '{assets_path}components/' . PKG . '/');

$code = function ($file) {
    $c = file_get_contents($file);
    $c = preg_replace('~^\s*<\?php\s*~', '', $c);
    return rtrim(preg_replace('~\?>\s*$~', '', $c));
};

$category = $modx->newObject(modCategory::class);
$category->set('category', 'Бронирование');

$snippets = [];
foreach (glob($core . 'elements/snippets/*.php') as $f) {
    $name = basename($f, '.php');
    preg_match('~/\*\*\s*\n\s*\*\s*(.+?)\n~', file_get_contents($f), $m);
    $s = $modx->newObject(modSnippet::class);
    $s->fromArray(['name' => $name, 'description' => mb_substr(trim($m[1] ?? ''), 0, 255), 'snippet' => $code($f)], '', true, true);
    $snippets[] = $s;
}
$chunks = [];
foreach (glob($core . 'elements/chunks/*.tpl') as $f) {
    // имена из контракта: booking.form.tpl, booking.prices.tpl…; письма — booking.email.request, booking.email.cancel
    $name = strpos(basename($f), 'booking.email.') === 0 ? basename($f, '.tpl') : basename($f);
    $c = $modx->newObject(modChunk::class);
    $c->fromArray(['name' => $name, 'description' => 'Бронирование', 'snippet' => file_get_contents($f)], '', true, true);
    $chunks[] = $c;
}
$templates = [];
foreach (glob($core . 'elements/templates/*.tpl') as $f) {
    $t = $modx->newObject(modTemplate::class);
    $t->fromArray(['templatename' => basename($f, '.tpl'), 'description' => 'Приложение бронирования (PWA, мини-приложение MAX)', 'content' => file_get_contents($f)], '', true, true);
    $templates[] = $t;
}
$category->addMany($snippets, 'Snippets');
$category->addMany($chunks, 'Chunks');
$category->addMany($templates, 'Templates');

$vehicle = $builder->createVehicle($category, [
    xPDOTransport::UNIQUE_KEY => 'category',
    xPDOTransport::PRESERVE_KEYS => false,
    xPDOTransport::UPDATE_OBJECT => true,
    xPDOTransport::RELATED_OBJECTS => true,
    xPDOTransport::RELATED_OBJECT_ATTRIBUTES => [
        'Snippets' => [xPDOTransport::PRESERVE_KEYS => false, xPDOTransport::UPDATE_OBJECT => true, xPDOTransport::UNIQUE_KEY => 'name'],
        'Chunks' => [xPDOTransport::PRESERVE_KEYS => false, xPDOTransport::UPDATE_OBJECT => true, xPDOTransport::UNIQUE_KEY => 'name'],
        'Templates' => [xPDOTransport::PRESERVE_KEYS => false, xPDOTransport::UPDATE_OBJECT => true, xPDOTransport::UNIQUE_KEY => 'templatename'],
    ],
]);
// до копирования файлов: права на запись у прежних файлов (иначе обновление падает на каждом файле)
$vehicle->validate('php', ['source' => __DIR__ . '/validators/permissions.php']);
// файлы: каталог booking копируется в components/ (имя каталога в копии — booking)
$vehicle->resolve('file', ['source' => $stage . 'core/booking', 'target' => "return MODX_CORE_PATH . 'components/';"]);
$vehicle->resolve('file', ['source' => $stage . 'assets/booking', 'target' => "return MODX_ASSETS_PATH . 'components/';"]);
foreach (['tables', 'seed', 'settings', 'resources'] as $r) {
    $vehicle->resolve('php', ['source' => __DIR__ . '/resolvers/' . $r . '.php']);
}
$builder->putVehicle($vehicle);

$docs = $core . 'docs/';
$builder->setPackageAttributes([
    'license' => is_file($docs . 'license.txt') ? file_get_contents($docs . 'license.txt') : 'Proprietary',
    'readme' => is_file($docs . 'README.md') ? file_get_contents($docs . 'README.md') : '',
    'changelog' => is_file($docs . 'changelog.txt') ? file_get_contents($docs . 'changelog.txt') : '',
]);
$builder->pack();

// убрать временную копию
$rm = function ($d) use (&$rm) {
    foreach (array_diff(scandir($d), ['.', '..']) as $f) {
        is_dir("$d/$f") ? $rm("$d/$f") : unlink("$d/$f");
    }
    rmdir($d);
};
$rm(rtrim($stage, '/'));

$signature = PKG . '-' . PKG_VERSION . '-' . PKG_RELEASE;
$zip = MODX_CORE_PATH . 'packages/' . $signature . '.transport.zip';
printf("Пакет: %s (%.1f КБ) за %.1f с\n", $zip, filesize($zip) / 1024, microtime(true) - $t0);
// копия в dist/ проекта — этот файл загружают в «Пакеты → Установщик» на рабочем сайте
$dist = dirname(__DIR__, 5) . '/dist/';
if (is_dir($dist)) {
    foreach (glob($dist . PKG . '-*.transport.zip') ?: [] as $old) @unlink($old);
    copy($zip, $dist . $signature . '.transport.zip');
    echo 'Для сайта: ' . realpath($dist . $signature . '.transport.zip') . "\n";
}

// ---------------------------------------------------------------- установка на этот MODX (стенд)
if ($install) {
    $modx->setLogLevel(modX::LOG_LEVEL_ERROR);
    $pkg = $modx->getObject(modTransportPackage::class, ['signature' => $signature]);
    if ($pkg) {
        // переустановка той же версии: запись пакета заменяется, данные и настройки не трогаются
        $pkg->remove();
        @array_map('unlink', glob(MODX_CORE_PATH . 'packages/' . $signature . '/*') ?: []);
    }
    $rmdir = MODX_CORE_PATH . 'packages/' . $signature;
    if (is_dir($rmdir)) {
        $rm($rmdir);
    }
    $provider = $modx->getObject(modTransportProvider::class, ['name' => 'modx.com']);
    $pkg = $modx->newObject(modTransportPackage::class);
    $pkg->set('signature', $signature);
    $pkg->fromArray([
        'created' => date('Y-m-d H:i:s'), 'updated' => null, 'state' => 1, 'workspace' => 1,
        'provider' => $provider ? $provider->get('id') : 0, 'source' => $signature . '.transport.zip', 'package_name' => PKG,
        'version_major' => 1, 'version_minor' => 2, 'version_patch' => 4, 'release' => PKG_RELEASE,
    ]);
    $pkg->save();
    $ok = $pkg->install();
    $modx->cacheManager->refresh();
    echo $ok ? "Установлен на стенд\n" : "ОШИБКА установки\n";
    exit($ok ? 0 : 1);
}
