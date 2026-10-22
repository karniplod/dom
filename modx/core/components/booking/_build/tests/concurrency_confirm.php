<?php
/**
 * Тест одновременности (SC-002, ст. 5.1): два администратора в один момент подтверждают пересекающиеся заявки —
 * ровно одно подтверждение проходит. Два отдельных процесса PHP (свои соединения с БД) стартуют в одну миллисекунду.
 * Запуск: php _build/tests/concurrency_confirm.php [путь к config.core.php] [раундов]
 */
$configCore = $argv[1] ?? 'C:/cloud/modx-bania/www/config.core.php';

if (($argv[2] ?? '') === 'child') {
    // дочерний процесс: ждёт общий момент старта и подтверждает свою заявку
    [$id, $startAt] = [(int)$argv[3], (float)$argv[4]];
    require $configCore;
    require MODX_CORE_PATH . 'vendor/autoload.php';
    $_SESSION = [];
    $modx = new \MODX\Revolution\modX();
    $modx->initialize('web');
    $booking = $modx->services->get('booking');
    $booking->setActor(['role' => 'admin', 'name' => 'ТЕСТ гонка ' . getmypid(), 'user_id' => 1, 'interface' => 'cli']);
    $modx->getCount(\Booking\Model\House::class);   // соединение открыто заранее
    while (microtime(true) < $startAt) {
        usleep(200);
    }
    $r = $booking->runAction('booking_confirm', ['id' => $id]);
    echo json_encode(['id' => $id, 'status' => $r['status'], 'message' => $r['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

$rounds = (int)($argv[2] ?? 10);
require $configCore;
require MODX_CORE_PATH . 'vendor/autoload.php';
$_SESSION = [];
$modx = new \MODX\Revolution\modX();
$modx->initialize('web');
$booking = $modx->services->get('booking');
$php = PHP_BINARY;
$ini = php_ini_loaded_file();

$fail = 0;
for ($i = 0; $i < $rounds; $i++) {
    // две пересекающиеся заявки: 1-я на ночи d, d+1; 2-я на d+1, d+2
    $d = date('Y-m-d', strtotime('2032-02-01 +' . ($i * 5) . ' days'));
    $ids = [];
    foreach ([[$d, 2], [date('Y-m-d', strtotime($d . ' +1 day')), 2]] as [$from, $n]) {
        $booking->setActor(['role' => 'guest', 'name' => 'гость', 'interface' => 'site']);
        $r = $booking->runAction('booking_create_request', ['house' => 'ru', 'date_from' => $from,
            'date_to' => date('Y-m-d', strtotime($from . " +{$n} days")), 'guests' => 2, 'name' => 'ТЕСТ гонка', 'phone' => '+79000000000']);
        $ids[] = json_decode(json_encode($r['data']), true)['id'] ?? 0;
    }
    $startAt = microtime(true) + 1.5;
    $procs = [];
    foreach ($ids as $id) {
        $cmd = escapeshellarg($php) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__) . ' '
            . escapeshellarg($configCore) . " child {$id} {$startAt}";
        $procs[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$id]);
    }
    $codes = [];
    foreach ($ids as $k => $id) {
        $out = stream_get_contents($pipes[$id][1]);
        proc_close($procs[$k]);
        $j = json_decode(trim($out), true);
        $codes[] = $j['status'] ?? 'ошибка: ' . trim($out);
    }
    sort($codes);
    $nights = $modx->getCount(\Booking\Model\Night::class, ['booking_id:IN' => $ids]);
    $ok = $codes === [200, 409] && $nights === 2;
    $fail += $ok ? 0 : 1;
    printf("раунд %d: ответы %s, занятых ночей %d — %s\n", $i + 1, json_encode($codes), $nights, $ok ? 'OK' : 'ОШИБКА');
}

// уборка
$ids = [];
foreach ($modx->getIterator(\Booking\Model\Booking::class, ['guest_name' => 'ТЕСТ гонка']) as $b) {
    $ids[] = (int)$b->get('id');
}
if ($ids) {
    $modx->removeCollection(\Booking\Model\Night::class, ['booking_id:IN' => $ids]);
    $modx->removeCollection(\Booking\Model\LogEntry::class, ['entity' => 'booking', 'entity_id:IN' => $ids]);
    $modx->removeCollection(\Booking\Model\Booking::class, ['id:IN' => $ids]);
}
echo $fail ? "ПРОВАЛ: {$fail} из {$rounds}\n" : "Все {$rounds} раундов: ровно одно подтверждение\n";
exit($fail ? 1 : 0);
