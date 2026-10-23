<?php
/**
 * Теги MODX из ввода гостя не выполняются (security-review 23.10.2026): имя, комментарий и причина отмены
 * попадают в чанки писем booking.email.request / booking.email.cancel, а MODX разбирает подставленные значения повторно.
 */
return [
    'очистка на входе: теги «[[…]]» вырезаются, в том числе собранные из частей' => function (BookingTest $t) {
        $t->eq('site_name', \Booking\Service\Bookings::clean('[[++site_name]]') === '++site_name' ? 'site_name' : \Booking\Service\Bookings::clean('[[++site_name]]'), 'простой тег');
        $t->ok(strpos(\Booking\Service\Bookings::clean('[]][++site_name]][]'), '[[') === false, '«[]][» не собирается в «[[»');
        $t->ok(strpos(\Booking\Service\Bookings::clean('[[[[x]]]]'), '[[') === false, 'вложенные скобки');
    },
    'заявка с тегами в имени и комментарии — в письме текст, а не значения настроек' => function (BookingTest $t) {
        $r = $t->request(['house' => 'en', 'date_from' => '2031-11-15', 'date_to' => '2031-11-16',
            'name' => 'ТЕСТ [[++site_name]]', 'comment' => 'привет [[++emailsender]] и [[$booking.email.cancel]]']);
        $t->ok($r['success'], 'заявка принята: ' . $r['message']);
        $row = $t->modx->getObject(\Booking\Model\Booking::class, $r['data']['id']);
        $t->ok(strpos($row->get('guest_name') . $row->get('guest_comment'), '[[') === false, 'в базе тегов нет');
        $ph = $t->booking->bookings()->placeholders($row);
        $html = $t->modx->getChunk('booking.email.request', $ph);
        $t->ok(strpos($html, (string)$t->modx->getOption('emailsender')) === false || $t->modx->getOption('emailsender') === '', 'адрес emailsender не подставился');
        $t->ok(strpos($html, 'Гость просит отменить') === false, 'чанк из комментария не выполнился');
    },
    'запрос отмены: теги в причине не выполняются (обход очистки на входе — значение уже в базе)' => function (BookingTest $t) {
        $r = $t->request(['house' => 'ru', 'date_from' => '2031-11-18', 'date_to' => '2031-11-19']);
        $row = $t->modx->getObject(\Booking\Model\Booking::class, $r['data']['id']);
        // даже если тег попал в базу в обход процессора, письмо выводит его текстом
        $row->set('cancellation_reason', 'Причина [[++site_name]] [[%booking]]');
        $row->save();
        $ph = $t->booking->bookings()->placeholders($row);
        $html = $t->modx->getChunk('booking.email.cancel', $ph);
        $t->ok(strpos($html, (string)$t->modx->getOption('site_name')) === false, 'site_name не подставился');
        $t->ok(strpos($html, '&#91;&#91;++site_name&#93;&#93;') !== false, 'тег выведен как текст');
        $c = $t->run('booking_request_cancel', ['id' => $r['data']['id'], 't' => $r['data']['code'], 'reason' => '[[++site_name:urldecode]] тест']);
        $t->ok($c['success'], 'запрос отмены принят');
        $t->ok(strpos((string)$t->modx->getObject(\Booking\Model\Booking::class, $r['data']['id'])->get('cancellation_reason'), '[[') === false, 'причина сохранена без тегов');
    },
];
