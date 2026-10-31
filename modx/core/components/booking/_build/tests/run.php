<?php
/**
 * Тесты процессоров booking на стенде: php _build/tests/run.php [путь к config.core.php] [фильтр]
 * Каждый *Test.php возвращает function (BookingTest $t). Тестовые данные — даты 2031 года, гость «ТЕСТ …»;
 * перед и после прогона они удаляются.
 */
$configCore = $argv[1] ?? 'C:/cloud/modx-bania/www/config.core.php';
$filter = $argv[2] ?? '';
require $configCore;
require MODX_CORE_PATH . 'vendor/autoload.php';
$_SESSION = [];
$modx = new \MODX\Revolution\modX();
$modx->initialize('web');
$modx->setLogTarget('ECHO');
$modx->setLogLevel(\MODX\Revolution\modX::LOG_LEVEL_ERROR);

class BookingTest
{
    public \MODX\Revolution\modX $modx;
    public \Booking\Service\Booking $booking;
    public int $pass = 0;
    public array $fail = [];
    public string $case = '';

    public function __construct($modx)
    {
        $this->modx = $modx;
        $this->booking = $modx->services->get('booking');
        $this->flood(false);
    }

    /** Защита от потока заявок: в тестах заявки идут с одного номера — включается только в FloodTest */
    public function flood(bool $on): void
    {
        $this->modx->setOption('booking.requests_per_phone', $on ? 3 : 0);
        $this->modx->setOption('booking.requests_per_ip', $on ? 10 : 0);
    }

    public function admin(string $interface = 'cli'): void
    {
        $this->booking->setActor(['role' => 'admin', 'name' => 'тест', 'user_id' => 1, 'interface' => $interface]);
    }

    public function guest(string $interface = 'site'): void
    {
        $this->booking->setActor(['role' => 'guest', 'name' => 'гость', 'interface' => $interface]);
    }

    public function run(string $action, array $params = []): array
    {
        $r = $this->booking->runAction($action, $params);
        $r['data'] = json_decode(json_encode($r['data']), true);
        return $r;
    }

    public function ok($cond, string $what): void
    {
        if ($cond) {
            $this->pass++;
        } else {
            $this->fail[] = $this->case . ': ' . $what;
            echo "  ✗ {$what}\n";
        }
    }

    public function eq($expected, $actual, string $what): void
    {
        $this->ok($expected === $actual, $what . ' (ожидали ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', получили ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . ')');
    }

    /** Заявка гостя напрямую процессором (как делает хук SendIt) */
    public function request(array $p): array
    {
        $this->guest();
        return $this->run('booking_create_request', $p + ['guests' => 4, 'pets' => 0, 'name' => 'ТЕСТ Гость', 'phone' => '+7 900 000-00-00']);
    }

    public function cleanup(): void
    {
        $m = $this->modx;
        $ids = [];
        foreach ($m->getIterator(\Booking\Model\Booking::class, ['guest_name:LIKE' => 'ТЕСТ%']) as $b) {
            $ids[] = (int)$b->get('id');
        }
        if ($ids) {
            $m->removeCollection(\Booking\Model\Night::class, ['booking_id:IN' => $ids]);
            $m->removeCollection(\Booking\Model\LogEntry::class, ['entity' => 'booking', 'entity_id:IN' => $ids]);
            $m->removeCollection(\Booking\Model\Booking::class, ['id:IN' => $ids]);
        }
        foreach ($m->getIterator(\Booking\Model\Holiday::class, ['date:>=' => '2031-01-01', 'date:<=' => '2031-12-31']) as $h) {
            $m->removeCollection(\Booking\Model\HolidayPrice::class, ['holiday_id' => $h->get('id')]);
            $h->remove();
        }
        $m->removeCollection(\Booking\Model\BlockedSlot::class, ['reason:LIKE' => 'ТЕСТ%']);
        $m->removeCollection(\Booking\Model\AdminToken::class, ['label:LIKE' => 'ТЕСТ%']);
    }
}

$t = new BookingTest($modx);
$t->cleanup();
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $name = basename($file, '.php');
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    echo $name, "\n";
    $cases = require $file;
    foreach ($cases as $case => $fn) {
        $t->case = $name . ' / ' . $case;
        echo "  · {$case}\n";
        try {
            $fn($t);
        } catch (\Throwable $e) {
            $t->fail[] = $t->case . ': исключение ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            echo "  ✗ исключение: ", $e->getMessage(), ' @ ', basename($e->getFile()), ':', $e->getLine(), "\n";
        }
    }
}
$t->cleanup();
echo "\nПроверок пройдено: {$t->pass}, провалено: " . count($t->fail) . "\n";
foreach ($t->fail as $f) {
    echo " - {$f}\n";
}
exit($t->fail ? 1 : 0);
