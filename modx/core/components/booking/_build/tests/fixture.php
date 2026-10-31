<?php
/**
 * Тестовые данные для e2e-тестов: php _build/tests/fixture.php request <дом> <заезд> <выезд> [гостей] [имя]
 *                                  php _build/tests/fixture.php confirm <id>
 *                                  php _build/tests/fixture.php token <подпись>
 *                                  php _build/tests/fixture.php addon <дом> <цена за час|off>
 *                                  php _build/tests/fixture.php cleanup
 * Конфиг стенда — переменная окружения BOOKING_CONFIG_CORE (по умолчанию C:/cloud/modx-bania/www/config.core.php).
 */
require getenv('BOOKING_CONFIG_CORE') ?: 'C:/cloud/modx-bania/www/config.core.php';
require MODX_CORE_PATH . 'vendor/autoload.php';
$_SESSION = [];
$modx = new \MODX\Revolution\modX();
$modx->initialize('web');
/** @var \Booking\Service\Booking $b */
$b = $modx->services->get('booking');
$cmd = $argv[1] ?? '';
$out = function ($r) {
    echo json_encode(is_array($r) && isset($r['data']) ? ['status' => $r['status'], 'message' => $r['message'], 'data' => $r['data']] : $r, JSON_UNESCAPED_UNICODE);
};
switch ($cmd) {
    case 'request':
        $b->setActor(['role' => 'guest', 'interface' => 'site']);
        $modx->setOption('booking.requests_per_phone', 0);   // e2e шлёт заявки с одного номера; защиту проверяет FloodTest
        $out($b->runAction('booking_create_request', ['house' => $argv[2], 'date_from' => $argv[3], 'date_to' => $argv[4],
            'guests' => $argv[5] ?? 4, 'name' => $argv[6] ?? 'ТЕСТ Гость', 'phone' => '+79001112233']));
        break;
    case 'confirm':
        $b->setActor(['role' => 'admin', 'name' => 'тест', 'user_id' => 1, 'interface' => 'cli']);
        $out($b->runAction('booking_confirm', ['id' => (int)$argv[2]]));
        break;
    case 'token':
        $b->setActor(['role' => 'admin', 'name' => 'тест', 'user_id' => 1, 'interface' => 'cli']);
        $out($b->runAction('booking_issue_token', ['op' => 'issue', 'label' => $argv[2] ?? 'ТЕСТ e2e']));
        break;
    case 'addon':
        // «addon <дом> <цена>» — включить баню для теста (уборка 30 мин, как ждут проверки), прежние настройки — в кэш;
        // «addon <дом> off» — вернуть настройки, какие были до теста (а не выключать: стенд мог быть настроен владельцем)
        $b->setActor(['role' => 'admin', 'name' => 'тест', 'user_id' => 1, 'interface' => 'cli']);
        $key = 'booking_e2e_addon_' . preg_replace('~\W~', '', $argv[2]);
        if (($argv[3] ?? 'off') === 'off') {
            $was = $modx->cacheManager->get($key);
            // нечего возвращать (тест не включал баню) — ничего не меняем
            $out($was ? $b->runAction('booking_manage_addon', $was) : ['status' => 200, 'message' => 'нечего возвращать', 'data' => []]);
            $modx->cacheManager->delete($key);
            break;
        }
        foreach (json_decode(json_encode($b->runAction('booking_manage_house', ['op' => 'list'])['data']), true)['items'] as $h) {
            if ($h['code'] === $argv[2] && !$modx->cacheManager->get($key)) {
                $was = ['op' => 'save', 'house' => $h['code'], 'bookable_separately' => $h['addon_bookable_separately'] ? 1 : 0,
                    'price_per_hour' => $h['addon']['price_per_hour'] ?? '', 'min_hours' => $h['addon']['min_hours'] ?? 2, 'buffer_minutes' => $h['addon']['buffer_minutes'] ?? 60];
                $modx->cacheManager->set($key, $was, 86400);
            }
        }
        $out($b->runAction('booking_manage_addon', ['op' => 'save', 'house' => $argv[2], 'bookable_separately' => 1,
            'price_per_hour' => (int)$argv[3], 'min_hours' => 2, 'buffer_minutes' => 30]));
        break;
    case 'cleanup':
        // счётчики «заявок с адреса за час» (Bookings::assertNotFlood): e2e шлёт заявки с 127.0.0.1 подряд
        $modx->cacheManager->clean([\xPDO\xPDO::OPT_CACHE_KEY => 'booking']);
        $ids = [];
        foreach ($modx->getIterator(\Booking\Model\Booking::class, ['guest_name:LIKE' => 'ТЕСТ%']) as $row) {
            $ids[] = (int)$row->get('id');
        }
        if ($ids) {
            $modx->removeCollection(\Booking\Model\Night::class, ['booking_id:IN' => $ids]);
            $modx->removeCollection(\Booking\Model\LogEntry::class, ['entity' => 'booking', 'entity_id:IN' => $ids]);
            $modx->removeCollection(\Booking\Model\Booking::class, ['id:IN' => $ids]);
        }
        $modx->removeCollection(\Booking\Model\BlockedSlot::class, ['reason:LIKE' => 'ТЕСТ%']);
        $modx->removeCollection(\Booking\Model\AdminToken::class, ['label:LIKE' => 'ТЕСТ%']);
        foreach ($modx->getIterator(\Booking\Model\Holiday::class, ['comment:LIKE' => 'ТЕСТ%']) as $h) {
            $modx->removeCollection(\Booking\Model\HolidayPrice::class, ['holiday_id' => $h->get('id')]);
            $h->remove();
        }
        $out(['removed' => count($ids)]);
        break;
    default:
        fwrite(STDERR, "команды: request, confirm, token, addon, cleanup\n");
        exit(1);
}
