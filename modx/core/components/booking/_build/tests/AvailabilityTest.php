<?php
/**
 * Занятость и пересечения (FR-012, FR-018, FR-019, FR-022, FR-030, SC-002, SC-004):
 * новая заявка даты не занимает; подтверждение — только при свободных ночах (409 с номером брони);
 * выезд и заезд в один день не пересекаются; закрытые даты; отмена освобождает ночи; залог 5 000–20 000;
 * баня: буфер 30 минут, сеанс в ночь подтверждённой брони дома запрещён.
 */
return [
    'новая заявка не занимает даты, подтверждённая — занимает' => function (BookingTest $t) {
        $a = $t->request(['house' => 'ru', 'date_from' => '2031-07-01', 'date_to' => '2031-07-03', 'name' => 'ТЕСТ Первый']);
        $t->ok($a['success'], 'заявка A создана: ' . $a['message']);
        $t->eq('new', $a['data']['status'] ?? null, 'статус new');
        $t->ok(preg_match('~^[a-f0-9]{32}$~', $a['data']['code'] ?? '') === 1, 'код гостя — 32 hex');
        $b = $t->request(['house' => 'ru', 'date_from' => '2031-07-02', 'date_to' => '2031-07-04', 'name' => 'ТЕСТ Второй']);
        $t->ok($b['success'], 'заявка B на пересекающиеся даты тоже принята (new не занимает)');

        $t->admin();
        $c = $t->run('booking_confirm', ['id' => $a['data']['id'], 'deposit_amount' => 10000]);
        $t->ok($c['success'], 'A подтверждена: ' . $c['message']);
        $t->eq('pending', $c['data']['deposit_status'] ?? null, 'залог 10 000 → ожидается');

        $t->guest();
        $av = $t->run('booking_get_availability', ['house' => 'ru', 'from' => '2031-07-01', 'to' => '2031-07-10']);
        $t->eq(['2031-07-01', '2031-07-02'], $av['data']['busy'], 'заняты ночи 1 и 2 июля, день выезда 3 июля свободен');
        $t->ok(!isset($av['data']['nights_blocked']), 'гость видит один список busy');

        $t->admin();
        $c2 = $t->run('booking_confirm', ['id' => $b['data']['id']]);
        $t->eq(409, $c2['status'], 'B не подтверждается — пересечение');
        $t->eq($a['data']['id'], $c2['data']['conflict']['booking_id'] ?? null, 'в ответе номер брони A');
        $again = $t->run('booking_confirm', ['id' => $a['data']['id']]);
        $t->eq(400, $again['status'], 'повторное подтверждение A — отказ, без дублей');
        $nights = $t->modx->getCount(\Booking\Model\Night::class, ['booking_id' => $a['data']['id']]);
        $t->eq(2, $nights, 'ночей A записано ровно 2');

        $g = $t->request(['house' => 'ru', 'date_from' => '2031-07-02', 'date_to' => '2031-07-05']);
        $t->eq(409, $g['status'], 'гость не может отправить заявку на занятые даты');
        $t->eq('dates', $g['data']['field'] ?? null, 'ошибка у поля дат');
        $d = $t->request(['house' => 'ru', 'date_from' => '2031-07-03', 'date_to' => '2031-07-05']);
        $t->ok($d['success'], 'заезд в день выезда A — можно');
        $other = $t->request(['house' => 'en', 'date_from' => '2031-07-01', 'date_to' => '2031-07-03']);
        $t->ok($other['success'], 'другой дом на те же даты — можно');

        $t->admin();
        $x = $t->run('booking_cancel', ['id' => $a['data']['id']]);
        $t->eq(400, $x['status'], 'отмена без причины — отказ');
        $x = $t->run('booking_cancel', ['id' => $a['data']['id'], 'reason' => 'ТЕСТ отмена']);
        $t->ok($x['success'], 'A отменена');
        $t->eq('pending', $x['data']['deposit_status'] ?? null, 'отмена не меняет статус залога');
        $t->eq(0, $t->modx->getCount(\Booking\Model\Night::class, ['booking_id' => $a['data']['id']]), 'ночи A освобождены');
        $t->ok($t->modx->getObject(\Booking\Model\Booking::class, $a['data']['id']) !== null, 'запись брони не удалена');
        $c3 = $t->run('booking_confirm', ['id' => $b['data']['id']]);
        $t->ok($c3['success'], 'теперь B подтверждается');
        $done = $t->run('booking_complete', ['id' => $b['data']['id']]);
        $t->eq('completed', $done['data']['status'] ?? null, 'B завершена');
    },
    'залог: только 5 000–20 000, переходы статуса' => function (BookingTest $t) {
        $r = $t->request(['house' => 'en', 'date_from' => '2031-07-20', 'date_to' => '2031-07-21']);
        $t->admin();
        $bad = $t->run('booking_confirm', ['id' => $r['data']['id'], 'deposit_amount' => 3000]);
        $t->eq(400, $bad['status'], 'залог 3 000 — отказ');
        $t->eq('new', $t->modx->getObject(\Booking\Model\Booking::class, $r['data']['id'])->get('status'), 'при отказе бронь не подтвердилась');
        $bad = $t->run('booking_confirm', ['id' => $r['data']['id'], 'deposit_amount' => 25000]);
        $t->eq(400, $bad['status'], 'залог 25 000 — отказ');
        $ok = $t->run('booking_confirm', ['id' => $r['data']['id']]);
        $t->eq('not_set', $ok['data']['deposit_status'] ?? null, 'подтверждение без залога — залог не назначен');
        $s = $t->run('booking_set_deposit', ['id' => $r['data']['id'], 'deposit_amount' => 20000]);
        $t->eq('pending', $s['data']['deposit_status'] ?? null, 'назначили 20 000 → ожидается');
        $s = $t->run('booking_set_deposit', ['id' => $r['data']['id'], 'deposit_status' => 'returned']);
        $t->eq(400, $s['status'], 'нельзя перескочить «внесён»');
        $s = $t->run('booking_set_deposit', ['id' => $r['data']['id'], 'deposit_status' => 'paid']);
        $t->eq('paid', $s['data']['deposit_status'] ?? null, 'внесён');
        $s = $t->run('booking_set_deposit', ['id' => $r['data']['id'], 'deposit_status' => 'returned']);
        $t->eq('returned', $s['data']['deposit_status'] ?? null, 'возвращён');
    },
    'закрытые даты' => function (BookingTest $t) {
        $t->admin();
        $bl = $t->run('booking_manage_block', ['op' => 'save', 'house' => 'sv', 'date_from' => '2031-08-10', 'date_to' => '2031-08-12', 'reason' => 'ТЕСТ ремонт']);
        $t->ok($bl['success'], 'закрыто: ' . $bl['message']);
        $t->guest();
        $av = $t->run('booking_get_availability', ['house' => 'sv', 'from' => '2031-08-01', 'to' => '2031-08-31']);
        $t->eq(['2031-08-10', '2031-08-11'], $av['data']['busy'], 'закрытые ночи серые для гостя');
        $r = $t->request(['house' => 'sv', 'date_from' => '2031-08-11', 'date_to' => '2031-08-13']);
        $t->eq(409, $r['status'], 'заявка на закрытые даты не проходит');
        $r = $t->request(['house' => 'sv', 'date_from' => '2031-08-12', 'date_to' => '2031-08-13']);
        $t->ok($r['success'], 'с последней даты закрытия — можно');
        $t->admin();
        $t->run('booking_confirm', ['id' => $r['data']['id']]);
        $bl2 = $t->run('booking_manage_block', ['op' => 'save', 'house' => 'sv', 'date_from' => '2031-08-12', 'date_to' => '2031-08-14', 'reason' => 'ТЕСТ']);
        $t->eq(409, $bl2['status'], 'закрыть даты подтверждённой брони нельзя');
        $bl3 = $t->run('booking_manage_block', ['op' => 'save', 'house' => 'sv', 'date_from' => '2031-08-20', 'date_to' => '2031-08-21', 'reason' => '']);
        $t->eq(400, $bl3['status'], 'без причины — отказ');
    },
    'бронь по телефону: сразу подтверждена, те же проверки' => function (BookingTest $t) {
        $t->admin();
        $m = $t->run('booking_create_manual', ['house' => 'en', 'date_from' => '2031-09-01', 'date_to' => '2031-09-03', 'guests' => 12,
            'guest_name' => 'ТЕСТ Телефон', 'guest_phone' => '89001112233', 'deposit_amount' => 5000]);
        $t->ok($m['success'], 'создана: ' . $m['message']);
        $t->eq('confirmed', $m['data']['status'] ?? null, 'сразу подтверждена');
        $t->eq('admin', $m['data']['source'] ?? null, 'источник — администратор');
        $t->eq(0, $m['data']['quote_discount'] ?? null, 'скидка ВКонтакте не применяется без галочки');
        $t->eq('+79001112233', $m['data']['guest_phone'] ?? null, 'телефон приведён к +7');
        $m2 = $t->run('booking_create_manual', ['house' => 'en', 'date_from' => '2031-09-02', 'date_to' => '2031-09-04', 'guests' => 2,
            'guest_name' => 'ТЕСТ Телефон 2', 'guest_phone' => '89001112234']);
        $t->eq(409, $m2['status'], 'пересечение — 409');
        $u = $t->run('booking_update', ['id' => $m['data']['id'], 'date_to' => '2031-09-05']);
        $t->ok($u['success'], 'продление брони на свободные даты: ' . $u['message']);
        $t->eq(4, $t->modx->getCount(\Booking\Model\Night::class, ['booking_id' => $m['data']['id']]), 'после продления 4 ночи');
    },
    'баня отдельно: буфер 30 минут, ночь брони дома' => function (BookingTest $t) {
        $t->admin();
        $houses = $t->run('booking_manage_house', ['op' => 'list'])['data']['items'];
        $sv = array_values(array_filter($houses, fn($h) => $h['code'] === 'sv'))[0];
        $restore = ['op' => 'save', 'house' => 'sv', 'bookable_separately' => $sv['addon_bookable_separately'] ? 1 : 0,
            'price_per_hour' => $sv['addon']['price_per_hour'], 'min_hours' => $sv['addon']['min_hours'], 'buffer_minutes' => $sv['addon']['buffer_minutes']];
        try {
            $no = $t->run('booking_manage_addon', ['op' => 'save', 'house' => 'sv', 'bookable_separately' => 1, 'price_per_hour' => '']);
            $t->eq(400, $no['status'], 'включить без цены за час нельзя');
            $on = $t->run('booking_manage_addon', ['op' => 'save', 'house' => 'sv', 'bookable_separately' => 1, 'price_per_hour' => 1500, 'min_hours' => 2, 'buffer_minutes' => 30]);
            $t->ok($on['success'], 'баня Свиязева бронируется отдельно');

            $s1 = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-10', 'time_from' => '15:00', 'time_to' => '18:00', 'guests' => 4]);
            $t->ok($s1['success'], 'сеанс 15–18: ' . $s1['message']);
            $t->eq(3 * 1500, $s1['data']['total'] ?? null, '3 часа × 1 500');
            $short = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-10', 'time_from' => '20:00', 'time_to' => '21:00']);
            $t->eq('time', $short['data']['field'] ?? null, 'меньше 2 часов — отказ');
            $t->admin();
            $c = $t->run('booking_confirm', ['id' => $s1['data']['id']]);
            $t->ok($c['success'], 'сеанс подтверждён');
            $t->guest();
            $slots = $t->run('booking_get_availability', ['house' => 'sv', 'addon' => 1, 'date' => '2031-10-10']);
            $t->eq([['from' => '15:00', 'to' => '18:00']], $slots['data']['slots_busy'] ?? null, 'занятое время бани');
            $s2 = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-10', 'time_from' => '18:00', 'time_to' => '20:00']);
            $t->eq(409, $s2['status'], 'с 18:00 нельзя — уборка 30 минут');
            $s3 = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-10', 'time_from' => '18:30', 'time_to' => '20:30']);
            $t->ok($s3['success'], 'с 18:30 можно');
            $hb = $t->request(['house' => 'sv', 'date_from' => '2031-10-10', 'date_to' => '2031-10-11']);
            $t->eq(409, $hb['status'], 'дом на сутки с подтверждённым сеансом бани — занято');

            $t->admin();
            $house = $t->run('booking_create_manual', ['house' => 'sv', 'date_from' => '2031-10-12', 'date_to' => '2031-10-13', 'guests' => 4, 'guest_name' => 'ТЕСТ Дом', 'guest_phone' => '+79000000001']);
            $t->ok($house['success'], 'дом Свиязева подтверждён на ночь 12.10');
            // Свиязева: заезд 14:00, выезд 11:00, уборка 30 минут — бронь Дома занимает баню по часам
            $s4 = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-12', 'time_from' => '10:00', 'time_to' => '12:00']);
            $t->ok($s4['success'], 'в день заезда до заезда с уборкой (10–12) — можно: ' . $s4['message']);
            $s4b = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-12', 'time_from' => '12:00', 'time_to' => '14:00']);
            $t->eq(409, $s4b['status'], 'в день заезда 12–14 — нельзя: нет 30 минут на уборку до заезда в 14:00');
            $sl = $t->run('booking_get_availability', ['house' => 'sv', 'addon' => 1, 'date' => '2031-10-12']);
            $t->ok(in_array(['from' => '14:00', 'to' => '24:00', 'house' => true], $sl['data']['slots_busy'] ?? [], true), 'гости Дома в занятом времени бани: с 14:00');
            $t->eq(false, $sl['data']['day_busy'] ?? null, 'день заезда не красный — утром баню можно снять');
            $s5 = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-13', 'time_from' => '15:00', 'time_to' => '17:00']);
            $t->ok($s5['success'], 'в день выезда гостей дома — можно');
            $s5b = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-13', 'time_from' => '09:00', 'time_to' => '11:00']);
            $t->eq(409, $s5b['status'], 'в день выезда 9–11 — нельзя: гости ещё в Доме');
            $s5d = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-13', 'time_from' => '11:30', 'time_to' => '13:30']);
            $t->eq(409, $s5d['status'], 'в день выезда с 11:30 — нельзя: уборка Дома после выезда 60 минут');
            $s5c = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-13', 'time_from' => '12:00', 'time_to' => '14:00']);
            $t->ok($s5c['success'], 'в день выезда с 12:00 (выезд 11:00 + уборка Дома 60 минут) — можно');

            // обратно: подтверждённые сеансы бани и бронь Дома
            $t->admin();
            $m1 = $t->run('booking_create_manual', ['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-20', 'time_from' => '10:00', 'time_to' => '12:00', 'guests' => 4, 'guest_name' => 'ТЕСТ Баня', 'guest_phone' => '+79000000002']);
            $m2 = $t->run('booking_create_manual', ['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-22', 'time_from' => '10:00', 'time_to' => '12:00', 'guests' => 4, 'guest_name' => 'ТЕСТ Баня', 'guest_phone' => '+79000000002']);
            $t->ok($m1['success'] && $m2['success'], 'сеансы 20.10 и 22.10 10–12 подтверждены');
            $h20 = $t->request(['house' => 'sv', 'date_from' => '2031-10-20', 'date_to' => '2031-10-21']);
            $t->ok($h20['success'], 'Дом на ночь 20.10: утренний сеанс 20.10 до заезда — не мешает: ' . $h20['message']);
            $h21 = $t->request(['house' => 'sv', 'date_from' => '2031-10-21', 'date_to' => '2031-10-22']);
            $t->eq(409, $h21['status'], 'Дом на ночь 21.10: сеанс 22.10 в 10:00 — гости ещё не выехали');
            // уборка Дома — своё поле: 120 минут → в день выезда баня с 13:00
            $t->admin();
            $houses = $t->run('booking_manage_house', ['op' => 'list'])['data']['items'];
            $svh = array_values(array_filter($houses, fn($h) => $h['code'] === 'sv'))[0];
            $keep = (int)($svh['cleanup_minutes'] ?? 60);
            $up = $t->run('booking_manage_house', ['op' => 'save'] + array_intersect_key($svh, array_flip(['id', 'code', 'name', 'slug', 'address', 'capacity_sleep', 'capacity_party', 'checkin_time', 'checkout_time', 'sort', 'active'])) + ['cleanup_minutes' => 120]);
            $t->ok($up['success'], 'уборка Дома 120 минут сохранена: ' . $up['message']);
            try {
                $c1 = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-13', 'time_from' => '12:30', 'time_to' => '14:30']);
                $t->eq(409, $c1['status'], 'уборка Дома 120 минут: с 12:30 — нельзя');
                $c2 = $t->request(['house' => 'sv', 'addon' => 1, 'date_from' => '2031-10-13', 'time_from' => '13:00', 'time_to' => '15:00']);
                $t->ok($c2['success'], 'уборка Дома 120 минут: с 13:00 — можно');
            } finally {
                $t->admin();
                $t->run('booking_manage_house', ['op' => 'save'] + array_intersect_key($svh, array_flip(['id', 'code', 'name', 'slug', 'address', 'capacity_sleep', 'capacity_party', 'checkin_time', 'checkout_time', 'sort', 'active'])) + ['cleanup_minutes' => $keep]);
            }
            $t->admin();
            $t->guest();
            $cal = $t->run('booking_get_availability', ['house' => 'sv', 'from' => '2031-10-19', 'to' => '2031-10-24']);
            $t->ok(in_array('2031-10-21', $cal['data']['busy'] ?? [], true) && !in_array('2031-10-20', $cal['data']['busy'] ?? [], true),
                'в календаре Дома занята ночь 21.10, а 20.10 свободна');
            $en = $t->request(['house' => 'en', 'addon' => 1, 'date_from' => '2031-10-10', 'time_from' => '15:00', 'time_to' => '17:00']);
            $t->eq('addon', $en['data']['field'] ?? null, 'у Английского баня отдельно выключена');
        } finally {
            $t->admin();
            $t->run('booking_manage_addon', $restore);
        }
    },
];
