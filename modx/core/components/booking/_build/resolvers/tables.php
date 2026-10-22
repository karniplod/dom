<?php
/**
 * Резолвер: таблицы booking_* — создание при установке, добавление новых полей и индексов при обновлении.
 * Ничего не удаляет: данные броней сохраняются при обновлении пакета.
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */
use xPDO\Transport\xPDOTransport;

$modx = $transport->xpdo;
if (!in_array($options[xPDOTransport::PACKAGE_ACTION], [xPDOTransport::ACTION_INSTALL, xPDOTransport::ACTION_UPGRADE], true)) {
    return true;
}
$path = $modx->getOption('core_path') . 'components/booking/';
\MODX\Revolution\modX::getLoader()->addPsr4('Booking\\', $path . 'src/');
$modx->addPackage('Booking\\Model', $path . 'src/', null, 'Booking\\');

$manager = $modx->getManager();
$classes = ['House', 'Addon', 'PriceRule', 'Holiday', 'HolidayPrice', 'Booking', 'Night', 'BlockedSlot', 'AdminToken', 'LogEntry'];
$level = $modx->getLogLevel();
$modx->setLogLevel(\xPDO\xPDO::LOG_LEVEL_FATAL);
foreach ($classes as $short) {
    $class = 'Booking\\Model\\' . $short;
    $table = $modx->getTableName($class);
    $exists = $modx->query('SHOW TABLES LIKE ' . $modx->quote(trim($table, '`')));
    if (!$exists || !$exists->fetchColumn()) {
        $manager->createObjectContainer($class);
        continue;
    }
    // обновление: недостающие поля и индексы
    $cols = [];
    foreach ($modx->query("SHOW COLUMNS FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $cols[$c['Field']] = true;
    }
    foreach (array_keys($modx->getFieldMeta($class)) as $field) {
        if (!isset($cols[$field])) {
            $manager->addField($class, $field);
        }
    }
    $idx = [];
    foreach ($modx->query("SHOW INDEX FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC) as $i) {
        $idx[$i['Key_name']] = true;
    }
    foreach (array_keys($modx->getIndexMeta($class)) as $name) {
        if (!isset($idx[$name])) {
            $manager->addIndex($class, $name);
        }
    }
}
$modx->setLogLevel($level);
$modx->log(\xPDO\xPDO::LOG_LEVEL_INFO, 'booking: таблицы готовы');
return true;
