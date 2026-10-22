<?php
/**
 * Расчёт цены (FR-003, FR-003a, FR-008, SC-003): тип суток по дню заезда, правило праздников,
 * особая цена праздника у дома, гости сверх нормы и питомцы — один раз, скидка сайта, целые рубли.
 * Дни недели 2031 г.: 03.03 Пн, 06.03 Чт, 07.03 Пт, 08.03 Сб, 09.03 Вс, 05.12 Пт, 06.12 Сб, 07.12 Вс.
 */
return [
    'будни: 3 суток Русского дома, скидка 5%' => function (BookingTest $t) {
        $t->guest();
        $r = $t->run('booking_quote', ['house' => 'ru', 'date_from' => '2031-03-03', 'date_to' => '2031-03-06', 'guests' => 6]);
        $t->ok($r['success'], 'расчёт успешен');
        $t->eq(54000, $r['data']['subtotal'], 'сумма 3 × 18 000');
        $t->eq(2700, $r['data']['discount'], 'скидка 5%');
        $t->eq(51300, $r['data']['total'], 'итог');
        $t->ok(is_int($r['data']['total']), 'итог — целое число рублей');
    },
    'пятница + суббота, 14 гостей (сценарий 1 quickstart)' => function (BookingTest $t) {
        $t->guest();
        $r = $t->run('booking_quote', ['house' => 'ru', 'date_from' => '2031-12-05', 'date_to' => '2031-12-07', 'guests' => 14]);
        $t->eq(22000 + 27000 + 4 * 500, $r['data']['subtotal'], 'Пт 22 000 + Сб 27 000 + 4 гостя × 500');
        $t->eq((int)round(51000 * 0.05), $r['data']['discount'], 'скидка от всей суммы');
        $labels = implode('|', array_column($r['data']['lines'], 'label'));
        $t->ok(strpos($labels, 'Пятница') !== false && strpos($labels, 'Суббота') !== false, 'строки по типам дней');
        $t->ok(strpos($labels, 'Скидка 5%') !== false, 'скидка отдельной строкой');
    },
    'воскресенье, Свиязева' => function (BookingTest $t) {
        $t->guest();
        $r = $t->run('booking_quote', ['house' => 'sv', 'date_from' => '2031-03-09', 'date_to' => '2031-03-10', 'guests' => 8]);
        $t->eq(17000, $r['data']['subtotal'], 'Вс 17 000, 8 гостей — в норме');
    },
    'гости сверх нормы и питомцы — один раз за отдых' => function (BookingTest $t) {
        $t->guest();
        $r = $t->run('booking_quote', ['house' => 'sv', 'date_from' => '2031-03-03', 'date_to' => '2031-03-05', 'guests' => 10, 'pets' => 2]);
        $t->eq(2 * 15000 + 2 * 500 + 2 * 1000, $r['data']['subtotal'], '2 суток + 2 гостя сверх 8 + 2 питомца');
    },
    'правило праздников: праздник перед рабочим днём = воскресенье' => function (BookingTest $t) {
        $t->admin();
        $h = $t->run('booking_manage_holiday', ['op' => 'save', 'date' => '2031-03-06', 'holiday_type' => 'holiday_before_workday', 'comment' => 'ТЕСТ']);
        $t->ok($h['success'], 'праздник сохранён: ' . $h['message']);
        $t->guest();
        $r = $t->run('booking_quote', ['house' => 'ru', 'date_from' => '2031-03-06', 'date_to' => '2031-03-07', 'guests' => 4]);
        $t->eq(19000, $r['data']['subtotal'], 'четверг-праздник по тарифу воскресенья');
        $t->admin();
        $t->run('booking_manage_holiday', ['op' => 'save', 'id' => $h['data']['id'], 'date' => '2031-03-06', 'holiday_type' => 'pre_holiday_workday', 'comment' => 'ТЕСТ']);
        $t->guest();
        $r = $t->run('booking_quote', ['house' => 'ru', 'date_from' => '2031-03-06', 'date_to' => '2031-03-07', 'guests' => 4]);
        $t->eq(22000, $r['data']['subtotal'], 'предпраздничный рабочий = пятница');
        $t->admin();
        $t->run('booking_manage_holiday', ['op' => 'save', 'id' => $h['data']['id'], 'date' => '2031-03-06', 'holiday_type' => 'holiday_before_dayoff', 'comment' => 'ТЕСТ']);
        $t->guest();
        $r = $t->run('booking_quote', ['house' => 'ru', 'date_from' => '2031-03-06', 'date_to' => '2031-03-07', 'guests' => 4]);
        $t->eq(27000, $r['data']['subtotal'], 'праздник перед выходным = суббота');
    },
    'особая цена праздника у дома (FR-003a)' => function (BookingTest $t) {
        $t->admin();
        $h = $t->run('booking_manage_holiday', ['op' => 'save', 'date' => '2031-03-08', 'holiday_type' => 'holiday_before_workday', 'comment' => 'ТЕСТ 8 Марта',
            'prices' => ['ru' => 35000, 'en' => '']]);
        $t->ok($h['success'], 'сохранено: ' . $h['message']);
        $t->eq(35000, $h['data']['prices']['ru'] ?? null, 'особая цена записана');
        $t->guest();
        $ru = $t->run('booking_quote', ['house' => 'ru', 'date_from' => '2031-03-08', 'date_to' => '2031-03-09', 'guests' => 4]);
        $t->eq(35000, $ru['data']['subtotal'], 'Русский дом — особая цена');
        $en = $t->run('booking_quote', ['house' => 'en', 'date_from' => '2031-03-08', 'date_to' => '2031-03-09', 'guests' => 4]);
        $t->eq(19000, $en['data']['subtotal'], 'Английский — по правилу (как воскресенье)');
        $t->admin();
        $bad = $t->run('booking_manage_holiday', ['op' => 'save', 'date' => '2031-03-08', 'holiday_type' => 'holiday_before_workday']);
        $t->eq(400, $bad['status'], 'дата праздника уникальна');
    },
    'проверки входа' => function (BookingTest $t) {
        $t->guest();
        $r = $t->run('booking_quote', ['house' => 'sv', 'date_from' => '2031-03-03', 'date_to' => '2031-03-04', 'guests' => 16]);
        $t->eq(400, $r['status'], 'больше 15 гостей на Свиязева — отказ');
        $t->eq('guests', $r['data']['field'] ?? null, 'ошибка у поля «гости»');
        $r = $t->run('booking_quote', ['house' => 'ru', 'date_from' => '2031-03-04', 'date_to' => '2031-03-04', 'guests' => 2]);
        $t->eq('dates', $r['data']['field'] ?? null, 'выезд не позже заезда — ошибка дат');
        $r = $t->run('booking_quote', ['house' => 'xx', 'date_from' => '2031-03-04', 'date_to' => '2031-03-05', 'guests' => 2]);
        $t->eq('house', $r['data']['field'] ?? null, 'неизвестный дом');
        $r = $t->run('booking_quote', ['house' => 'ru', 'date_from' => '2020-03-04', 'date_to' => '2020-03-05', 'guests' => 2]);
        $t->eq(400, $r['status'], 'прошедшие даты — отказ');
    },
    'админ меняет цену — расчёт и booking.prices видят новую (SC-006)' => function (BookingTest $t) {
        $t->admin();
        $old = $t->run('booking_manage_prices', ['op' => 'list'])['data']['items'];
        $sv = array_values(array_filter($old, fn($i) => $i['house'] === 'sv'))[0];
        $r = $t->run('booking_manage_prices', ['op' => 'save', 'house' => 'sv', 'rules' => array_merge($sv['rules'], ['friday' => 21000]),
            'extra_guest_price' => $sv['extra_guest_price'], 'extra_guest_limit' => $sv['extra_guest_limit'], 'pet_price' => $sv['pet_price']]);
        $t->ok($r['success'], 'цены сохранены');
        $t->guest();
        $q = $t->run('booking_quote', ['house' => 'sv', 'date_from' => '2031-03-07', 'date_to' => '2031-03-08', 'guests' => 2]);
        $t->eq(21000, $q['data']['subtotal'], 'расчёт по новой цене пятницы');
        $html = $t->modx->runSnippet('booking.prices', ['house' => 'sv']);
        $t->ok(strpos($html, '21 000') !== false, 'booking.prices выводит новую цену');
        $t->ok(strpos($html, 'class="hot"') !== false && substr_count($html, '<div') === 4, '4 дня, самый дорогой подсвечен');
        $t->admin();
        $bad = $t->run('booking_manage_prices', ['op' => 'save', 'house' => 'sv', 'rules' => array_merge($sv['rules'], ['friday' => 0])]);
        $t->eq(400, $bad['status'], 'цена 0 — отказ');
        $t->run('booking_manage_prices', ['op' => 'save', 'house' => 'sv', 'rules' => $sv['rules'],
            'extra_guest_price' => $sv['extra_guest_price'], 'extra_guest_limit' => $sv['extra_guest_limit'], 'pet_price' => $sv['pet_price']]);
    },
];
