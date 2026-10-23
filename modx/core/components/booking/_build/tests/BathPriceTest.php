<?php
/**
 * Цена бани за час: по типу дня («Цены») и в праздничную или выбранную дату («Праздники»); пусто — базовая цена бани.
 * Свиязева, базовая цена 1 500 ₽/час; 2031-10-10 — пятница, 2031-10-11 — суббота, 2031-10-14 — вторник.
 */
return [
    'баня: цена по типу дня и в праздник' => function (BookingTest $t) {
        $t->admin();
        $houses = $t->run('booking_manage_house', ['op' => 'list'])['data']['items'];
        $sv = array_values(array_filter($houses, fn($h) => $h['code'] === 'sv'))[0];
        $restoreAddon = ['op' => 'save', 'house' => 'sv', 'bookable_separately' => $sv['addon_bookable_separately'] ? 1 : 0,
            'price_per_hour' => $sv['addon']['price_per_hour'], 'min_hours' => $sv['addon']['min_hours'], 'buffer_minutes' => $sv['addon']['buffer_minutes']];
        $list = $t->run('booking_manage_prices', ['op' => 'list'])['data']['items'];
        $p = array_values(array_filter($list, fn($x) => $x['house'] === 'sv'))[0];
        $save = ['op' => 'save', 'house' => 'sv', 'extra_guest_price' => $p['extra_guest_price'], 'extra_guest_limit' => $p['extra_guest_limit'], 'pet_price' => $p['pet_price']];
        foreach ($p['rules'] as $type => $price) $save['price_' . $type] = $price;
        $holId = 0;
        try {
            $t->run('booking_manage_addon', ['op' => 'save', 'house' => 'sv', 'bookable_separately' => 1, 'price_per_hour' => 1500, 'min_hours' => 2, 'buffer_minutes' => 30]);
            $bad = $t->run('booking_manage_prices', $save + ['addon_saturday' => '-5']);
            $t->eq(400, $bad['status'], 'цена бани — только положительное число или пусто');
            $ok = $t->run('booking_manage_prices', $save + ['addon_saturday' => 2000, 'addon_friday' => 1800]);
            $t->ok($ok['success'], 'цены бани по дням сохранены: ' . $ok['message']);
            $t->eq(2000, $ok['data']['addon']['saturday'] ?? null, 'суббота 2 000 ₽/час');
            $t->ok(array_key_exists('weekday', $ok['data']['addon'] ?? []) && $ok['data']['addon']['weekday'] === null, 'будни пусто — по базовой');

            $q = fn($date) => $t->run('booking_quote', ['house' => 'sv', 'addon' => 1, 'date_from' => $date, 'time_from' => '15:00', 'time_to' => '17:00', 'guests' => 4]);
            $sat = $q('2031-10-11');
            $t->eq(4000, $sat['data']['total'] ?? null, 'суббота: 2 часа × 2 000');
            $t->ok(strpos($sat['data']['lines'][0]['label'] ?? '', 'суббота') !== false, 'в расчёте видно «суббота»: ' . ($sat['data']['lines'][0]['label'] ?? ''));
            $t->eq(3600, $q('2031-10-10')['data']['total'] ?? null, 'пятница: 2 × 1 800');
            $t->eq(3000, $q('2031-10-14')['data']['total'] ?? null, 'вторник: 2 × 1 500 (базовая)');

            // выбранная дата (вторник) — особая цена бани; потом без неё, но по тарифу субботы
            $h = $t->run('booking_manage_holiday', ['op' => 'save', 'date' => '2031-10-14', 'holiday_type' => 'holiday_before_dayoff', 'comment' => 'ТЕСТ день города',
                'prices' => [], 'addon_prices' => ['sv' => 2500]]);
            $t->ok($h['success'], 'дата с особой ценой бани: ' . $h['message']);
            $holId = (int)($h['data']['id'] ?? 0);
            $t->eq(2500, $h['data']['addon_prices']['sv'] ?? null, 'в праздниках сохранено 2 500 ₽/час');
            $hq = $q('2031-10-14');
            $t->eq(5000, $hq['data']['total'] ?? null, 'дата: 2 × 2 500');
            $t->ok(strpos($hq['data']['lines'][0]['label'] ?? '', 'ТЕСТ день города') !== false, 'в расчёте видно название даты: ' . ($hq['data']['lines'][0]['label'] ?? ''));
            $h2 = $t->run('booking_manage_holiday', ['op' => 'save', 'id' => $holId, 'date' => '2031-10-14', 'holiday_type' => 'holiday_before_dayoff', 'comment' => 'ТЕСТ день города',
                'prices' => [], 'addon_prices' => ['sv' => '']]);
            $t->ok($h2['success'], 'особая цена бани снята');
            $t->eq(4000, $q('2031-10-14')['data']['total'] ?? null, 'без особой цены — по тарифу субботы: 2 × 2 000');
            $night = $t->run('booking_quote', ['house' => 'sv', 'date_from' => '2031-10-14', 'date_to' => '2031-10-15', 'guests' => 4]);
            $t->eq((int)$p['rules']['saturday'], $night['data']['total'] ?? null, 'сутки Дома в эту дату — по тарифу субботы (особой цены Дома нет)');
        } finally {
            $t->admin();
            if ($holId) $t->run('booking_manage_holiday', ['op' => 'delete', 'id' => $holId]);
            $t->run('booking_manage_prices', $save + ['addon_saturday' => '', 'addon_friday' => '']);
            $t->run('booking_manage_addon', $restoreAddon);
        }
    },
];
