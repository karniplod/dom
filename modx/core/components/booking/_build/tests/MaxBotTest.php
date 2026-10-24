<?php
/**
 * Бот MAX: ответы на общие вопросы по данным сайта, только в личном чате. MAX подменён — запросы копятся в $calls.
 */
return [
    'бот: приветствие, ответы из базы, молчание в группах, повторы, кнопки, запасной режим' => function (BookingTest $t) {
        $b = $t->booking;
        $bot = $b->maxBot();
        $calls = [];
        $answerCode = 200;
        $bot->transport = function ($method, $path, $query, $body) use (&$calls, &$answerCode) {
            $calls[] = compact('method', 'path', 'query', 'body');
            if ($path === '/answers') return [$answerCode, ['success' => $answerCode === 200]];
            if ($path === '/updates') return [200, ['updates' => [
                ['update_type' => 'message_created', 'timestamp' => 1, 'message' => ['sender' => ['user_id' => 501], 'recipient' => ['chat_type' => 'dialog', 'chat_id' => 9],
                    'body' => ['mid' => 'test-poll-' . uniqid(), 'text' => 'во сколько заезд']]],
            ], 'marker' => 777]];
            return [200, ['message' => []]];
        };
        $msg = fn($text, $type = 'dialog', $mid = null) => ['update_type' => 'message_created', 'timestamp' => time(),
            'message' => ['sender' => ['user_id' => 501, 'name' => 'ТЕСТ гость'], 'recipient' => ['chat_type' => $type, 'chat_id' => $type === 'dialog' ? 9 : -77],
                'body' => ['mid' => $mid ?? 'test-' . uniqid('', true), 'text' => $text]]];

        // приветствие при старте
        $t->eq('answered', $bot->handle(['update_type' => 'bot_started', 'timestamp' => microtime(true), 'chat_id' => 9, 'user' => ['user_id' => 501]]), 'старт бота — ответ');
        $c = end($calls);
        $t->eq('/messages', $c['path'], 'ответ — POST /messages');
        $t->eq(501, $c['query']['user_id'] ?? null, 'в личный чат гостя (user_id)');
        $t->ok(strpos($c['body']['text'], 'Здравствуйте') !== false, 'приветствие');
        $kb = $c['body']['attachments'][0] ?? [];
        $t->eq('inline_keyboard', $kb['type'] ?? null, 'кнопки — inline_keyboard');
        $flat = array_merge(...($kb['payload']['buttons'] ?? [[]]));
        $t->ok(in_array('prices', array_column($flat, 'payload'), true), 'в меню есть «Цены»');
        $link = array_values(array_filter($flat, fn($x) => $x['type'] === 'link'));
        $t->ok($link && strpos($link[0]['url'], 'client=max') !== false, 'кнопка «Забронировать» ведёт в приложение с client=max: ' . ($link[0]['url'] ?? '—'));

        // цена — из базы бронирования
        $sv = $b->modx->getObject(\Booking\Model\House::class, ['code' => 'sv']);
        $rules = $b->pricing()->rules((int)$sv->get('id'));
        $calls = [];
        $t->eq('answered', $bot->handle($msg('Сколько стоит Дом на Свиязева?')), 'вопрос о цене — ответ');
        $txt = $calls[0]['body']['text'] ?? '';
        $t->ok(strpos($txt, $b->money((int)$rules['weekday']->get('price'))) !== false, 'цена будней Свиязева — как в «Ценах»: ' . mb_substr($txt, 0, 80));
        $t->ok(strpos($txt, 'Английский') === false, 'спросили про один Дом — про другие не рассказываем');

        // в группе и канале — молчим; повтор события — не отвечаем второй раз
        $calls = [];
        $t->eq('skip:group', $bot->handle($msg('Сколько стоит?', 'chat')), 'в группе администраторов бот молчит');
        $t->eq(0, count($calls), 'в группу ничего не отправлено');
        $mid = 'test-repeat-' . uniqid();
        $bot->handle($msg('привет', 'dialog', $mid));
        $t->eq('skip:repeat', $bot->handle($msg('привет', 'dialog', $mid)), 'MAX повторил событие — второй ответ не уходит');

        // кнопка: ответ меняет сообщение с меню; не вышло — новым сообщением
        $calls = [];
        $cb = fn() => ['update_type' => 'message_callback', 'timestamp' => time(), 'callback' => ['callback_id' => 'cb-' . uniqid('', true), 'payload' => 'times', 'user' => ['user_id' => 501]],
            'message' => ['recipient' => ['chat_type' => 'dialog', 'chat_id' => 9]]];
        $t->eq('answered', $bot->handle($cb()), 'кнопка «Заезд и выезд» — ответ');
        $t->eq('/answers', $calls[0]['path'] ?? '', 'ответ на нажатие — POST /answers');
        $t->ok(isset($calls[0]['query']['callback_id']) && strpos($calls[0]['body']['message']['text'] ?? '', 'заезд с') !== false, 'с callback_id и текстом про заезд');
        $answerCode = 400;
        $calls = [];
        $t->eq('answered', $bot->handle($cb()), 'MAX не принял ответ на нажатие — всё равно ответили');
        $t->eq('/messages', $calls[1]['path'] ?? '', 'запасной путь — новым сообщением');
        $answerCode = 200;

        // запасной режим без домена: забрать события и ответить, маркер запомнить
        $calls = [];
        $t->eq(1, $bot->poll(1), 'poll: одно сообщение — один ответ');
        $t->eq('/updates', $calls[0]['path'] ?? '', 'poll спрашивает GET /updates');
        $t->eq(777, $b->modx->cacheManager->get('bot/marker', [\xPDO\xPDO::OPT_CACHE_KEY => 'booking']), 'маркер сохранён для следующего запроса');
        $b->modx->cacheManager->delete('bot/marker', [\xPDO\xPDO::OPT_CACHE_KEY => 'booking']);

        // вебхук без верного секрета не принимается
        $t->ok(!$bot->checkSecret(''), 'пустой секрет — отказ');
        $t->ok(!$bot->checkSecret('чужой-секрет'), 'чужой секрет — отказ');
    },

    'бот: пост в канал с закреплением, поиск групп и каналов' => function (BookingTest $t) {
        $bot = $t->booking->maxBot();
        $calls = [];
        $bot->transport = function ($method, $path, $query, $body) use (&$calls) {
            $calls[] = compact('method', 'path', 'query', 'body');
            if ($path === '/updates') return [200, ['updates' => [
                ['update_type' => 'bot_added', 'chat_id' => -100, 'is_channel' => true, 'user' => []],
                ['update_type' => 'message_created', 'message' => ['recipient' => ['chat_type' => 'chat', 'chat_id' => -200], 'body' => ['mid' => 'x', 'text' => 'проверка']]],
                ['update_type' => 'message_created', 'message' => ['recipient' => ['chat_type' => 'dialog', 'chat_id' => 5], 'body' => ['mid' => 'y', 'text' => 'привет']]],
            ], 'marker' => 1]];
            if ($path === '/chats/-200') return [200, ['title' => 'ТЕСТ Администраторы']];
            if ($path === '/messages') return [200, ['message' => ['body' => ['mid' => 'mid-77']]]];
            return [200, ['success' => true]];
        };
        $post = $t->booking->faq()->channelPost('https://max.ru/test_bot');
        $t->ok(strpos($post['text'], 'Дом на Свиязева') !== false && mb_stripos($post['text'], 'саун') === false, 'пост: Дома по данным сайта, «баня»');
        $t->eq('https://max.ru/test_bot', $post['buttons'][0][0]['url'] ?? null, 'кнопка «Задать вопрос боту» — ссылка из «Конфигурации»');
        $t->eq('mid-77', $bot->post(-100, $post, true), 'опубликовано в канал');
        $t->eq(-100, $calls[0]['query']['chat_id'] ?? null, 'в канал по chat_id');
        $t->ok(($calls[1]['method'] ?? '') === 'PUT' && ($calls[1]['path'] ?? '') === '/chats/-100/pin' && ($calls[1]['body']['message_id'] ?? '') === 'mid-77', 'пост закреплён');
        $calls = [];
        $chats = $bot->chats();
        $t->eq('канал', $chats[-100] ?? null, 'канал найден по bot_added');
        $t->eq('группа «ТЕСТ Администраторы»', $chats[-200] ?? null, 'группа найдена по сообщению, с названием');
        $t->ok(!isset($chats[5]), 'личные чаты в список не попадают');
        $t->ok(!isset($calls[0]['query']['marker']), 'маркер не передаётся — события остаются для poll');
    },

    'бот: ответы только по данным сайта, «баня», а не «сауна»' => function (BookingTest $t) {
        $faq = $t->booking->faq();
        $qs = ['привет', 'сколько стоит', 'можно снять только сауну?', 'во сколько заезд в русском доме', 'где вы находитесь', 'есть бильярд?',
            'можно курить', 'можно с собакой?', 'хотим отметить день рождения на 18 человек', 'есть скидки?', 'фывапролд', 'свободно на новый год?'];
        foreach ($qs as $q) {
            $a = $faq->answer($q);
            $t->ok(mb_stripos($a['text'], 'саун') === false, "«{$q}»: в ответе нет слова «сауна»");
            $t->ok($a['text'] !== '' && mb_strlen($a['text']) <= 4000, "«{$q}»: ответ есть и короче 4000 знаков");
            $t->ok(count($a['buttons']) >= 4, "«{$q}»: под ответом меню");
        }
        $t->ok(strpos($faq->answer('можно снять только сауну?')['text'], 'по часам') !== false, 'про баню отдельно — по часам');
        $t->ok(strpos($faq->answer('можно курить')['text'], 'Курение') !== false, 'про курение — строка правил');
        $t->ok(strpos($faq->answer('фывапролд')['text'], 'Не нашёл') !== false, 'непонятный вопрос — честно «не нашёл» и телефон');
        $t->ok(strpos($faq->answer('свободно на новый год?')['text'], 'бронировании') !== false, 'свободные даты — не обещаем, ведём в бронирование');
        $card = $faq->answer('', 'house:ru')['text'];
        $t->ok(strpos($card, 'Русский Дом') === 0 && strpos($card, 'заезд') === false && strpos($card, 'Заезд с 17:00') !== false, 'карточка Русского Дома: часы из «Дома и бани»');
    },
];
