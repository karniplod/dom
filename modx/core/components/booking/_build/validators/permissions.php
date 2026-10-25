<?php
/**
 * Проверка перед копированием файлов (валидатор пакета): файлы и папки бронирования должны быть доступны для записи PHP.
 * Иначе обновление в «Пакеты → Установщик» падает на каждом файле («Could not copy file … could not create directory»),
 * а удалить пакет удаётся (удалению хватает права на папку). Возвращаем права: папки 755, файлы 644.
 * Файлы чужого владельца поправить нельзя — пишем это одной понятной строкой. Установку не останавливает.
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */
use xPDO\xPDO;

$modx = $transport->xpdo;
$fixed = 0;
$bad = [];
$foreign = [];
$euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
foreach ([MODX_CORE_PATH . 'components/booking', MODX_ASSETS_PATH . 'components/booking'] as $root) {
    if (!is_dir($root)) {
        continue;   // первая установка — нечего проверять
    }
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ([new SplFileInfo($root)] + iterator_to_array($items, false) as $f) {
        $path = $f->getPathname();
        if (is_writable($path)) {
            continue;
        }
        $owner = @fileowner($path);
        if ($euid !== null && $owner !== false && $owner !== $euid) {
            $foreign[$owner] = true;
            $bad[] = $path;
            continue;
        }
        if (@chmod($path, $f->isDir() ? 0755 : 0644)) {
            clearstatcache(true, $path);
        }
        is_writable($path) ? $fixed++ : $bad[] = $path;
    }
}
if ($fixed) {
    $modx->log(xPDO::LOG_LEVEL_WARN, "Бронирование: у $fixed файлов и папок не было права на запись — исправлено (папки 755, файлы 644)");
}
if ($bad) {
    $who = function ($uid) { return (function_exists('posix_getpwuid') && ($p = @posix_getpwuid($uid))) ? $p['name'] : (string)$uid; };
    $modx->log(xPDO::LOG_LEVEL_ERROR, 'Бронирование: ' . count($bad) . ' файлов нельзя перезаписать (например, ' . $bad[0] . ')'
        . ($foreign ? '. Их владелец — ' . implode(', ', array_map($who, array_keys($foreign))) . ', а PHP работает от ' . $who($euid)
            . ': попросите поддержку хостинга сделать владельцем ' . $who($euid) . ' папки core/components/booking и assets/components/booking'
            : '. Поставьте рекурсивно: папкам 755, файлам 644')
        . '. Или удалите пакет и установите заново — брони и цены в базе не пропадут.');
}
return true;
