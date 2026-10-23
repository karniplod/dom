<?php
/**
 * Установщик сайта «Доходные дома Ефремовых» для MODX 3 — основной код. Собирается tools/modx_build.py из tools/modx/bn-install.inc.php.tpl — руками не править.
 * Запускается через bn-install.php (он проверяет версию PHP), напрямую этот файл не открывать.
 *
 * Что делает (всё повторяемо: второй запуск ничего не задублирует):
 *   1. ставит дополнения ClientConfig и TinyMCE (визуальный редактор статей), если их нет;
 *   2. копирует файлы сайта из папки site/ в корень (assets/css, js, img — стили, скрипт, фото, QR-коды);
 *   3. кладёт библиотеку сайта в core/components/bania/, создаёт пространство имён и пункт меню «Заявки»,
 *      таблицы заявок (bania_leads) и занятых ночей (bania_nights) — основа будущего онлайн-бронирования;
 *   4. создаёт категорию «Доходные дома» с шаблонами, чанками, сниппетами и полями (TV);
 *   5. настройки: контакты, реквизиты, условия — в ClientConfig («Конфигурация»), без него — в системные настройки bn_*;
 *   6. страницы: главная, три дома со страницами предоплаты (/Предоплата1/ …), контакты, блог со статьями, раздел «Отзывы»,
 *      приём заявок, robots.txt, sitemap.xml, rss.xml, llms.txt;
 *   7. человекопонятные адреса, .htaccess с заголовками безопасности; в конце — самопроверка сайта.
 *
 * Ключи (консоль / браузер):
 *   --dry / ?dry=1               пробный прогон: проверки и список действий, база и файлы не меняются
 *   --update / ?update=1         перезаписать уже созданные страницы (тексты, поля домов и статей); без ключа их не трогаем
 *   --no-extras / ?noextras=1    не ставить дополнения
 *   --no-files / ?nofiles=1      не копировать файлы сайта из site/
 *   --no-tune / ?notune=1        не трогать настройки MODX (адреса, кэш, .htaccess)
 *   --no-clientconfig / ?nocc=1  контакты — в системные настройки, даже если установлен ClientConfig
 *   --static / ?static=1         элементы читают код из файлов этой папки (папку тогда не удалять)
 *   --force / ?force=1           продолжить, несмотря на конфликты имён; перезаписать и элементы, изменённые в админке
 *   --check / ?check=1           только проверить уже установленный сайт (ничего не меняет)
 *   --root=путь                  корень сайта, если скрипт не находит MODX сам
 *
 * Обновление: загрузите новый архив и запустите установщик снова. Обновится только изменённое. Если чанк, шаблон или сниппет
 * поменяли в админке после прошлой установки — он НЕ перезаписывается (ваш вариант остаётся), новая версия кладётся в резервную копию.
 * Что ставил установщик, он помнит в системной настройке bn_install_state. Заявки и брони при обновлении не трогаются.
 *
 * Безопасность: проверки до любых изменений; перед перезаписью — резервная копия в core/cache/bn-install-backup/<дата>/;
 * ошибка одного шага не останавливает остальные; журнал — core/cache/logs/bn-install-<дата>.log.
 * В консоли код выхода: 0 — без ошибок, 1 — были ошибки, 2 — проверки не пройдены.
 */
@set_time_limit(900);
@ini_set('memory_limit', '512M');
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $msg = "Установка прервана ошибкой PHP: {$e['message']} ({$e['file']}:{$e['line']}).\nУже сделанное сохранено; повторный запуск ничего не задублирует.";
    if (PHP_SAPI === 'cli') fwrite(STDERR, $msg . "\n"); else echo '<pre style="color:#b3261e">' . htmlspecialchars($msg) . '</pre>';
});
$cli = PHP_SAPI === 'cli';
$args = $argv ?? [];
$opt = function ($cliKey, $getKey) use ($cli, $args) { return $cli ? in_array($cliKey, $args, true) : !empty($_GET[$getKey]); };
$dry = $opt('--dry', 'dry');
$update = $opt('--update', 'update');
$noExtras = $opt('--no-extras', 'noextras');
$noFiles = $opt('--no-files', 'nofiles');
$noTune = $opt('--no-tune', 'notune');
$noCc = $opt('--no-clientconfig', 'nocc');
$static = $opt('--static', 'static');
$force = $opt('--force', 'force');
$checkOnly = $opt('--check', 'check');
$dir = __DIR__;
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { echo "Запускайте bn-install.php, а не этот файл.\n"; exit(2); }
$stamp = date('Ymd-His');

function page($title, $body, $color = '#2A1A14') {
    return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . '</title>'
        . '<body style="font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;background:#F4EBDF;color:' . $color . ';padding:24px;max-width:980px;margin:0 auto">'
        . '<h1 style="font-size:22px;line-height:1.3">' . htmlspecialchars($title) . '</h1>' . $body;
}
function stop($msg, $code = 2) {   // ошибка до начала изменений
    global $cli;
    if ($cli) { fwrite(STDERR, "ОСТАНОВЛЕНО: $msg\n"); exit($code); }
    http_response_code(500);
    exit(page('Установка остановлена', '<p style="color:#b3261e">' . nl2br(htmlspecialchars($msg)) . '</p><p>База и файлы не изменялись.</p>'));
}

// --- 1. окружение: PHP и MODX 3
if (PHP_VERSION_ID < 70400) stop('Нужен PHP 7.4 или новее (для MODX 3 — 8.1+). Сейчас: ' . PHP_VERSION . '. Поменяйте версию PHP в панели хостинга.');
foreach (['json', 'mbstring'] as $ext) if (!extension_loaded($ext)) stop("Не включено расширение PHP «{$ext}». Включите его в настройках PHP хостинга.");

$cfg = null;
foreach ($args as $a) if (strpos($a, '--root=') === 0) $cfg = rtrim(substr($a, 7), '/\\') . '/config.core.php';
for ($d = $dir, $i = 0; !$cfg && $i < 8; $d = dirname($d), $i++) { if (is_file($d . '/config.core.php')) $cfg = $d . '/config.core.php'; }
if (!$cfg || !is_file($cfg)) stop("Не найден config.core.php — похоже, MODX здесь не установлен.\nПоложите папку установщика в корень сайта с MODX (рядом с index.php) или укажите --root=путь_к_корню_сайта.");
require_once $cfg;
if (!defined('MODX_CORE_PATH')) stop('config.core.php не задаёт MODX_CORE_PATH — это не файл MODX.');
if (!is_file(MODX_CORE_PATH . 'vendor/autoload.php')) stop('Не найден core/vendor/autoload.php. Похоже, это MODX 2.x — установщик рассчитан на MODX 3.');
require_once MODX_CORE_PATH . 'vendor/autoload.php';
if (!class_exists('MODX\Revolution\modX')) stop('Нет класса MODX\Revolution\modX. Установщик рассчитан на MODX 3.');

use xPDO\xPDO;
use MODX\Revolution\modX;
use MODX\Revolution\modCategory;
use MODX\Revolution\modChunk;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarTemplate;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modPluginEvent;
use MODX\Revolution\modResource;
use MODX\Revolution\modDocument;
use MODX\Revolution\modWebLink;
use MODX\Revolution\modContentType;
use MODX\Revolution\Transport\modTransportProvider;
use MODX\Revolution\Transport\modTransportPackage;

if ($cli && !isset($_SESSION)) $_SESSION = [];   // поставщикам дополнений MODX нужна сессия, в консоли её нет
try {
    $modx = new modX();
    $modx->initialize($cli ? 'web' : 'mgr');
} catch (Throwable $e) {
    stop('MODX не запустился: ' . $e->getMessage());
}
if (!$cli && !($modx->user && $modx->user->isAuthenticated('mgr') && $modx->user->get('sudo'))) {
    http_response_code(403);
    exit(page('Нужен вход в админку', '<p>Войдите в админку MODX под администратором: <a href="' . htmlspecialchars(MODX_MANAGER_URL) . '" target="_blank">' . htmlspecialchars(MODX_MANAGER_URL)
        . '</a>, затем обновите эту страницу.</p>'));
}
$ver = method_exists($modx, 'getVersionData') ? $modx->getVersionData() : [];
if ((int)($ver['version'] ?? 3) < 3) stop('Это MODX ' . ($ver['full_version'] ?? '2.x') . '. Установщик рассчитан на MODX 3.');

// --- удалить папку установщика (кнопка в конце отчёта, только из браузера)
if (!$cli && !empty($_GET['remove'])) {
    $removed = true;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { if (!($f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()))) $removed = false; }
    $emptyLeft = false;
    if (!@rmdir($dir)) { if ($removed) $emptyLeft = true; $removed = false; }   // файлы удалены, а папку держит сервер (бывает на Windows)
    if ($emptyLeft) exit(page('Файлы установщика удалены', '<p>Осталась пустая папка ' . htmlspecialchars(basename($dir)) . ' — удалите её файловым менеджером, когда будет удобно. Сайт: <a href="'
        . htmlspecialchars($modx->getOption('site_url')) . '">' . htmlspecialchars($modx->getOption('site_url')) . '</a></p>'));
    exit(page($removed ? 'Папка установщика удалена' : 'Папку удалить не получилось',
        '<p>' . ($removed ? 'Готово. Сайт: <a href="' . htmlspecialchars($modx->getOption('site_url')) . '">' . htmlspecialchars($modx->getOption('site_url')) . '</a>' : 'Удалите папку ' . htmlspecialchars(basename($dir)) . ' файловым менеджером хостинга.') . '</p>'));
}

// --- журнал
$log = [];
function say($kind, $text) { global $log; $log[] = [$kind, $text]; }
$logDir = MODX_CORE_PATH . 'cache/logs/';
$logFile = $logDir . "bn-install-$stamp.log";

// --- 2. описание сайта и файлы установщика
$manifest = <<<'JSON'
%%MANIFEST%%
JSON;
$M = json_decode($manifest, true);
if (!is_array($M)) stop('Описание сайта внутри установщика повреждено (' . json_last_error_msg() . '). Загрузите bn-install.inc.php заново.');

if ($checkOnly) {   // ?check=1 — только самопроверка, без установки
    $norm0 = function ($p) { $r = realpath($p); return rtrim(str_replace('\\', '/', $r ?: $p), '/') . '/'; };
    $inside = stripos($norm0($dir), $norm0(MODX_BASE_PATH)) === 0; $static = false;
    $staticPrefix = $inside ? substr($norm0($dir), strlen($norm0(MODX_BASE_PATH))) : '';
    selfCheck();
    report(true);
}

$problems = [];   // не пройдено — ничего не меняем
$warnings = [];   // можно продолжать

foreach (['chunks', 'templates', 'snippets'] as $group) foreach ($M[$group] as $e) {
    $p = $dir . '/' . $e['file'];
    if (!is_file($p)) $problems[] = "нет файла установщика: {$e['file']} — загрузите папку целиком";
    elseif (filesize($p) === 0) $problems[] = "файл установщика пустой (оборвалась загрузка?): {$e['file']}";
}
$R = json_decode((string)@file_get_contents($dir . '/resources.json'), true);
if (!is_array($R)) $problems[] = 'нет или повреждён resources.json — загрузите папку установщика заново';
$siteDir = $dir . '/site';
if (!is_file($dir . '/component/Bania.php')) $problems[] = 'нет файла component/Bania.php — загрузите папку установщика целиком';
$hasSite = is_dir($siteDir);
if (!$hasSite && !$noFiles) $warnings[] = 'рядом нет папки site/ с файлами сайта — стили, скрипты и картинки не скопируются (загрузите архив целиком или скопируйте файлы вручную)';

// --- 3. права на запись: журнал, резервная копия, js/
$backupDir = MODX_CORE_PATH . "cache/bn-install-backup/$stamp/";
if (!$dry) {
    foreach ([$logDir, dirname(rtrim($backupDir, '/'))] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
        if (!is_dir($d) || !is_writable($d)) $problems[] = 'нет прав на запись в ' . str_replace(MODX_CORE_PATH, 'core/', $d) . ' — проверьте права на папку core/cache/';
    }
    if (!$noFiles && !is_writable(MODX_BASE_PATH)) $problems[] = 'нет прав на запись в корень сайта — файлы сайта не скопировать';
}

// --- 4. конфликты имён: элемент с тем же именем в чужой категории — не наш, без --force не перезаписываем
$ourCats = [];
$top = $modx->getObject(modCategory::class, ['category' => $M['top'], 'parent' => 0]);
if ($top) {
    $ourCats[] = (int)$top->get('id');
    foreach ($modx->getIterator(modCategory::class, ['parent' => $top->get('id')]) as $c) $ourCats[] = (int)$c->get('id');
}
$conflicts = [];
$checkName = function ($class, $field, $name, $label) use ($modx, &$conflicts, $ourCats) {
    $o = $modx->getObject($class, [$field => $name]);
    if ($o && (int)$o->get('category') !== 0 && !in_array((int)$o->get('category'), $ourCats, true)) $conflicts[] = "$label «{$name}»";
};
foreach ($M['chunks'] as $e) $checkName(modChunk::class, 'name', $e['name'], 'чанк');
foreach ($M['templates'] as $e) $checkName(modTemplate::class, 'templatename', $e['name'], 'шаблон');
foreach ($M['snippets'] as $e) $checkName(modSnippet::class, 'name', $e['name'], 'сниппет');
foreach ($M['plugins'] as $e) $checkName(modPlugin::class, 'name', $e['name'], 'плагин');
foreach ($M['tvs'] as $e) $checkName(modTemplateVar::class, 'name', $e['name'], 'поле (TV)');
if ($conflicts) {
    $msg = 'в MODX уже есть элементы с такими же именами в чужих категориях: ' . implode(', ', $conflicts);
    if ($force) $warnings[] = $msg . ' — перезаписываю (--force), старые версии будут в резервной копии';
    else $problems[] = $msg . '. Переименуйте их или запустите с --force (старые версии сохранятся в резервную копию)';
}

// --- резервная копия и сохранение
$backedUp = 0;
function backup($type, $name, $data) {
    global $dry, $backupDir, $backedUp;
    if ($dry) return;
    $safe = preg_replace('~[^\w.\-]+~u', '_', $name);
    $d = $backupDir . $type . '/';
    if (!is_dir($d) && !@mkdir($d, 0755, true)) { say('error', "не удалось создать папку резервной копии $d"); return; }
    $ext = is_string($data) ? (in_array(basename($type), ['snippets', 'plugins'], true) ? '.php' : ($type === 'files' ? '' : '.html')) : '.json';
    $text = is_string($data) ? (in_array(basename($type), ['snippets', 'plugins'], true) ? "<?php\n" . $data : $data) : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($d . $safe . $ext, $text) === false) { say('error', "не удалось сохранить резервную копию: $type/$name"); return; }
    $backedUp++;
}
function saved($o, $what) {   // save() с проверкой результата
    global $dry;
    if ($dry) return true;
    try {
        if ($o->save()) return true;
        say('error', "MODX не сохранил $what (проверьте журнал ошибок MODX)");
    } catch (Throwable $e) {
        say('error', "ошибка при сохранении $what: " . $e->getMessage());
    }
    return false;
}
function setting($key, $value, $area = 'Доходные дома') {   // системная настройка: создать или поменять значение
    global $modx;
    $s = $modx->getObject(modSystemSetting::class, $key);
    $old = $s ? (string)$s->get('value') : null;
    if ($old === (string)$value) return false;
    if (!$s) { $s = $modx->newObject(modSystemSetting::class); $s->fromArray(['key' => $key, 'xtype' => 'textfield', 'namespace' => 'core', 'area' => $area], '', true); }
    else backup('settings', $key, $s->toArray());
    $s->set('value', (string)$value);
    return saved($s, "настройку $key") ? ($old === null ? 'new' : 'upd') : false;
}

// --- 5. дополнения: ставим недостающие из modx.com и modstore.pro
$snippetExists = function ($name) use ($modx) { return (bool)$modx->getObject(modSnippet::class, ['name' => $name]); };
$extras = [   // имя у поставщика → [адрес поставщика, начало подписи пакета, признак установки]
    'ClientConfig' => ['https://rest.modx.com/extras/', 'clientconfig', function () { return is_dir(MODX_CORE_PATH . 'components/clientconfig/'); }],
    'TinyMCE Rich Text Editor' => ['https://rest.modx.com/extras/', 'tinymcerte', function () { return is_dir(MODX_CORE_PATH . 'components/tinymcerte/'); }],
];
$missing = [];
foreach ($extras as $name => [$url, $sigPrefix, $has]) if (!$has()) $missing[] = $name;
if ($missing && $noExtras) foreach ($missing as $name) $warnings[] = "не установлено дополнение $name (--no-extras) — установите его в «Пакеты → Установщик»";

foreach ($warnings as $w) say('warn', $w);
if ($problems) {
    foreach ($problems as $p) say('error', $p);
    if (!$dry) { report(false); stop("Проверки не пройдены, база и файлы не изменялись:\n• " . implode("\n• ", $problems)); }
}

function provider($name, $url) {
    global $modx, $dry;
    $p = $modx->getObject(modTransportProvider::class, ['service_url' => $url]);
    if ($p || $dry) return $p;
    $p = $modx->newObject(modTransportProvider::class);
    $p->fromArray(['name' => $name, 'service_url' => $url, 'description' => '', 'username' => '', 'api_key' => '']);
    if (!saved($p, "поставщика $name")) return null;
    say('new', "поставщик дополнений $name");
    return $p;
}
function bestSignature($prov, $name, $prefix) {   // самая новая стабильная версия пакета у поставщика
    $list = [];
    $v = $prov->latest($prefix);
    if (is_array($v)) foreach ($v as $n) if (stripos($n['signature'] ?? '', $prefix . '-') === 0) $list[] = $n['signature'];
    if (!$list) {
        $f = $prov->find(['query' => $name, 'limit' => 20]);
        if (is_array($f) && isset($f[1])) foreach ($f[1] as $n) if (stripos($n['signature'] ?? '', $prefix . '-') === 0) $list[] = $n['signature'];
    }
    $stable = array_values(array_filter($list, function ($s) { return preg_match('~-pl\d*$~', $s); }));
    if ($stable) $list = $stable;
    usort($list, function ($a, $b) { return version_compare(preg_replace('~^[^-]+-~', '', $b), preg_replace('~^[^-]+-~', '', $a)); });
    return $list[0] ?? null;
}
if ($missing && !$noExtras) {
    foreach ($missing as $name) {
        [$url, $sigPrefix] = $extras[$name];
        if ($dry) { say('new', "дополнение $name — скачаю и установлю (" . parse_url($url, PHP_URL_HOST) . ')'); continue; }
        try {
            $prov = provider('modx.com', $url);
            if (!$prov) { say('error', "дополнение $name: не создался поставщик"); continue; }
            $sig = bestSignature($prov, $name, $sigPrefix);
            if (!$sig) { say('error', "дополнение $name: не нашёл у " . $prov->get('name') . ' — нет связи с сайтом поставщика? Установите вручную: «Пакеты → Установщик»'); continue; }
            $pkg = $modx->getObject(modTransportPackage::class, ['signature' => $sig]) ?: $prov->transfer($sig);
            if (!$pkg) { say('error', "дополнение $name: не скачался пакет $sig"); continue; }
            if (!$pkg->get('installed') && !$pkg->install()) { say('error', "дополнение $name: ошибка установки $sig — смотрите журнал ошибок MODX"); continue; }
            say('new', "установлено дополнение $name ($sig)");
        } catch (Throwable $e) { say('error', "дополнение $name: " . $e->getMessage()); }
    }
    $modx->cacheManager->refresh();
}

// ClientConfig: контакты и цены — в его группы, остальное — системные настройки
$cc = false;
if (!$noCc && is_dir(MODX_CORE_PATH . 'components/clientconfig/')) {
    try {
        $modx->addPackage('clientconfig', MODX_CORE_PATH . 'components/clientconfig/model/');
        $cc = (bool)$modx->loadClass('cgSetting') && (bool)$modx->loadClass('cgGroup');
    } catch (Throwable $e) { $cc = false; }
    if (!$cc) say('warn', 'ClientConfig найден, но его модель не загрузилась — контакты запишу в системные настройки bn_*');
}

// --- что ставил установщик в прошлый раз (контрольные суммы элементов) и версия пакета
$stateSetting = $modx->getObject(modSystemSetting::class, 'bn_install_state');
$state = $stateSetting ? (array)json_decode((string)$stateSetting->get('value'), true) : [];
$prevVersion = (string)($state['_version'] ?? '');

// --- категории и элементы
$catIds = [];
function category($name, $parent = 0) {
    global $modx, $dry, $catIds;
    $key = $parent . '/' . $name;
    if (isset($catIds[$key])) return $catIds[$key];
    $c = $modx->getObject(modCategory::class, ['category' => $name, 'parent' => $parent]);
    if (!$c) {
        say('new', "категория «{$name}»");
        if ($dry) return $catIds[$key] = 0;
        $c = $modx->newObject(modCategory::class);
        $c->fromArray(['category' => $name, 'parent' => $parent]);
        if (!saved($c, "категорию «{$name}»")) return $catIds[$key] = 0;
    }
    return $catIds[$key] = (int)$c->get('id');
}
$norm = function ($p) { $r = realpath($p); return rtrim(str_replace('\\', '/', $r ?: $p), '/') . '/'; };
$base = $norm(MODX_BASE_PATH);
$here = $norm($dir);
$inside = DIRECTORY_SEPARATOR === '\\' ? stripos($here, $base) === 0 : strpos($here, $base) === 0;
if ($inside) { $staticSource = (int)$modx->getOption('default_media_source', null, 1); $staticPrefix = substr($here, strlen($base)); }
else { $staticSource = 0; $staticPrefix = $here; }
$typeDir = [modChunk::class => 'chunks', modTemplate::class => 'templates', modSnippet::class => 'snippets', modPlugin::class => 'plugins'];
function element($class, $nameField, $name, $content, $catId, $desc, $file) {
    global $modx, $static, $staticSource, $staticPrefix, $typeDir, $state, $force, $dry;
    try {
        if (trim((string)$content) === '') { say('error', "$name: файл $file пустой или не читается — элемент не создан"); return null; }
        $o = $modx->getObject($class, [$nameField => $name]);
        $isNew = !$o;
        $key = $typeDir[$class] . '/' . $name;
        if ($isNew) $o = $modx->newObject($class);
        else {
            $cur = (string)$o->getContent();
            if ($cur === (string)$content && (int)$o->get('category') === (int)$catId && (bool)$o->get('static') === (bool)$static) {
                $state[$key] = md5($cur); say('skip', "без изменений: $name"); return $o;
            }
            // правили в админке после прошлой установки (не то, что ставил установщик) — не трогаем, если не --force
            if (isset($state[$key]) && $state[$key] !== md5($cur) && !$force) {
                if (!$dry) backup('new-versions/' . $typeDir[$class], $name, (string)$content);
                say('warn', "$name изменён в админке — оставил ваш вариант; новая версия из архива — в резервной копии (new-versions). Перезаписать: ?force=1");
                return $o;
            }
            backup($typeDir[$class], $name, $cur);
        }
        $state[$key] = md5((string)$content);
        $data = [$nameField => $name, 'description' => $desc, 'category' => $catId];
        $data += $static ? ['static' => 1, 'source' => $staticSource, 'static_file' => $staticPrefix . $file] : ['static' => 0, 'static_file' => ''];
        $o->fromArray($data);
        $o->setContent($content);
        if (saved($o, $name)) say($isNew ? 'new' : 'upd', ($isNew ? 'создан' : 'обновлён') . ": $name");
        return $o;
    } catch (Throwable $e) {
        say('error', "$name: " . $e->getMessage());
        return null;
    }
}
function body($rel) { global $dir; return rtrim((string)@file_get_contents($dir . '/' . $rel), "\n"); }
function code($rel) { return preg_replace('~^\s*<\?php\s*~', '', body($rel)); }

try {
    // --- 6. файлы сайта: site/ → корень (только изменившиеся; перезаписанные — в резервную копию)
    if ($hasSite && !$noFiles) {
        $copied = 0; $same = 0; $failed = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($siteDir) + 1));
            $target = MODX_BASE_PATH . $rel;
                        if (is_file($target) && filesize($target) === $f->getSize() && md5_file($target) === md5_file($f->getPathname())) { $same++; continue; }
            if ($dry) { $copied++; continue; }
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) { $failed++; say('error', "не создать папку для $rel"); continue; }
            if (is_file($target) && filesize($target) < 2 * 1024 * 1024) backup('files', str_replace('/', '__', $rel), (string)file_get_contents($target));
            if (@copy($f->getPathname(), $target)) $copied++; else { $failed++; say('error', "не скопировался файл $rel"); }
        }
        say($copied ? 'upd' : 'skip', ($dry ? 'скопирую' : 'скопировано') . " файлов сайта: $copied, без изменений: $same" . ($failed ? ", ошибок: $failed" : ''));
    }

    $top = category($M['top']);
    $sub = function ($name) use ($top) { return category($name, $top); };
    if ($static) say('upd', 'статичные элементы читают код из папки: ' . ($staticSource ? '/' : '') . $staticPrefix);

    // --- 7. элементы
    foreach ($M['chunks'] as $c) element(modChunk::class, 'name', $c['name'], body($c['file']), $sub($c['category']), $c['description'] ?? '', $c['file']);
    $tplIds = [];
    foreach ($M['templates'] as $t) {
        $o = element(modTemplate::class, 'templatename', $t['name'], body($t['file']), $sub('Шаблоны'), $t['description'], $t['file']);
        $tplIds[$t['name']] = ($o && !$dry) ? (int)$o->get('id') : 0;
    }
    foreach ($M['snippets'] as $s) element(modSnippet::class, 'name', $s['name'], code($s['file']), $sub('Сниппеты'), $s['description'], $s['file']);
    // --- 8. поля (TV) и привязка к шаблонам
    foreach ($M['tvs'] as $i => $t) {
        try {
            $o = $modx->getObject(modTemplateVar::class, ['name' => $t['name']]);
            $isNew = !$o;
            if ($isNew) $o = $modx->newObject(modTemplateVar::class);
            else backup('tvs', $t['name'], $o->toArray());
            $o->fromArray(['name' => $t['name'], 'caption' => $t['caption'], 'description' => $t['description'], 'type' => $t['type'],
                           'elements' => $t['elements'], 'default_text' => $t['default'], 'display' => 'default', 'rank' => $i,
                           'category' => $sub($t['category'])]);
            if (!empty($t['input_properties'])) $o->set('input_properties', $t['input_properties']);
            if (!saved($o, "поле {$t['name']}")) continue;
            say($isNew ? 'new' : 'skip', ($isNew ? 'создано' : 'обновлено') . " поле (TV) «{$t['caption']}» {$t['name']} → " . implode(', ', $t['templates']));
            if ($dry) continue;
            // поля «картинка» и «файл» — с источником файлов по умолчанию (как при сохранении в админке);
            // без него MODX при выводе поля пишет в журнал предупреждение «Undefined array key class_key»
            if (in_array($t['type'], ['image', 'file'], true)) {
                $srcId = (int)$modx->getOption('default_media_source', null, 1) ?: 1;
                $el = ['source' => $srcId, 'object_class' => modTemplateVar::class, 'object' => $o->get('id'), 'context_key' => 'web'];
                if (!$modx->getObject(\MODX\Revolution\Sources\modMediaSourceElement::class, ['object_class' => modTemplateVar::class, 'object' => $o->get('id'), 'context_key' => 'web'])) {
                    $se = $modx->newObject(\MODX\Revolution\Sources\modMediaSourceElement::class);
                    $se->fromArray($el, '', true, true);
                    saved($se, "источник файлов поля {$t['name']}");
                }
            }
            foreach ($t['templates'] as $tn) {
                if (empty($tplIds[$tn])) { say('error', "поле {$t['name']}: шаблон $tn не создан — не привязано"); continue; }
                $link = ['tmplvarid' => $o->get('id'), 'templateid' => $tplIds[$tn]];
                if ($modx->getObject(modTemplateVarTemplate::class, $link)) continue;
                $l = $modx->newObject(modTemplateVarTemplate::class);
                $l->fromArray($link + ['rank' => $i], '', true);
                saved($l, "привязку {$t['name']} к $tn");
            }
        } catch (Throwable $e) { say('error', "поле {$t['name']}: " . $e->getMessage()); }
    }

    // --- 9. настройки: контакты и цены — ClientConfig (только недостающие), остальное — системные
    $ccIds = [];
    if ($cc) foreach ($M['cc_groups'] as $label => $about) {
        try {
            $g = $modx->getObject('cgGroup', ['label' => $label]);
            if ($g) { $ccIds[$label] = (int)$g->get('id'); continue; }
            say('new', "ClientConfig: группа «{$label}»");
            if ($dry) { $ccIds[$label] = 0; continue; }
            $g = $modx->newObject('cgGroup');
            $g->fromArray(['label' => $label, 'description' => $about, 'sortorder' => count($ccIds)]);
            if (saved($g, "группу ClientConfig «{$label}»")) $ccIds[$label] = (int)$g->get('id');
        } catch (Throwable $e) { say('error', "ClientConfig, группа «{$label}»: " . $e->getMessage() . ' — её поля пишу в системные настройки'); }
    }
    $inCc = function ($s) use (&$ccIds) { return !empty($s['cc']) && isset($ccIds[$s['cc']]); };
    $ccSort = [];
    foreach ($M['settings'] as $s) {
        if (!$inCc($s)) continue;
        $grp = $s['cc']; $ccSort[$grp] = isset($ccSort[$grp]) ? $ccSort[$grp] + 1 : 0;
        try {
            if ($modx->getObject('cgSetting', ['key' => $s['key']])) { say('skip', "ClientConfig: {$s['name']} ({$s['key']}) уже есть — не трогаю"); continue; }
            $value = $s['value'];
            $sys = $modx->getObject(modSystemSetting::class, $s['key']);
            if ($sys && (string)$sys->get('value') !== '') $value = (string)$sys->get('value');
            $o = $modx->newObject('cgSetting');
            $o->fromArray(['key' => $s['key'], 'label' => $s['name'], 'description' => $s['help'], 'xtype' => $s['xtype'] ?? 'textfield',
                           'value' => $value, 'default' => '', 'group' => $ccIds[$grp], 'sortorder' => $ccSort[$grp]]);
            if (saved($o, "поле ClientConfig {$s['key']}")) say('new', "ClientConfig «{$grp}»: {$s['name']} = «{$value}»");
        } catch (Throwable $e) { say('error', "ClientConfig {$s['key']}: " . $e->getMessage()); }
    }
    foreach ($M['settings'] as $s) {
        if ($inCc($s)) continue;
        if ($modx->getObject(modSystemSetting::class, $s['key'])) { say('skip', "настройка {$s['key']} уже есть — не трогаю"); continue; }
        $o = $modx->newObject(modSystemSetting::class);
        $o->fromArray(['key' => $s['key'], 'value' => $s['value'], 'xtype' => ($s['xtype'] ?? '') === 'xcheckbox' ? 'combo-boolean' : 'textfield', 'namespace' => 'core', 'area' => 'Доходные дома'], '', true);
        if (saved($o, "настройку {$s['key']}")) say('new', "настройка {$s['key']} = «{$s['value']}»");
        $modx->setOption($s['key'], $s['value']);
    }
    // поля, которые сайт больше не выводит (Viber убран 23.10.2026 по просьбе владельца) — убрать из «Конфигурации» и настроек
    foreach (['bn_viber', 'bn_viber_raw'] as $old) {
        try {
            if ($cc && ($o = $modx->getObject('cgSetting', ['key' => $old]))) {
                say('upd', "ClientConfig: поле {$old} больше не нужно — удаляю");
                if (!$dry) { backup('settings', 'cc:' . $old, $o->toArray()); $o->remove(); }
            }
            if ($o = $modx->getObject(modSystemSetting::class, $old)) {
                say('upd', "настройка {$old} больше не нужна — удаляю");
                if (!$dry) { backup('settings', $old, $o->toArray()); $o->remove(); }
            }
        } catch (Throwable $e) { say('error', "удаление {$old}: " . $e->getMessage()); }
    }
    // ClientConfig держит свой кэш значений: без сброса новые поля не видны на сайте, пока не очистить кэш вручную
    if ($cc && !$dry) { try { $modx->cacheManager->delete('system_settings', [xPDO::OPT_CACHE_KEY => 'clientconfig']); } catch (Throwable $e) {} }
    // значения из ClientConfig — сразу в этот запуск (их ждут сниппеты ниже)
    if ($cc && !$dry) foreach ($modx->getIterator('cgSetting') as $o) $modx->setOption($o->get('key'), $o->get('value'));

    // --- 10. настройки MODX: человекопонятные адреса без .html, кэш, .htaccess (--no-tune — не трогать)
    if (!$noTune) {
        foreach (['friendly_urls' => '1', 'use_alias_path' => '1', 'container_suffix' => '', 'cache_disabled' => '0', 'cache_resource' => '1',
                  'automatic_template_assignment' => 'sibling', 'publish_default' => '1', 'site_name' => $M['site_name'], 'use_editor' => '1',
                  'manager_language' => 'ru', 'cultureKey' => 'ru', 'fe_editor_lang' => 'ru'] as $k => $v) {
            if ($dry) { $cur = $modx->getObject(modSystemSetting::class, $k); if (!$cur || (string)$cur->get('value') !== $v) say('upd', "настройка $k = «{$v}»"); continue; }
            if ($r = setting($k, $v, 'Доходные дома')) say($r, "настройка $k = «{$v}»");
            $modx->setOption($k, $v);   // сразу в этот запуск: адреса страниц ниже считаются по этим настройкам
        }
        $html = $modx->getObject(modContentType::class, ['mime_type' => 'text/html']);
        if ($html && $html->get('file_extensions') !== '') {
            say('upd', 'тип содержимого HTML: адреса страниц без «.html»');
            if (!$dry) { backup('settings', 'content_type_html', $html->toArray()); $html->set('file_extensions', ''); saved($html, 'тип содержимого HTML'); }
        }
        $ht = MODX_BASE_PATH . '.htaccess';
        if (!is_file($ht) && is_file(MODX_BASE_PATH . 'ht.access')) {
            say('new', '.htaccess из ht.access MODX (человекопонятные адреса)');
            if (!$dry && !@copy(MODX_BASE_PATH . 'ht.access', $ht)) say('error', 'не удалось создать .htaccess — переименуйте ht.access в .htaccess в корне сайта вручную');
        } elseif (is_file($ht) && strpos((string)file_get_contents($ht), 'RewriteRule') === false) {
            say('warn', 'в .htaccess нет правил MODX — адреса страниц могут не открываться; возьмите правила из ht.access');
        }
        // заголовки безопасности: свой блок между метками — повторный запуск его обновит, а не задублирует
        $sec = "# BEGIN Bania security (bn-install.php)
<IfModule mod_headers.c>
"
             . "  Header always set X-Content-Type-Options \"nosniff\"
"
             . "  Header always set X-Frame-Options \"SAMEORIGIN\"
"
             . "  Header always set Referrer-Policy \"strict-origin-when-cross-origin\"
"
             . "  Header always set Permissions-Policy \"geolocation=(), camera=(), microphone=(), payment=(), usb=()\"
"
             . "  Header always set Strict-Transport-Security \"max-age=31536000\" \"expr=%{HTTPS} == 'on'\"
"
             . "</IfModule>
# END Bania security
";
        $cur = is_file($ht) ? (string)file_get_contents($ht) : '';
        $next = preg_match('~# BEGIN Bania security.*?# END Bania security
?~s', $cur) ? preg_replace('~# BEGIN Bania security.*?# END Bania security
?~s', $sec, $cur) : $sec . "
" . $cur;
        if ($next !== $cur && (is_file($ht) || $dry)) {
            say('upd', '.htaccess: заголовки безопасности (запрет встраивания сайта, HTTPS)');
            if (!$dry) { if ($cur !== '') backup('files', '.htaccess', $cur); if (@file_put_contents($ht, $next) === false) say('error', 'не удалось записать заголовки безопасности в .htaccess'); }
        }
    }

    // --- 11. страницы: родитель — по адресу (alias) раздела; uri — свой адрес (предоплата: /Предоплата1/)
    $ids = []; $menu = [];
    $ctype = function ($mime) use ($modx) {
        if (!$mime) return null;
        $c = $modx->getObject(modContentType::class, ['mime_type' => $mime]);
        if (!$c) say('warn', "тип содержимого $mime не найден — оставляю HTML");
        return $c ? (int)$c->get('id') : null;
    };
    $tvValue = function ($v) use (&$ids) {   // «@ids:адрес,адрес» → id страниц через || (поля-флажки, списки домов)
        if (!is_string($v) || strpos($v, '@ids:') !== 0) return (string)$v;
        $out = [];
        foreach (array_filter(explode(',', substr($v, 5))) as $a) if (!empty($ids[$a])) $out[] = $ids[$a];
        return implode('||', $out);
    };
    foreach ($R as $r) {
        try {
            $parentId = $r['parent'] === '' ? 0 : ($ids[$r['parent']] ?? null);
            if ($parentId === null) { say('error', "страница /{$r['alias']}: не создан раздел /{$r['parent']} — пропускаю"); continue; }
            $menu[$parentId] = ($menu[$parentId] ?? -1) + 1;
            $home = !empty($r['home']);
            $o = $home ? $modx->getObject(modResource::class, (int)$modx->getOption('site_start', null, 1))
                       : $modx->getObject(modResource::class, ['alias' => $r['alias'], 'parent' => $parentId, 'context_key' => 'web']);
            $isNew = !$o;
            $ours = !$isNew && $home && (int)$o->get('template') === (int)($tplIds[$r['template']] ?? -1);
            if (!$isNew && !$update && empty($r['always']) && (!$home || $ours || $dry && !$tplIds)) {
                $ids[$r['alias']] = (int)$o->get('id');
                say('skip', "страница «{$o->get('pagetitle')}» уже есть — не трогаю (перезаписать: --update)");
                continue;
            }
            if (!$isNew) backup('resources', $r['alias'], $o->toArray() + ['tvs' => array_map(function ($n) use ($o) { return $o->getTVValue($n); }, array_combine(array_keys($r['tvs']), array_keys($r['tvs'])))]);
            if ($isNew) $o = $modx->newObject(modDocument::class);
            $data = $r['fields'] + [
                'parent' => $parentId, 'template' => $r['template'] ? (int)($tplIds[$r['template']] ?? 0) : 0,
                'published' => $r['published'] ?? 1, 'hidemenu' => $r['hidemenu'] ?? 0, 'searchable' => $r['searchable'] ?? 1,
                'isfolder' => $r['isfolder'] ?? 0, 'menuindex' => $menu[$parentId], 'context_key' => 'web',
                'richtext' => $r['richtext'] ?? 0, 'class_key' => modDocument::class, 'cacheable' => $r['cacheable'] ?? 1,
            ];
            if (!$home || $isNew) $data['alias'] = $r['alias'];
            if (!empty($r['uri'])) { $data['uri'] = $r['uri']; $data['uri_override'] = 1; }
            if (!empty($r['publishedon'])) $data['publishedon'] = strtotime($r['publishedon'] . ' 10:00');
            elseif ($isNew && $data['published']) $data['publishedon'] = time();
            if (($ct = $ctype($r['content_type'] ?? '')) !== null) $data['content_type'] = $ct;
            $o->fromArray($data);
            $title = $r['fields']['pagetitle'] ?? $r['alias'];
            if (!saved($o, "страницу /{$r['alias']}")) continue;
            say($isNew ? 'new' : 'upd', ($isNew ? 'создана' : 'обновлена') . " страница «{$title}»" . ($r['tvs'] ? ' · полей: ' . count($r['tvs']) : ''));
            if ($dry) { $ids[$r['alias']] = 0; continue; }
            $ids[$r['alias']] = (int)$o->get('id');
            foreach ($r['tvs'] as $tvName => $value) if (!$o->setTVValue($tvName, $tvValue($value))) say('error', "страница /{$r['alias']}: не записалось поле $tvName");
        } catch (Throwable $e) { say('error', "страница /{$r['alias']}: " . $e->getMessage()); }
    }

    // --- 12. ID разделов в настройки (их читают сниппеты и чанки)
    foreach ($M['id_settings'] as $key => $alias) {
        if (empty($ids[$alias])) continue;
        if ($r = setting($key, $ids[$alias])) say($r, "настройка $key = ID страницы /$alias");
        $modx->setOption($key, $ids[$alias]);
    }
    // ссылки [[~id]] — от корня сайта («/kontakty»): в разметке нет <base>, и относительные ссылки со статей блога вели бы в /blog/…
    if (!$dry && ($r = setting('link_tag_scheme', 'abs'))) say($r, 'настройка link_tag_scheme = abs (ссылки от корня сайта)');
    $modx->setOption('link_tag_scheme', 'abs');

    // --- 13. библиотека сайта: core/components/bania/ (Bania.php, страница «Заявки»)
    $comp = MODX_CORE_PATH . 'components/bania/';
    $copied = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/component', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir . '/component') + 1));
        $target = $comp . $rel;
        if (is_file($target) && md5_file($target) === md5_file($f->getPathname())) continue;
        $copied++;
        if ($dry) continue;
        if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
        if (is_file($target)) backup('component', str_replace('/', '__', $rel), (string)file_get_contents($target));
        if (!@copy($f->getPathname(), $target)) say('error', "не скопировался core/components/bania/$rel — нет прав на core/components/?");
    }
    say($copied ? 'upd' : 'skip', ($dry ? 'положу' : 'обновлено') . " файлов библиотеки сайта (core/components/bania/): $copied");

    // пространство имён и пункт меню «Заявки» (Пакеты → Заявки)
    $ns = $modx->getObject(\MODX\Revolution\modNamespace::class, 'bania');
    if (!$ns) {
        say('new', 'пространство имён bania (страница «Заявки» в админке)');
        if (!$dry) { $ns = $modx->newObject(\MODX\Revolution\modNamespace::class); $ns->set('name', 'bania'); $ns->set('path', '{core_path}components/bania/'); $ns->set('assets_path', '{assets_path}'); saved($ns, 'пространство имён bania'); }
    }
    // пункт меню: подпись — ключ записи меню; старый вариант «bania_leads» (до 2026-10-20) убираем
    if (!$dry && ($old = $modx->getObject(\MODX\Revolution\modMenu::class, 'bania_leads'))) { $old->remove(); say('upd', 'старый пункт меню bania_leads убран'); }
    $menuItem = $modx->getObject(\MODX\Revolution\modMenu::class, 'Заявки');
    if (!$menuItem) {
        say('new', 'пункт меню «Заявки» (Пакеты → Заявки)');
        if (!$dry) {
            $menuItem = $modx->newObject(\MODX\Revolution\modMenu::class);
            $menuItem->fromArray(['text' => 'Заявки', 'parent' => 'components', 'action' => 'index', 'namespace' => 'bania', 'description' => 'Заявки с сайта: статусы, предоплата, заметки', 'menuindex' => 0, 'params' => '', 'handler' => '', 'permissions' => ''], '', true, true);
            saved($menuItem, 'пункт меню «Заявки»');
        }
    }
    // таблицы заявок и занятых ночей
    if ($dry) say('new', 'таблицы заявок (bania_leads) и занятых ночей (bania_nights) — создам, если их нет');
    else {
        require_once $comp . 'Bania.php';
        Bania::init($modx);
        $had = (bool)$modx->query("SHOW TABLES LIKE '" . $modx->getOption('table_prefix') . "bania_leads'")->fetch();
        Bania::ensureTables();
        $has = (bool)$modx->query("SHOW TABLES LIKE '" . $modx->getOption('table_prefix') . "bania_leads'")->fetch();
        say($has ? ($had ? 'skip' : 'new') : 'error', $has ? ($had ? 'таблицы заявок уже есть — данные не тронуты' : 'созданы таблицы заявок и занятых ночей') : 'не создались таблицы заявок — смотрите журнал ошибок MODX');
    }
} catch (Throwable $e) {
    say('error', 'установка прервана: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . '). Уже сделанное сохранено, резервная копия — ' . str_replace(MODX_CORE_PATH, 'core/', $backupDir));
}

// адреса всех страниц заново (страницы, сохранённые до включения человекопонятных адресов, остались бы без адреса)
if (!$dry && $modx->getOption('friendly_urls')) {
    try { modResource::refreshURIs($modx); say('upd', 'адреса страниц пересчитаны'); } catch (Throwable $e) { say('warn', 'адреса страниц не пересчитались: ' . $e->getMessage() . ' — очистите кэш в админке'); }
}
if (!$dry) { try { $modx->cacheManager->refresh(); } catch (Throwable $e) { say('warn', 'кэш не очистился: очистите вручную (Управление → Очистить кэш)'); } }
if (!$dry && !$checkOnly) {   // запомнить, что поставили: при следующем обновлении правки из админки не перезапишутся
    $state['_version'] = $M['version'];
    if (setting('bn_install_state', json_encode($state, JSON_UNESCAPED_UNICODE))) {}
    say(!$prevVersion ? 'new' : ($prevVersion !== $M['version'] ? 'upd' : 'skip'), $prevVersion ? ($prevVersion === $M['version'] ? "версия сайта {$M['version']} (та же)" : "сайт обновлён с версии $prevVersion до {$M['version']}") : "установлена версия сайта {$M['version']}");
}
if (!$dry) selfCheck();

report(true);

/* ---------------------------------------------------------------- самопроверка
   Открывает свой сайт как посетитель и проверяет: страницы, приложение, файлы в assets/, безопасность, почту, заполненность настроек.
   «проверено» — всё хорошо, «внимание» — стоит поправить, «ошибка» — сайт работает неправильно. */
function httpGet($url, $follow = false) {   // [код, заголовки (строчными), тело]
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'Bania-selfcheck']);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($res === false) return [0, '', ''];
        return [$code, strtolower(substr($res, 0, $hs)), (string)substr($res, $hs)];
    }
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => $follow ? 1 : 0, 'timeout' => 20, 'header' => "User-Agent: Bania-selfcheck\r\n"],
                                  'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $body = @file_get_contents($url, false, $ctx);
    $hdr = isset($http_response_header) ? strtolower(implode("\n", $http_response_header)) : '';
    $code = preg_match('~^http/\S+\s+(\d+)~', $hdr, $m) ? (int)$m[1] : 0;
    return [$code, $hdr, (string)$body];
}
function selfCheck() {
    global $modx, $dir, $inside, $staticPrefix, $M;
    say('skip', '— самопроверка сайта —');
    $base = rtrim((string)$modx->getOption('site_url'), '/') . '/';
    [$code, $hdr, $body] = httpGet($base);
    if ($code === 0) { say('warn', "самопроверка: сервер не открыл свой же сайт $base (хостинг не разрешает запросы к себе?) — проверьте сайт в браузере вручную"); return; }
    $page = function ($path, $label, $must = []) use ($base) {
        [$c, , $b] = httpGet($base . $path);
        $bad = [];
        if ($c !== 200) $bad[] = "код ответа $c";
        if (strpos($b, '[[') !== false) $bad[] = 'остались теги MODX';
        foreach ($must as $m) if (strpos($b, $m) === false) $bad[] = "нет «{$m}»";
        say($bad ? 'error' : 'ok', "$label (/" . rawurldecode($path) . ")" . ($bad ? ': ' . implode(', ', $bad) : ''));
        return $b;
    };
    $home = $page('', 'главная', ['class="hero', 'class="hcard', 'application/ld+json', 'window.HOUSES']);
    foreach (['bn_phone' => 'телефон', 'bn_email' => 'почта'] as $k => $label) {
        $v = trim((string)$modx->getOption($k));
        if ($v === '') continue;
        $shown = strpos($home, htmlspecialchars($v)) !== false || strpos($home, $v) !== false;
        say($shown ? 'ok' : 'error', $shown ? "на сайте выводится $label из «Конфигурации»: $v" : "на сайте не выводится $label из «Конфигурации» ($v) — очистите кэш и проверьте ключ поля «{$k}»");
    }
    foreach ($M['id_settings'] as $key => $alias) {
        if (strpos($key, 'bn_house_') !== 0) continue;
        $id = (int)$modx->getOption($key);
        if ($id) $page(ltrim($modx->makeUrl($id), '/'), 'страница дома', ['class="dhero', 'class="gal', 'class="price-days']);
    }
    foreach (['Предоплата1/', 'Предоплата2/', 'Предоплата3/'] as $u) $page(rawurlencode(rtrim($u, '/')) . '/', 'страница предоплаты', ['qr.nspk.ru', 'noindex']);
    if ($id = (int)$modx->getOption('bn_contacts_id')) $page(ltrim($modx->makeUrl($id), '/'), 'контакты', ['class="cgrid']);
    if ($id = (int)$modx->getOption('bn_blog_id')) $page(ltrim($modx->makeUrl($id), '/'), 'блог', ['class="pcard']);
    $page('robots.txt', 'robots.txt', ['Sitemap:']);
    $page('sitemap.xml', 'карта сайта', ['<urlset']);
    $page('rss.xml', 'лента блога', ['<rss']);
    $page('llms.txt', 'llms.txt', ['# ']);
    foreach (['assets/css/styles.css' => 'стили', 'assets/js/app.js' => 'скрипт', 'assets/img/fox.svg' => 'логотип', 'assets/img/ru/hero-2.jpg' => 'фото', 'assets/img/qr/ru.svg' => 'QR-код предоплаты'] as $path => $label) {
        [$c] = httpGet($base . $path);
        say($c === 200 ? 'ok' : 'error', "$label (/$path)" . ($c === 200 ? '' : " — код $c: запустите установщик ещё раз"));
    }
    // приём заявок: запрос без защитного кода должен вежливо отказать (400), а не упасть
    if ($id = (int)$modx->getOption('bn_lead_id')) {
        $u = $base . ltrim($modx->makeUrl($id), '/');
        $ch = function_exists('curl_init') ? curl_init($u) : null;
        if ($ch) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'name=test', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
            $b = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
            $j = json_decode($b, true);
            say($c === 400 && is_array($j) ? 'ok' : 'error', $c === 400 && is_array($j) ? 'приём заявок работает (без защитного кода — отказ, как и должно быть)' : "приём заявок отвечает не так (код $c): " . mb_substr($b, 0, 120));
        }
    }
    say(stripos($base, 'https://') === 0 ? 'ok' : 'warn', stripos($base, 'https://') === 0 ? 'сайт работает по https' : 'сайт открывается без https — выпустите SSL-сертификат в панели хостинга');
    $hasSec = strpos($hdr, 'x-frame-options') !== false && strpos($hdr, 'x-content-type-options') !== false;
    say($hasSec ? 'ok' : 'warn', $hasSec ? 'заголовки безопасности включены' : 'заголовки безопасности не пришли — на хостинге выключен mod_headers или .htaccess не читается');
    if ($inside && is_dir($dir)) {
        [$c] = httpGet($base . $staticPrefix . 'resources.json');
        say($c === 200 ? 'warn' : 'ok', $c === 200 ? 'папка установщика открыта из браузера — удалите её кнопкой «Удалить папку установщика»' : 'папка установщика закрыта из браузера (после проверки удалите её)');
    }
    $mailOk = function_exists('mail') && !in_array('mail', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
    say($mailOk ? 'ok' : 'warn', $mailOk ? 'хостинг умеет отправлять письма (заявки придут на ' . $modx->getOption('bn_lead_email') . ') — отправьте тестовую заявку' : 'на хостинге выключена отправка писем — заявки будут только в «Пакеты → Заявки»');
    foreach (['bn_phone' => 'телефон', 'bn_lead_email' => 'почта для заявок', 'bn_inn' => 'ИНН'] as $k => $label) {
        if (trim((string)$modx->getOption($k)) === '') say('warn', "«Конфигурация»: не заполнено — $label");
    }
}

// --- отчёт: экран + файл журнала
function report($finished) {
    global $log, $dry, $cli, $logFile, $backedUp, $backupDir, $modx, $static, $inside;
    global $checkOnly;
    $count = ['new' => 0, 'upd' => 0, 'skip' => 0, 'ok' => 0, 'warn' => 0, 'error' => 0];
    foreach ($log as $l) $count[$l[0]]++;
    $title = $checkOnly ? "Проверка сайта. Проверено: {$count['ok']}, предупреждений: {$count['warn']}, ошибок: {$count['error']}"
           : ($dry ? 'Пробный прогон (ничего не менялось). ' : ($finished ? 'Готово. ' : 'Остановлено. '))
           . "Создано: {$count['new']}, обновлено: {$count['upd']}, без изменений: {$count['skip']}, проверено: {$count['ok']}, предупреждений: {$count['warn']}, ошибок: {$count['error']}";
    if (!$dry && !$checkOnly && $backedUp) say('upd', "резервная копия перезаписанного ($backedUp шт.): " . str_replace(MODX_CORE_PATH, 'core/', $backupDir));
    if (!$dry) {
        $text = $title . "\n" . implode("\n", array_map(function ($l) { return "[{$l[0]}] {$l[1]}"; }, $log)) . "\n";
        if (@file_put_contents($logFile, $text) !== false) say('skip', 'журнал: ' . str_replace(MODX_CORE_PATH, 'core/', $logFile));
    }
    if (!$finished) return;
    if ($cli) {
        echo $title, "\n";
        foreach ($log as $l) echo "[{$l[0]}] {$l[1]}\n";
        exit($count['error'] ? 1 : 0);
    }
    $colors = ['new' => '#1e7d45', 'upd' => '#1f5fbf', 'skip' => '#8a7a70', 'ok' => '#1e7d45', 'warn' => '#a35b00', 'error' => '#b3261e'];
    $names = ['new' => 'новое', 'upd' => 'обновлено', 'skip' => 'без изменений', 'ok' => 'проверено ✓', 'warn' => 'внимание', 'error' => 'ошибка'];
    $btn = function ($href, $text, $main = false) {
        return '<a href="' . $href . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:600;'
            . ($main ? 'background:#B04E09;color:#fff' : 'background:#fff;color:#2A1A14;box-shadow:inset 0 0 0 1.5px #E7D3C0') . '">' . $text . '</a>';
    };
    $body = '';
    if ($checkOnly) $body .= '<p>' . ($count['error'] ? 'Есть ошибки — смотрите красные строки. Чаще всего помогает запустить установщик ещё раз. ' : 'Сайт работает. ')
        . $btn('?check=1', 'Проверить ещё раз') . $btn(htmlspecialchars($modx->getOption('site_url')), 'Открыть сайт', true) . '</p>';
    elseif ($dry && !$count['error']) $body .= '<p>Всё верно? ' . $btn('?', 'Установить', true) . $btn('?update=1', 'Установить и перезаписать страницы') . '</p>';
    elseif ($dry) $body .= '<p style="color:#b3261e">Исправьте ошибки (красным) и запустите пробный прогон снова: ' . $btn('?dry=1', 'Проверить ещё раз') . '</p>';
    else {
        $site = htmlspecialchars($modx->getOption('site_url'));
        $body .= '<p>' . ($count['error'] ? 'Есть ошибки — смотрите красные строки и журнал. ' : 'Сайт установлен. ') . $btn($site, 'Открыть сайт', true)
            . $btn(htmlspecialchars(MODX_MANAGER_URL), 'Открыть админку') . '</p>'
            . '<p>Ниже — результаты самопроверки (зелёным «проверено ✓»). Дальше: проверьте контакты в «Конфигурации» и отправьте с сайта тестовую заявку — она появится в «Пакеты → Заявки». '
            . 'Проверить сайт ещё раз можно в любой момент: ' . $btn('?check=1', 'Только проверить') . '</p>';
        if ($inside && !$static && !$count['error']) $body .= '<p>Папка установщика больше не нужна: ' . $btn('?remove=1', 'Удалить папку установщика') . '</p>';
    }
    $list = '<ul style="list-style:none;padding:0">';
    foreach ($log as $l) $list .= '<li style="padding:2px 0"><b style="color:' . $colors[$l[0]] . '">' . $names[$l[0]] . '</b> ' . htmlspecialchars($l[1]) . '</li>';
    echo page($title, $body . $list . '</ul>');
}
