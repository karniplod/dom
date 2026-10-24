<?php
/**
 * Бот MAX из консоли.
 *   php bot.php ask "Сколько стоит Русский Дом?"   — что ответит бот (без MAX, токен не нужен)
 *   php bot.php status                             — бот найден по токену, подключён ли вебхук
 *   php bot.php poll                               — запасной режим без домена: бот сам забирает сообщения из MAX и отвечает (Ctrl+C — стоп)
 *   php bot.php poll --once                        — один раз забрать и ответить
 *   php bot.php subscribe https://ваш-домен/assets/components/booking/bot.php   — подключить вебхук (рабочий сайт с https)
 *   php bot.php unsubscribe https://ваш-домен/assets/components/booking/bot.php — отключить вебхук
 * Путь к сайту: --config=путь/к/config.core.php (по умолчанию — сайт, в котором лежит этот файл).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$args = array_slice($argv, 1);
$config = dirname(__DIR__, 4) . '/config.core.php';
foreach ($args as $i => $a) {
    if (strpos($a, '--config=') === 0) { $config = substr($a, 9); unset($args[$i]); }
}
$once = in_array('--once', $args, true);
$args = array_values(array_diff($args, ['--once']));
$cmd = $args[0] ?? '';
if (!is_file($config)) {
    fwrite(STDERR, "Не найден config.core.php: $config (укажите --config=…)\n");
    exit(2);
}
require $config;
require MODX_CORE_PATH . 'vendor/autoload.php';
$modx = new \MODX\Revolution\modX();
$modx->initialize('web');
$modx->setLogTarget('ECHO');
$modx->setLogLevel(\MODX\Revolution\modX::LOG_LEVEL_ERROR);
/** @var \Booking\Service\Booking $booking */
$booking = $modx->services->has('booking') ? $modx->services->get('booking') : null;
if (!$booking) {
    fwrite(STDERR, "Компонент «Бронирование» не установлен\n");
    exit(2);
}
$booking->setActor(['role' => 'guest', 'name' => 'гость', 'interface' => 'max']);
$bot = $booking->maxBot();

$show = function (array $reply) {
    echo $reply['text'], "\n";
    foreach ($reply['buttons'] as $row) {
        echo '  ', implode('   ', array_map(fn($b) => '[' . $b['text'] . ']', $row)), "\n";
    }
};
$needToken = function () use ($bot) {
    if ($bot->token() === '') {
        fwrite(STDERR, "Нет токена бота: впишите его в системную настройку maxnotify3.max_token\n");
        exit(2);
    }
};

switch ($cmd) {
    case 'ask':
        $show($booking->faq()->answer((string)($args[1] ?? ''), (string)($args[2] ?? '')));
        break;
    case 'status':
        $needToken();
        [$code, $me] = $bot->call('GET', '/me');
        echo $code === 200 ? 'Бот: ' . ($me['name'] ?? '') . ' @' . ($me['username'] ?? '') . "\n" : "Токен не принят (код $code)\n";
        [$code, $subs] = $bot->call('GET', '/subscriptions');
        $list = $subs['subscriptions'] ?? [];
        echo $list ? 'Вебхук: ' . implode(', ', array_column($list, 'url')) . "\n" : "Вебхук не подключён — работает только запасной режим (poll)\n";
        echo 'Ответы гостям (booking.bot_enabled): ' . ($modx->getOption('booking.bot_enabled') ? 'включены' : 'выключены') . "\n";
        break;
    case 'poll':
        $needToken();
        if (!$modx->getOption('booking.bot_enabled')) {
            echo "Внимание: booking.bot_enabled = Нет — в запасном режиме бот всё равно отвечает (это проверка).\n";
        }
        echo $once ? "Забираю сообщения…\n" : "Бот отвечает. Напишите ему в MAX. Остановить — Ctrl+C.\n";
        do {
            $n = $bot->poll($once ? 5 : 30);
            if ($n < 0) { echo "MAX не ответил — проверьте токен и интернет. Повтор через 10 секунд.\n"; if (!$once) sleep(10); }
            elseif ($n > 0) echo date('H:i:s') . " ответов: $n\n";
        } while (!$once);
        break;
    case 'subscribe':
    case 'unsubscribe':
        $needToken();
        $url = (string)($args[1] ?? '');
        if (!preg_match('~^https://[^/]+/.+~', $url)) {
            fwrite(STDERR, "Нужен адрес с https, например https://ваш-домен/assets/components/booking/bot.php\n");
            exit(2);
        }
        [$code, $res] = $cmd === 'subscribe' ? $bot->subscribe($url) : $bot->unsubscribe($url);
        echo $code === 200 && ($res['success'] ?? true) ? ($cmd === 'subscribe' ? "Вебхук подключён: $url\n" : "Вебхук отключён\n")
            : "MAX ответил $code: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
        break;
    default:
        echo preg_replace('~^.*?/\*\*\n|\*/.*$~s', '', file_get_contents(__FILE__, false, null, 0, 1200)), "\n";
}
