<?php
/**
 * Баня по часам суток (Addon.rates), доплата за гостей бани сверх нормы, отдельная баня или сауна (kind=bath).
 * Решение владельца 01.11.2026: Свиязева — Пн–Пт до 18:00 1 500 ₽, 18:00–09:00 и Сб, Вс — 2 000 ₽; до 8 гостей, +300 ₽ за гостя в час.
 * Даты 2031: 3 марта — понедельник, 7 — пятница, 15 — суббота.
 */
$state = function (BookingTest $t, string $code): array {
    $t->admin();
    foreach ($t->run('booking_manage_prices', ['op' => 'list'])['data']['items'] as $p) {
        if ($p['house'] === $code) return $p;
    }
    return [];
};
$save = function (BookingTest $t, array $p, array $over = []): array {
    $t->admin();
    $data = ['op' => 'save', 'house' => $p['house'], 'extra_guest_limit' => $p['extra_guest_limit'], 'extra_guest_price' => $p['extra_guest_price'],
        'pet_price' => $p['pet_price'], 'addon_rates' => json_encode($p['addon_rates']), 'addon_guest_limit' => $p['addon_guest_limit'],
        'addon_extra_guest_price' => $p['addon_extra_guest_price'], 'addon_extra_guest_per' => $p['addon_extra_guest_per']];
    foreach (\Booking\Service\Pricing::DAY_TYPES as $d) {
        $data['price_' . $d] = $p['rules'][$d] ?: '';
        $data['addon_' . $d] = $p['addon'][$d] ?? '';
    }
    return $t->run('booking_manage_prices', $over + $data);
};
$quote = function (BookingTest $t, string $house, string $date, string $from, string $to, int $guests = 4): array {
    $t->guest();
    return $t->run('booking_quote', ['house' => $house, 'addon' => 1, 'date_from' => $date, 'time_from' => $from, 'time_to' => $to, 'guests' => $guests]);
};
$rates = [
    ['days' => ['weekday', 'friday'], 'from' => '18:00', 'to' => '09:00', 'price' => 2000],
    ['days' => ['saturday', 'sunday'], 'from' => '00:00', 'to' => '24:00', 'price' => 2000],
];

return [
    'баня Свиязева: будни 16–20 — 2 ч по 1 500 и 2 ч по 2 000; суббота — 2 000; гости сверх 8 — +300 в час' => function (BookingTest $t) use ($state, $save, $quote, $rates) {
        $was = $state($t, 'sv');
        $base = $was;
        $base['addon'] = array_fill_keys(\Booking\Service\Pricing::DAY_TYPES, '');
        $base['addon_rates'] = $rates;
        $base['addon_guest_limit'] = 8;
        $base['addon_extra_guest_price'] = 300;
        $base['addon_extra_guest_per'] = 'hour';
        try {
            $r = $save($t, $base, ['addon_' . 'weekday' => '']);
            $t->ok($r['success'], 'цены по часам сохранены: ' . $r['message']);
            $q = $quote($t, 'sv', '2031-03-03', '16:00', '20:00');
            $t->eq(7000, $q['data']['total'] ?? null, 'понедельник 16:00–20:00 = 2 × 1 500 + 2 × 2 000');
            $labels = implode(' | ', array_column($q['data']['lines'] ?? [], 'label'));
            $t->ok(strpos($labels, '16:00–18:00') !== false && strpos($labels, '18:00–20:00') !== false, 'в расчёте видно часы каждой цены: ' . $labels);
            $t->eq(3000, $quote($t, 'sv', '2031-03-07', '10:00', '12:00')['data']['total'] ?? null, 'пятница днём — 1 500 (Пн–Пт до 18:00)');
            $t->eq(4000, $quote($t, 'sv', '2031-03-07', '19:00', '21:00')['data']['total'] ?? null, 'пятница вечером — 2 000');
            $t->eq(4000, $quote($t, 'sv', '2031-03-15', '12:00', '14:00')['data']['total'] ?? null, 'суббота весь день — 2 000');
            $g = $quote($t, 'sv', '2031-03-15', '12:00', '14:00', 10);
            $t->eq(4000 + 2 * 300 * 2, $g['data']['total'] ?? null, '10 гостей: +2 × 300 ₽ × 2 часа');
            $t->eq(4000, $quote($t, 'sv', '2031-03-15', '12:00', '14:00', 8)['data']['total'] ?? null, '8 гостей — без доплаты');
            $r = $save($t, $base, ['addon_extra_guest_per' => 'booking']);
            $t->eq(4000 + 600, $quote($t, 'sv', '2031-03-15', '12:00', '14:00', 10)['data']['total'] ?? null, 'доплата «за сеанс» — один раз: +2 × 300');
            // день с ценой по типу дня: часы вне промежутков — по ней
            $save($t, $base, ['addon_weekday' => 1700]);
            $t->eq(2 * 1700 + 2 * 2000, $quote($t, 'sv', '2031-03-03', '16:00', '20:00')['data']['total'] ?? null, 'цена «будни» 1 700 — для часов до 18:00');
            $sch = $state($t, 'sv')['addon_schedule'];
            $t->ok(count($sch) >= 2, 'расписание для прайса и бота: ' . json_encode($sch, JSON_UNESCAPED_UNICODE));
            $save($t, $base);
            $sch = $state($t, 'sv')['addon_schedule'];
            $t->eq('Понедельник – пятница', $sch[0]['label'] ?? null, 'Пн–Чт и Пт с одинаковыми ценами — одной строкой');
            $t->eq([['from' => '09:00', 'to' => '18:00', 'price' => 1500], ['from' => '18:00', 'to' => '09:00', 'price' => 2000]], $sch[0]['parts'] ?? null, 'днём 1 500, с 18:00 до 09:00 — 2 000');
            $t->eq('Суббота и воскресенье', $sch[1]['label'] ?? null, 'Сб и Вс — одной строкой');
            $t->eq([['from' => '', 'to' => '', 'price' => 2000]], $sch[1]['parts'] ?? null, 'Сб и Вс — 2 000 весь день');
            // праздник с особой ценой бани — на весь сеанс
            $t->admin();
            $hol = $t->run('booking_manage_holiday', ['op' => 'save', 'date' => '2031-03-03', 'holiday_type' => 'holiday_before_dayoff', 'comment' => 'ТЕСТ',
                'prices' => [], 'addon_prices' => ['sv' => 2500]]);
            $t->ok($hol['success'], 'праздник с особой ценой бани: ' . $hol['message']);
            $t->eq(4 * 2500, $quote($t, 'sv', '2031-03-03', '16:00', '20:00')['data']['total'] ?? null, 'особая цена праздника — на все часы');
            // приложение без поля цен по часам — прежние не стираются
            $t->admin();
            $data = ['op' => 'save', 'house' => 'sv', 'extra_guest_limit' => $was['extra_guest_limit'], 'extra_guest_price' => $was['extra_guest_price'], 'pet_price' => $was['pet_price']];
            foreach (\Booking\Service\Pricing::DAY_TYPES as $d) $data['price_' . $d] = $was['rules'][$d];
            $t->run('booking_manage_prices', $data);
            $t->eq(2, count($state($t, 'sv')['addon_rates']), 'сохранение без addon_rates оставляет цены по часам');
            $bad = $save($t, $base, ['addon_rates' => json_encode([['days' => ['weekday'], 'from' => '18:15', 'to' => '20:00', 'price' => 2000]])]);
            $t->eq(400, $bad['status'], 'время не по 30 минут — ошибка');
            $bad = $save($t, $base, ['addon_rates' => json_encode([['days' => [], 'from' => '18:00', 'to' => '20:00', 'price' => 2000]])]);
            $t->eq(400, $bad['status'], 'без дней — ошибка');
        } finally {
            $save($t, $was);
            foreach ($t->modx->getIterator(\Booking\Model\Holiday::class, ['date' => '2031-03-03', 'comment' => 'ТЕСТ']) as $x) {   // другим тестам — без праздника
                $t->modx->removeCollection(\Booking\Model\HolidayPrice::class, ['holiday_id' => $x->get('id')]);
                $x->remove();
            }
        }
    },
    'сауна (kind=bath): только по часам, в списке Домов для формы помечена, загрузку Домов не портит' => function (BookingTest $t) use ($quote) {
        $m = $t->modx;
        $h = $m->getObject(\Booking\Model\House::class, ['code' => 'sauna']);
        if (!$h) {
            $t->ok(false, 'сауна создана при обновлении пакета');
            return;
        }
        $t->eq('bath', $h->get('kind'), 'сауна — отдельная баня по часам');
        $t->eq('', $h->get('address'), 'адрес не указан (решение владельца)');
        $wasActive = (bool)$h->get('active');
        $t->eq(false, $wasActive, 'сауна после установки выключена');
        try {
            $h->set('active', 1);
            $h->save();
            $t->guest();
            $list = array_column($t->run('booking_houses')['data']['houses'], null, 'code');
            $t->eq('bath', $list['sauna']['kind'] ?? null, 'в форме — с пометкой «по часам»');
            $t->eq('house', $list['sv']['kind'] ?? null, 'Дома — посуточно');
            $no = $t->request(['house' => 'sauna', 'date_from' => '2031-04-07', 'date_to' => '2031-04-08']);
            $t->ok(!$no['success'] && ($no['data']['field'] ?? '') === 'addon', 'сауну на сутки не забронировать: ' . $no['message']);
            $q = $quote($t, 'sauna', '2031-04-07', '16:00', '19:00', 9);
            $t->eq(2 * 1500 + 2000 + 300 * 3, $q['data']['total'] ?? null, 'сауна в понедельник 16–19, 9 гостей: 2 × 1 500 + 2 000 + 300 × 3');
            $ok = $t->request(['house' => 'sauna', 'addon' => 1, 'date_from' => '2031-04-07', 'time_from' => '16:00', 'time_to' => '19:00', 'phone' => '+7 900 555-61-01']);
            $t->ok($ok['success'], 'заявка на сауну по часам принята: ' . $ok['message']);
            $t->admin();
            $grid = $t->run('booking_grid', ['from' => '2031-04-06', 'to' => '2031-04-13']);
            if ($grid['success']) {
                $rows = array_values(array_filter($grid['data']['rows'], fn($r) => $r['code'] === 'sauna'));
                $t->eq(['addon'], array_column($rows, 'type'), 'в сетке у сауны только строка часов');
            }
            $t->admin();
            $bad = $t->run('booking_manage_house', ['op' => 'save', 'kind' => 'spa', 'code' => 'tst', 'name' => 'ТЕСТ', 'capacity_party' => 4, 'checkin_time' => '15:00', 'checkout_time' => '12:00']);
            $t->eq(400, $bad['status'], 'неизвестный вид объекта — ошибка');
        } finally {
            $h->set('active', $wasActive);
            $h->save();
        }
    },
];
