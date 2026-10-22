<?php
/**
 * Генерация классов модели из schema/booking.mysql.schema.xml в src/Model/.
 * Запуск на стенде: php _build/build.schema.php [путь к config.core.php]
 */
$configCore = $argv[1] ?? 'C:/cloud/modx-bania/www/config.core.php';
require $configCore;
require MODX_CORE_PATH . 'vendor/autoload.php';

$_SESSION = [];
$modx = new \MODX\Revolution\modX();
$modx->initialize('mgr');
$modx->setLogLevel(\xPDO\xPDO::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');

$root = dirname(__DIR__) . '/';
$generator = $modx->getManager()->getGenerator();
// namespacePrefix = 'Booking' → классы Booking\Model\* лежат в src/Model/
$generator->parseSchema($root . 'schema/booking.mysql.schema.xml', $root . 'src/', [
    'compile' => 0,
    'update' => 0,
    'regenerate' => 1,
    'namespacePrefix' => 'Booking',
]);
echo "Классы модели: {$root}src/Model/\n";
