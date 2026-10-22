<?php
/**
 * Загрузка компонента booking (MODX 3 подключает файл при инициализации пространства имён).
 * @var \MODX\Revolution\modX $modx
 * @var array $namespace
 */
$path = $namespace['path'] ?? ($modx->getOption('core_path') . 'components/booking/');
\MODX\Revolution\modX::getLoader()->addPsr4('Booking\\', $path . 'src/');
$modx->addPackage('Booking\\Model', $path . 'src/', null, 'Booking\\');

if (!$modx->services->has('booking')) {
    $modx->services->add('booking', function () use ($modx) {
        return new \Booking\Service\Booking($modx);
    });
}
