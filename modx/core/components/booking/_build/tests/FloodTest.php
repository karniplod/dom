<?php
/**
 * Защита от потока заявок (Bookings::assertNotFlood) и окно предоплаты после заявки (Bookings::prepay).
 */
return [
    'повтор той же заявки — отказ без номера первой; больше 3 новых с одного телефона за сутки — отказ' => function (BookingTest $t) {
        $t->flood(true);
        $phone = '+7 900 555-44-01';
        $a = $t->request(['house' => 'ru', 'date_from' => '2031-05-05', 'date_to' => '2031-05-06', 'phone' => $phone]);
        $t->ok($a['success'], 'первая заявка принята: ' . $a['message']);
        $dup = $t->request(['house' => 'ru', 'date_from' => '2031-05-05', 'date_to' => '2031-05-06', 'phone' => $phone]);
        $t->ok(!$dup['success'] && strpos($dup['message'], 'уже отправлена') !== false && strpos($dup['message'], '№') === false, 'повтор — отказ, номер первой не показан: ' . $dup['message']);
        $t->eq('dates', $dup['data']['field'] ?? null, 'ошибка — у дат');
        $t->request(['house' => 'ru', 'date_from' => '2031-05-12', 'date_to' => '2031-05-13', 'phone' => $phone]);
        $c = $t->request(['house' => 'en', 'date_from' => '2031-05-12', 'date_to' => '2031-05-13', 'phone' => $phone]);
        $t->ok($c['success'], 'третья заявка с номера принята');
        $d = $t->request(['house' => 'sv', 'date_from' => '2031-05-19', 'date_to' => '2031-05-20', 'phone' => $phone]);
        $t->ok(!$d['success'] && ($d['data']['field'] ?? '') === 'phone', 'четвёртая новая с номера — отказ у телефона: ' . $d['message']);
        $t->admin();
        $t->run('booking_cancel', ['id' => $a['data']['id'], 'reason' => 'ТЕСТ']);
        $e = $t->request(['house' => 'sv', 'date_from' => '2031-05-19', 'date_to' => '2031-05-20', 'phone' => $phone]);
        $t->ok($e['success'], 'отменённая не считается — заявка принята');
        $f = $t->request(['house' => 'sv', 'date_from' => '2031-05-26', 'date_to' => '2031-05-27', 'phone' => '+7 900 555-44-02']);
        $t->ok($f['success'], 'другой номер — без ограничений');
        $t->flood(false);
    },
    'с одного адреса больше booking.requests_per_ip за час — отказ' => function (BookingTest $t) {
        $t->flood(true);
        $t->modx->setOption('booking.requests_per_ip', 2);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . mt_rand(1, 250);
        $ok = 0;
        for ($i = 0; $i < 3; $i++) {
            $r = $t->request(['house' => 'en', 'date_from' => '2031-06-0' . (2 + $i * 2), 'date_to' => '2031-06-0' . (3 + $i * 2), 'phone' => '+7 900 555-45-0' . $i]);
            $ok += $r['success'] ? 1 : 0;
        }
        $t->eq(2, $ok, 'две заявки приняты, третья — нет');
        $t->ok(strpos($r['message'], 'Слишком много заявок') !== false, 'понятная причина: ' . $r['message']);
        unset($_SERVER['REMOTE_ADDR']);
        $t->flood(false);
    },
    'окно предоплаты: заявка на Дом — ссылка на страницу предоплаты и 50%; баня, отключение — без окна' => function (BookingTest $t) {
        $m = $t->modx;
        $r = $t->request(['house' => 'en', 'date_from' => '2031-07-07', 'date_to' => '2031-07-08']);
        $row = $m->getObject(\Booking\Model\Booking::class, $r['data']['id']);
        $bk = $t->booking->bookings();
        $p = $bk->prepay($row);
        $tpl = $m->getObject(\MODX\Revolution\modTemplate::class, ['templatename' => 'bn-prepay']);
        if (!$tpl) {
            $t->ok($p === null, 'нет шаблона страницы предоплаты — окна нет');
            return;
        }
        $t->ok(is_array($p) && $p['url'] !== '', 'у Дома со страницей предоплаты — ссылка: ' . json_encode($p, JSON_UNESCAPED_UNICODE));
        $t->eq((int)round((int)$row->get('quote_total') * $p['percent'] / 100), $p['amount'] ?? null, 'сумма — процент от расчёта');
        $t->ok(preg_match('~^\+7\d{10}$~', $p['tel']) || $p['tel'] === '', 'телефон для звонка — +7 и 10 цифр');
        foreach (['ru', 'sv'] as $code) {
            $h = $m->getObject(\Booking\Model\House::class, ['code' => $code]);
            $t->ok($bk->housePage($h) > 0, "страница Дома «{$code}» находится и без «Страницы сайта»");
        }
        $m->setOption('booking.prepay_window', 0);
        $t->ok($bk->prepay($row) === null, 'окно выключено настройкой');
        $m->setOption('booking.prepay_window', 1);
        $row->set('status', 'cancelled');
        $t->ok($bk->prepay($row) === null, 'отменённая — без окна');
        $row->set('status', 'new');
        $row->set('addon_id', 1);
        $t->ok($bk->prepay($row) === null, '«только баня» — без окна');
    },
];
