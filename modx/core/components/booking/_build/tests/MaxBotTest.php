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
        $drained = false;   // MAX отдаёт событие один раз: второй запрос — пустой
        $wasChats = (string)$t->modx->getOption('booking.bot_chats', null, '');
        $t->modx->setOption('booking.bot_chats', '');
        $bot->transport = function ($method, $path, $query, $body) use (&$calls, &$drained) {
            $calls[] = compact('method', 'path', 'query', 'body');
            if ($path === '/updates' && $drained) return [200, ['updates' => [], 'marker' => 2]];
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
        $drained = true;
        $again = $bot->chats();
        $t->ok(isset($again[-100], $again[-200]), 'второе нажатие: MAX событий уже не отдаёт, а группа и канал в списке остаются');
        $st = $t->modx->getObject(\MODX\Revolution\modSystemSetting::class, ['key' => 'booking.bot_chats']);
        if ($st) { if ($wasChats === '') { $st->remove(); } else { $st->set('value', $wasChats); $st->save(); } }
        $t->modx->setOption('booking.bot_chats', $wasChats);
        $t->modx->cacheManager->refresh(['system_settings' => []]);

        // вебхук (в том числе при выключенных ответах) запоминает группы и каналы — кнопка в админке их покажет
        $opt = [\xPDO\xPDO::OPT_CACHE_KEY => 'booking'];
        $was = (string)$t->modx->getOption('booking.bot_chats', null, '');
        $t->modx->setOption('booking.bot_chats', '');
        $bot->observe(['update_type' => 'bot_added', 'chat_id' => -300, 'is_channel' => false, 'user' => []]);
        $bot->handle(['update_type' => 'message_created', 'message' => ['recipient' => ['chat_type' => 'channel', 'chat_id' => -400], 'body' => ['mid' => 'ch-' . uniqid(), 'text' => 'пост']]]);
        $bot->observe(['update_type' => 'message_created', 'message' => ['recipient' => ['chat_type' => 'dialog', 'chat_id' => 7], 'body' => ['mid' => 'z', 'text' => 'х']]]);
        $mem = $bot->known();
        $t->eq(['-300' => 'группа', '-400' => 'канал'], array_map('strval', array_combine(array_map('strval', array_keys($mem)), $mem)), 'запомнены группа и канал, личный чат — нет');
        $t->ok(isset($bot->chats()[-300]), 'кнопка «Найти группы и каналы» показывает запомненные');
        // вернуть, как было до теста (в базе и в памяти)
        $st = $t->modx->getObject(\MODX\Revolution\modSystemSetting::class, ['key' => 'booking.bot_chats']);
        if ($st) { $was === '' ? $st->remove() : ($st->set('value', $was) || true) && $st->save(); }
        $t->modx->setOption('booking.bot_chats', $was);
        $t->modx->cacheManager->refresh(['system_settings' => []]);
        $t->admin('cmp');
        $r = $t->run('booking_max_chats');
        $t->ok($r['status'] === 400 || $r['success'], 'booking_max_chats из админки отвечает (без токена — понятная ошибка): ' . $r['message']);
        $t->admin('pwa');
        $t->eq(403, $t->run('booking_max_chats')['status'], 'из приложения по токену — нельзя (только админка MODX)');
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

    'бот: уборка — тестовые номера групп убраны из booking.bot_chats' => function (BookingTest $t) {
        $list = $t->booking->maxBot()->known();
        foreach ([-77, -100, -200, -300, -400] as $id) unset($list[$id], $list[(string)$id]);
        $st = $t->modx->getObject(\MODX\Revolution\modSystemSetting::class, ['key' => 'booking.bot_chats']);
        if ($st) { if (!$list) { $st->remove(); } else { $st->set('value', json_encode($list, JSON_UNESCAPED_UNICODE)); $st->save(); } }
        $t->modx->setOption('booking.bot_chats', $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : '');
        $t->modx->cacheManager->refresh(['system_settings' => []]);
        $t->ok(!array_intersect(array_map('intval', array_keys($t->booking->maxBot()->known())), [-77, -100, -200, -300, -400]), 'тестовых номеров не осталось');
    },
    'бот: «Подключить бота» в админке — адрес сайта в MAX, прежняя подписка снята, бот включён' => function (BookingTest $t) {
        $modx = $t->modx;
        $bot = $t->booking->maxBot();
        $subs = [['url' => 'https://example.ru/assets/components/booking/bot.php'], ['url' => 'https://other.ru/hook']];
        $calls = [];
        $bot->transport = function ($method, $path, $query, $body) use (&$calls, &$subs) {
            $calls[] = $method . ' ' . $path . ' ' . ($query['url'] ?? ($body['url'] ?? ''));
            if ($method === 'GET') return [200, ['subscriptions' => $subs]];
            if ($method === 'DELETE') { $subs = array_values(array_filter($subs, fn($s) => $s['url'] !== $query['url'])); return [200, ['success' => true]]; }
            $subs[] = ['url' => $body['url']];
            return [200, ['success' => true]];
        };
        $keys = ['maxnotify3.max_token', 'site_url', 'booking.bot_enabled'];
        $was = [];
        foreach ($keys as $k) $was[$k] = $modx->getOption($k);
        $st = $modx->getObject(\MODX\Revolution\modSystemSetting::class, ['key' => 'booking.bot_enabled']);
        $wasDb = $st ? $st->get('value') : null;
        try {
            $modx->setOption('maxnotify3.max_token', 't');
            $modx->setOption('site_url', 'https://example.ru/');
            $t->admin('cmp');
            $r = $t->run('booking_max_webhook', ['op' => 'on']);
            $t->ok($r['success'], 'подключено: ' . $r['message']);
            $t->eq('https://example.ru/assets/components/booking/bot.php', $r['data']['url'] ?? null, 'адрес приёма — bot.php сайта');
            $t->ok(in_array('DELETE /subscriptions https://example.ru/assets/components/booking/bot.php', $calls, true), 'прежняя подписка сайта снята (старый секрет)');
            $t->ok(!in_array('DELETE /subscriptions https://other.ru/hook', $calls, true), 'чужие подписки не трогаем');
            $t->ok($r['data']['webhook'] && $r['data']['enabled'], 'бот включён и адрес подключён');
            $r = $t->run('booking_max_webhook', ['op' => 'off']);
            $t->ok($r['success'] && !$r['data']['webhook'] && !$r['data']['enabled'], 'отключено');
            $modx->setOption('site_url', 'http://example.ru/');
            $t->eq(400, $t->run('booking_max_webhook', ['op' => 'on'])['status'], 'без https — понятная ошибка');
            $t->admin('pwa');
            $t->eq(403, $t->run('booking_max_webhook', ['op' => 'status'])['status'], 'из приложения по токену — нельзя');
        } finally {
            foreach ($was as $k => $v) $modx->setOption($k, $v);
            $st = $modx->getObject(\MODX\Revolution\modSystemSetting::class, ['key' => 'booking.bot_enabled']);
            if ($st && $wasDb !== null) { $st->set('value', $wasDb); $st->save(); }
            $modx->cacheManager->refresh(['system_settings' => []]);
            $bot->transport = [$bot, 'http'];
        }
    },
    'бот: вопросы своими словами, в разных формах — нужная тема' => function (BookingTest $t) {
        $faq = $t->booking->faq();
        $m = new \ReflectionMethod($faq, 'intents');
        $m->setAccessible(true);
        $cases = [
            'почём сутки' => 'prices', 'во что обойдётся ночь' => 'prices', 'прайс скиньте' => 'prices',
            'когда можно заехать' => 'times', 'до скольки выезжать' => 'times', 'можно пораньше заселиться' => 'times',
            'как к вам доехать' => 'address', 'далеко от центра?' => 'address', 'куда подъехать на такси' => 'address', 'есть на яндекс картах?' => 'address',
            'как с вами связаться' => 'contacts', 'есть ватсап?' => 'contacts', 'хочу поговорить с живым человеком' => 'contacts',
            'нас 15 человек, разместимся?' => 'capacity', 'хотим отметить др' => 'capacity', 'девичник' => 'capacity',
            'можно арендовать дом' => 'book', 'есть свободные даты на выходные' => 'book',
            'какие у вас коттеджи' => 'houses', 'покажите фотки' => 'houses',
            'можно попариться пару часов' => 'bath', 'баньку на 3 часа' => 'bath',
            'есть скидочки?' => 'discount', 'можно заказать шашлык' => 'cook', 'есть мангал?' => 'cook',
            'с котом можно?' => 'rules', 'какой залог' => 'rules', 'здрасьте' => 'start', 'доброе утро' => 'start',
        ];
        foreach ($cases as $q => $want) {
            $got = $m->invoke($faq, $faq->norm($q));
            $t->eq($want, $got[0] ?? null, "«{$q}» — тема {$want}");
        }
        $t->ok(strpos($faq->answer('отлично спасибо')['text'], 'Пожалуйста') === 0, '«отлично спасибо» — «Пожалуйста», а не «не нашёл ответа»');
        $w = $faq->welcome();
        $t->ok(strpos($w['text'], 'Что я умею') !== false && strpos($faq->answer('/start')['text'], 'Что я умею') !== false, 'приветствие для новых: что умеет бот, и на /start');
        $t->ok(mb_stripos($w['text'], 'саун') === false, 'в приветствии нет «сауна»');
        $last = end($w['buttons']);
        $t->eq('Сайт', $last[0]['text'] ?? null, 'последняя кнопка приветствия — «Сайт»');
    },
];
