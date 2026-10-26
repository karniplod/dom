<?php
/**
 * Права (FR-016, FR-024, FR-025, FR-026b, ст. 3.4, 5.3, 7.8): гость не меняет статус и залог,
 * админские действия без токена — 401, отозванный токен не работает, выпуск токенов — только из админки MODX,
 * заявка через api.php не создаётся (только формой SendIt). Часть проверок — живые HTTP-запросы к api.php стенда.
 */
function booking_http(string $method, array $params, string $token = ''): array
{
    $url = getenv('BOOKING_API') ?: 'http://127.0.0.1:8091/assets/components/booking/api.php';
    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 20, 'header' => '']];
    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
    } else {
        $headers[] = 'Content-Type: application/json';
        $opts['http']['content'] = json_encode($params);
    }
    $opts['http']['header'] = implode("\r\n", $headers);
    $body = @file_get_contents($url, false, stream_context_create($opts));
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    $j = json_decode((string)$body, true) ?: [];
    $j['status'] = $status;
    $j['headers'] = $http_response_header ?? [];
    return $j;
}

return [
    'гость не задаёт статус, залог, сумму и источник' => function (BookingTest $t) {
        $r = $t->request(['house' => 'en', 'date_from' => '2031-11-01', 'date_to' => '2031-11-02',
            'status' => 'confirmed', 'deposit_amount' => 20000, 'deposit_status' => 'paid', 'quote_total' => 1, 'source' => 'admin', 'admin_note' => 'взлом']);
        $t->ok($r['success'], 'заявка принята');
        $row = $t->modx->getObject(\Booking\Model\Booking::class, $r['data']['id']);
        $t->eq('new', $row->get('status'), 'статус — new');
        $t->eq(null, $row->get('deposit_amount'), 'залог не назначен');
        $t->eq('not_set', $row->get('deposit_status'), 'статус залога not_set');
        $t->eq('site', $row->get('source'), 'источник — site');
        $t->ok((int)$row->get('quote_total') > 1, 'сумма посчитана сервером');
        $t->eq('', (string)$row->get('admin_note'), 'заметку гость не пишет');
        $t->eq(0, $t->modx->getCount(\Booking\Model\Night::class, ['booking_id' => $r['data']['id']]), 'даты не заняты');
    },
    'гостю недоступны админские действия' => function (BookingTest $t) {
        $r = $t->request(['house' => 'en', 'date_from' => '2031-11-05', 'date_to' => '2031-11-06']);
        $t->guest();
        foreach (['booking_confirm' => ['id' => $r['data']['id']], 'booking_cancel' => ['id' => $r['data']['id'], 'reason' => 'x'],
                     'booking_set_deposit' => ['id' => $r['data']['id'], 'deposit_amount' => 5000], 'booking_list' => [],
                     'booking_manage_prices' => ['op' => 'list'], 'booking_issue_token' => ['op' => 'list'], 'booking_grid' => ['from' => '2031-11-01', 'to' => '2031-11-10']] as $a => $p) {
            $x = $t->run($a, $p);
            $t->eq(401, $x['status'], $a . ' без входа → 401');
        }
        $t->eq('new', $t->modx->getObject(\Booking\Model\Booking::class, $r['data']['id'])->get('status'), 'заявка не изменилась');
    },
    'гость видит свою заявку только по коду или телефону' => function (BookingTest $t) {
        $r = $t->request(['house' => 'ru', 'date_from' => '2031-11-10', 'date_to' => '2031-11-11', 'phone' => '+7 (900) 555-44-33']);
        $id = $r['data']['id'];
        $t->guest();
        $ok = $t->run('booking_get_request', ['id' => $id, 't' => $r['data']['code']]);
        $t->ok($ok['success'], 'по коду из ссылки');
        $t->ok(!isset($ok['data']['guest_phone']) && !isset($ok['data']['admin_note']), 'без служебных полей');
        $t->ok($t->run('booking_get_request', ['id' => $id, 'phone' => '89005554433'])['success'], 'по номеру и телефону');
        $t->eq(403, $t->run('booking_get_request', ['id' => $id, 'phone' => '89005554400'])['status'], 'чужой телефон — 403');
        $t->eq(403, $t->run('booking_get_request', ['id' => $id, 't' => str_repeat('a', 32)])['status'], 'чужой код — 403');
        $t->eq(403, $t->run('booking_get_request', ['id' => $id + 100000, 'phone' => '89005554433'])['status'], 'нет такой — тот же 403');
        $byPhone = $t->run('booking_request_cancel', ['id' => $id, 'phone' => '89005554433', 'reason' => 'чужой']);
        $t->eq(403, $byPhone['status'], 'отмена по номеру и телефону — нельзя (телефон знают и чужие): только по ссылке с кодом');
        $t->ok(!$t->modx->getObject(\Booking\Model\Booking::class, $id)->get('cancellation_requested'), 'запрос отмены не поставлен');
        $c = $t->run('booking_request_cancel', ['id' => $id, 't' => $r['data']['code'], 'reason' => 'заболели']);
        $t->ok($c['success'] && $c['data']['cancellation_requested'], 'запрос отмены отмечен');
        $t->eq('new', $c['data']['status'], 'статус не изменился');
        $row = $t->modx->getObject(\Booking\Model\Booking::class, $id);
        $t->eq('guest', $row->get('cancellation_requested_by'), 'отмену запросил гость');
    },
    'токены: выпуск только в админке, отзыв' => function (BookingTest $t) {
        $t->admin('api');
        $x = $t->run('booking_issue_token', ['op' => 'issue', 'label' => 'ТЕСТ api']);
        $t->eq(403, $x['status'], 'выпуск токена через api — 403');
        $t->admin('cmp');
        $iss = $t->run('booking_issue_token', ['op' => 'issue', 'label' => 'ТЕСТ телефон']);
        $t->ok($iss['success'] && strlen($iss['data']['token'] ?? '') >= 40, 'токен выпущен');
        $token = $iss['data']['token'];
        $row = $t->modx->getObject(\Booking\Model\AdminToken::class, $iss['data']['id']);
        $t->eq(hash('sha256', $token), $row->get('token_hash'), 'в БД — SHA-256');
        $t->ok(strpos(json_encode($row->toArray()), $token) === false, 'сам токен в БД не хранится');
        $who = $t->booking->access()->byToken($token, 'pwa');
        $t->eq('admin', $who['role'] ?? null, 'токен даёт роль администратора');
        $t->ok(strpos($who['name'], 'ТЕСТ телефон') !== false, 'владелец токена — для журнала');
        $GLOBALS['booking_test_token'] = $token;
        $GLOBALS['booking_test_token_id'] = $iss['data']['id'];
    },
    'HTTP: api.php — 401 без токена, работа с токеном, отзыв' => function (BookingTest $t) {
        $token = $GLOBALS['booking_test_token'] ?? '';
        $r = $t->request(['house' => 'sv', 'date_from' => '2031-11-20', 'date_to' => '2031-11-22']);
        $id = $r['data']['id'];

        $g = booking_http('GET', ['action' => 'booking_get_availability', 'house' => 'sv', 'from' => '2031-11-01', 'to' => '2031-12-01']);
        $t->eq(200, $g['status'], 'занятость для гостя');
        $t->ok((bool)array_filter($g['headers'], fn($h) => stripos($h, 'Cache-Control: no-store') === 0), 'ответ с Cache-Control: no-store');

        $x = booking_http('POST', ['action' => 'booking_confirm', 'id' => $id]);
        $t->eq(401, $x['status'], 'подтверждение без токена → 401');
        $x = booking_http('POST', ['action' => 'booking_confirm', 'id' => $id, 'status' => 'confirmed'], 'wrong-token-wrong-token-1234');
        $t->eq(401, $x['status'], 'неверный токен → 401');
        $x = booking_http('POST', ['action' => 'booking_create_request', 'house' => 'sv', 'date_from' => '2031-11-25', 'date_to' => '2031-11-26', 'guests' => 2, 'name' => 'ТЕСТ', 'phone' => '+79000000000']);
        $t->eq(400, $x['status'], 'заявка через api.php не создаётся — только формой SendIt');
        $x = booking_http('GET', ['action' => 'booking_confirm', 'id' => $id], $token);
        $t->eq(405, $x['status'], 'изменения — только POST');

        $ok = booking_http('POST', ['action' => 'booking_confirm', 'id' => $id, 'deposit_amount' => 7000, 'client' => 'pwa'], $token);
        $t->eq(200, $ok['status'], 'с токеном подтверждение работает: ' . ($ok['message'] ?? ''));
        $conf = $t->request(['house' => 'sv', 'date_from' => '2031-11-21', 'date_to' => '2031-11-23']);
        $t->eq(409, $conf['status'], 'занятые через PWA даты заняты и для формы сайта');
        $other = $t->request(['house' => 'sv', 'date_from' => '2031-11-22', 'date_to' => '2031-11-23']);
        $x = booking_http('POST', ['action' => 'booking_set_deposit', 'id' => $id, 'deposit_amount' => 3000, 'client' => 'max'], $token);
        $t->eq(400, $x['status'], 'та же проверка залога в MAX/PWA');
        $log = $t->modx->getObject(\Booking\Model\LogEntry::class, ['entity' => 'booking', 'entity_id' => $id, 'action' => 'confirm']);
        $t->eq('pwa', $log ? $log->get('interface') : null, 'в журнале интерфейс pwa');
        $t->ok($log && strpos($log->get('actor'), 'ТЕСТ телефон') !== false, 'в журнале владелец токена');
        $x = booking_http('POST', ['action' => 'booking_issue_token', 'op' => 'issue', 'label' => 'ТЕСТ x'], $token);
        $t->eq(403, $x['status'], 'токен не выпускает новые токены');

        $t->admin('cmp');
        $t->run('booking_issue_token', ['op' => 'revoke', 'id' => $GLOBALS['booking_test_token_id'] ?? 0]);
        $x = booking_http('POST', ['action' => 'booking_confirm', 'id' => $other['data']['id']], $token);
        $t->eq(401, $x['status'], 'отозванный токен → 401');
    },
];
