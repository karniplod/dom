<?php
/**
 * Куда уходят письма о заявках: booking.admin_email → «Почта для заявок» из «Конфигурации» (bn_lead_email) → emailsender.
 */
return [
    'почта для заявок: своя бронирования, иначе из «Конфигурации»' => function (BookingTest $t) {
        $modx = $t->modx;
        $notify = $t->booking->notify();
        $was = (string)$modx->getOption('booking.admin_email', null, '');
        $lead = $t->booking->faq()->cc('bn_lead_email');
        try {
            $modx->setOption('booking.admin_email', '');
            $t->eq($lead !== '' ? $lead : (string)$modx->getOption('emailsender'), $notify->adminEmail(), 'пусто — «Почта для заявок» из «Конфигурации»');
            $modx->setOption('booking.admin_email', 'test-booking@example.com');
            $t->eq('test-booking@example.com', $notify->adminEmail(), 'задана своя почта бронирования — она');
        } finally {
            $modx->setOption('booking.admin_email', $was);
        }
    },
    'MAX о новой заявке: коротко, без служебных полей формы, с кнопками' => function (BookingTest $t) {
        $modx = $t->modx;
        $r = $t->request(['house' => 'en', 'date_from' => '2031-12-07', 'date_to' => '2031-12-08', 'guests' => 3]);
        $t->ok($r['success'], 'заявка создана');
        $row = $modx->getObject(\Booking\Model\Booking::class, (int)$r['data']['id']);
        $bot = $t->booking->maxBot();
        $calls = [];
        $bot->transport = function ($method, $path, $query, $body) use (&$calls) {
            $calls[] = compact('method', 'path', 'query', 'body');
            return [200, ['message' => ['body' => ['mid' => 'm1']]]];
        };
        $keys = ['maxnotify3.enabled', 'maxnotify3.max_token', 'maxnotify3.max_recipient_ids', 'maxnotify3.max_recipient_type'];
        $was = [];
        foreach ($keys as $k) $was[$k] = $modx->getOption($k);
        try {
            $modx->setOption('maxnotify3.enabled', 1);
            $modx->setOption('maxnotify3.max_token', 'test-token');
            $modx->setOption('maxnotify3.max_recipient_ids', '-77,-78');
            $modx->setOption('maxnotify3.max_recipient_type', 'chat_id');
            $t->ok($t->booking->notify()->request($row), 'отправлено');
            $t->eq(2, count($calls), 'в каждую группу из max_recipient_ids');
            $t->eq(-77, $calls[0]['query']['chat_id'] ?? null, 'по chat_id');
            $text = $calls[0]['body']['text'] ?? '';
            $t->ok(strpos($text, 'Новая заявка №' . $row->get('id')) === 0, 'начинается с номера заявки');
            foreach (['Английский Дом', '07.12.2031', 'Гостей: 3', 'Сумма:', 'ТЕСТ Гость'] as $s) {
                $t->ok(strpos($text, $s) !== false, 'в тексте есть «' . $s . '»');
            }
            foreach (['_behavior_signature', '_pow_nonce', 'fields', '<tr>', 'client:'] as $s) {
                $t->ok(strpos($text, $s) === false, 'в тексте нет «' . $s . '»');
            }
            $btn = $calls[0]['body']['attachments'][0]['payload']['buttons'] ?? [];
            $urls = array_map(fn($b) => $b[0]['url'] ?? '', $btn);
            $t->ok((bool)preg_grep('~namespace=booking#id=' . $row->get('id') . '$~', $urls), 'кнопка «Открыть в админке»');
        } finally {
            foreach ($was as $k => $v) $modx->setOption($k, $v);
            $bot->transport = [$bot, 'http'];
        }
    },
];
