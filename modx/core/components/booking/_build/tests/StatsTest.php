<?php
/**
 * Статистика: брони считаются по дате заезда, ночи — те, что попали в период; отменённые — только в заявках и отменах.
 */
return [
    'статистика: брони, ночи, загрузка, дни недели, сравнение с прошлым периодом' => function (BookingTest $t) {
        $t->admin();
        $active = $t->modx->getCount(\Booking\Model\House::class, ['active' => 1]);
        // пятница 07.03.2031 → воскресенье 09.03: ночи пятницы и субботы
        $a = $t->run('booking_create_manual', ['house' => 'ru', 'date_from' => '2031-03-07', 'date_to' => '2031-03-09', 'guests' => 8,
            'guest_name' => 'ТЕСТ Статистика', 'guest_phone' => '+79005550001']);
        $t->ok($a['success'], 'бронь создана: ' . $a['message']);
        // бронь 31.03 → 02.04: в марте одна ночь, заезд в марте
        $c = $t->run('booking_create_manual', ['house' => 'en', 'date_from' => '2031-03-31', 'date_to' => '2031-04-02', 'guests' => 4,
            'guest_name' => 'ТЕСТ Статистика 2', 'guest_phone' => '+79005550002']);
        $r = $t->request(['house' => 'sv', 'date_from' => '2031-03-14', 'date_to' => '2031-03-15', 'guests' => 3]);
        $t->admin();
        $t->run('booking_cancel', ['id' => $r['data']['id'], 'reason' => 'ТЕСТ']);
        $s = $t->run('booking_stats', ['from' => '2031-03-01', 'to' => '2031-03-31']);
        $t->ok($s['success'], 'статистика считается: ' . $s['message']);
        $k = $s['data']['kpi'] ?? [];
        $t->eq(2, $k['bookings'] ?? null, 'две брони с заездом в марте');
        $t->eq(3, $k['nights'] ?? null, 'ночей в марте: 2 + 1 (вторая бронь уходит в апрель)');
        $t->eq((int)$a['data']['total'] + (int)$c['data']['total'], $k['revenue'] ?? null, 'сумма — по расчёту броней');
        $t->eq(round(3 * 100 / (31 * $active), 1), $k['occupancy'] ?? null, 'загрузка — занятые ночи из всех ночей активных Домов');
        $t->eq(3, $k['requests'] ?? null, 'заявок три, с отменённой');
        $t->eq(1, $k['cancelled'] ?? null, 'одна отменена');
        $t->eq(66.7, $k['conversion'] ?? null, 'заявки → брони: 2 из 3 решённых');
        $wd = $s['data']['weekdays'] ?? [];
        $t->ok(($wd[4]['nights'] ?? 0) === 1 && ($wd[5]['nights'] ?? 0) === 1, 'ночи пятницы и субботы');
        $t->ok(($wd[4]['checkins'] ?? 0) === 1 && ($wd[0]['checkins'] ?? 0) === 1, 'заезды: пятница 07.03 и понедельник 31.03');
        $t->eq(1, $wd[0]['nights'] ?? null, 'ночь понедельника 31.03');
        $ru = array_values(array_filter($s['data']['by_house'] ?? [], fn($h) => $h['code'] === 'ru'))[0] ?? [];
        $t->eq(2, $ru['nights'] ?? null, 'Русский Дом: 2 ночи');
        $m = $s['data']['months'] ?? [];
        $t->eq(1, count($m), 'один месяц');
        $t->eq(2, $m[0]['nights']['ru'] ?? null, 'сезонность: ночи по Дому');
        $t->eq(31, $s['data']['days'] ?? null, 'дней в периоде');
        $t->eq('2031-01-29', $s['data']['prev_from'] ?? null, 'прошлый период — такой же длины перед этим');

        $t->eq(400, $t->run('booking_stats', ['from' => '2031-03-10', 'to' => '2031-03-01'])['status'], 'конец раньше начала — 400');
        $t->eq(400, $t->run('booking_stats', ['from' => '2020-01-01', 'to' => '2031-03-01'])['status'], 'больше 3 лет — 400');
        $t->guest();
        $t->eq(401, $t->run('booking_stats', [])['status'], 'гостю статистика недоступна');
    },
];
