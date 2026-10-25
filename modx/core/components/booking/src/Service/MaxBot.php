<?php
namespace Booking\Service;

use MODX\Revolution\modX;

/**
 * Бот MAX: отвечает гостям в личном чате на общие вопросы (ответы — Faq, только по данным сайта).
 * В группах и каналах молчит: там администраторы получают заявки, лишние сообщения не нужны.
 * События приходят вебхуком (assets/components/booking/bot.php) или забираются запасным режимом
 * GET /updates (cli/bot.php poll) — пока у сайта нет домена с https.
 * Токен бота — тот же, что у MaxNotify3 (maxnotify3.max_token), в коде и файлах его нет (ст. 7.4).
 * Формат событий и кнопок — по официальной библиотеке max-messenger/max-bot-api-client-ts.
 */
class MaxBot
{
    public const API = 'https://platform-api2.max.ru';
    public const UPDATE_TYPES = ['message_created', 'bot_started', 'message_callback', 'bot_added'];

    protected Booking $booking;

    /** Отправка запроса: fn(string $method, string $path, array $query, ?array $body): array [код, ответ] — подменяется в тестах */
    public $transport;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
        $this->transport = [$this, 'http'];
    }

    public function token(): string
    {
        return trim((string)$this->booking->modx->getOption('maxnotify3.max_token', null, ''));
    }

    public function enabled(): bool
    {
        return (bool)$this->booking->modx->getOption('booking.bot_enabled', null, false) && $this->token() !== '';
    }

    /** Одно событие MAX → ответ гостю. Возвращает, что сделано: 'answered', 'skip:…' */
    public function handle(array $u): string
    {
        $type = (string)($u['update_type'] ?? '');
        if (!in_array($type, self::UPDATE_TYPES, true)) {
            return 'skip:type';
        }
        if ($type === 'bot_added') {   // бота добавили в группу или канал — запомним номер (кнопка «Найти группы и каналы»)
            $this->remember((int)($u['chat_id'] ?? 0), !empty($u['is_channel']) ? 'канал' : 'группа');
            return 'skip:added';
        }
        // повтор того же события (MAX повторяет вебхук, если ответ задержался) — не отвечаем дважды
        $id = $type === 'message_callback' ? (string)($u['callback']['callback_id'] ?? '')
            : ($type === 'message_created' ? (string)($u['message']['body']['mid'] ?? '') : 'start-' . ($u['chat_id'] ?? '') . '-' . ($u['timestamp'] ?? ''));
        if ($id !== '' && !$this->once($id)) {
            return 'skip:repeat';
        }
        $faq = $this->booking->faq();

        if ($type === 'bot_started') {
            $userId = (int)($u['user']['user_id'] ?? 0);
            return $userId > 0 && $this->send($userId, $faq->greeting()) ? 'answered' : 'skip:user';
        }
        if ($type === 'message_callback') {
            $cb = $u['callback'] ?? [];
            if (($u['message']['recipient']['chat_type'] ?? 'dialog') !== 'dialog') {
                return 'skip:group';
            }
            $reply = $faq->answer('', (string)($cb['payload'] ?? ''));
            // ответ на нажатие: сообщение с кнопками меняется на ответ (с тем же меню); не вышло — новым сообщением
            [$code] = $this->call('POST', '/answers', ['callback_id' => (string)($cb['callback_id'] ?? '')], ['message' => $this->body($reply)]);
            if ($code === 200) {
                return 'answered';
            }
            $userId = (int)($cb['user']['user_id'] ?? 0);
            return $userId > 0 && $this->send($userId, $reply) ? 'answered' : 'skip:user';
        }
        // message_created: только личный чат с ботом, не от ботов
        $m = $u['message'] ?? [];
        if (($m['recipient']['chat_type'] ?? '') !== 'dialog') {
            $this->remember((int)($m['recipient']['chat_id'] ?? 0), ($m['recipient']['chat_type'] ?? '') === 'channel' ? 'канал' : 'группа');
            return 'skip:group';
        }
        if (!empty($m['sender']['is_bot'])) {
            return 'skip:bot';
        }
        $userId = (int)($m['sender']['user_id'] ?? 0);
        $text = (string)($m['body']['text'] ?? '');
        if ($userId <= 0) {
            return 'skip:user';
        }
        if (trim($text) === '') {   // фото, стикер, голосовое — ответим меню
            return $this->send($userId, $faq->greeting()) ? 'answered' : 'skip:send';
        }
        return $this->send($userId, $faq->answer(mb_substr($text, 0, 500))) ? 'answered' : 'skip:send';
    }

    /** Сообщение гостю в личный чат: текст и кнопки (inline_keyboard) */
    public function send(int $userId, array $reply): bool
    {
        [$code] = $this->call('POST', '/messages', ['user_id' => $userId, 'disable_link_preview' => 'true'], $this->body($reply));
        return $code === 200;
    }

    /** Пост в канал или группу (бот — администратор): возвращает id сообщения или '' */
    public function post(int $chatId, array $reply, bool $pin = false): string
    {
        [$code, $data] = $this->call('POST', '/messages', ['chat_id' => $chatId, 'disable_link_preview' => 'true'], $this->body($reply));
        $mid = $code === 200 ? (string)($data['message']['body']['mid'] ?? '') : '';
        if ($mid !== '' && $pin) {
            $this->call('PUT', '/chats/' . $chatId . '/pin', [], ['message_id' => $mid, 'notify' => false]);
        }
        return $mid;
    }

    /**
     * Группы и каналы, куда добавлен бот: из ещё не забранных событий (bot_added, сообщения в группах).
     * Маркер не сохраняется — события остаются для poll. При подключённом вебхуке MAX их так не отдаёт.
     * @return array chat_id => описание
     */
    public function chats(): array
    {
        // запомненные вебхуком и запасным режимом + ещё не забранные события (если вебхука нет)
        $out = (array)$this->booking->modx->cacheManager->get('bot/chats', [\xPDO\xPDO::OPT_CACHE_KEY => 'booking']);
        [$code, $data] = $this->call('GET', '/updates', ['timeout' => 1, 'limit' => 100, 'types' => 'bot_added,message_created,user_added']);
        if ($code !== 200) {
            $data = [];
        }
        foreach ($data['updates'] ?? [] as $u) {
            if (($u['update_type'] ?? '') === 'bot_added' && isset($u['chat_id'])) {
                $out[(int)$u['chat_id']] = !empty($u['is_channel']) ? 'канал' : 'группа';
            } elseif (($u['update_type'] ?? '') === 'message_created') {
                $r = $u['message']['recipient'] ?? [];
                if (in_array($r['chat_type'] ?? '', ['chat', 'channel'], true) && isset($r['chat_id'])) {
                    $out[(int)$r['chat_id']] = $r['chat_type'] === 'channel' ? 'канал' : 'группа';
                }
            }
        }
        foreach ($out as $id => $kind) {   // название — из сведений о чате
            [$c, $chat] = $this->call('GET', '/chats/' . $id);
            if ($c === 200 && !empty($chat['title'])) $out[$id] = $kind . ' «' . $chat['title'] . '»';
        }
        return $out;
    }

    /** Только запомнить группу или канал из события (вебхук при выключенных ответах бота) */
    public function observe(array $u): void
    {
        $type = (string)($u['update_type'] ?? '');
        if ($type === 'bot_added') {
            $this->remember((int)($u['chat_id'] ?? 0), !empty($u['is_channel']) ? 'канал' : 'группа');
        } elseif ($type === 'message_created' && in_array($u['message']['recipient']['chat_type'] ?? '', ['chat', 'channel'], true)) {
            $this->remember((int)($u['message']['recipient']['chat_id'] ?? 0), $u['message']['recipient']['chat_type'] === 'channel' ? 'канал' : 'группа');
        }
    }

    /** Номер группы или канала, где бот, — для кнопки «Найти группы и каналы» (вебхук забирает события сам) */
    protected function remember(int $chatId, string $kind): void
    {
        if ($chatId === 0) {
            return;
        }
        $opt = [\xPDO\xPDO::OPT_CACHE_KEY => 'booking'];
        $list = (array)$this->booking->modx->cacheManager->get('bot/chats', $opt);
        if (!isset($list[$chatId])) {
            $list[$chatId] = $kind;
            $this->booking->modx->cacheManager->set('bot/chats', $list, 0, $opt);
        }
    }

    protected function body(array $reply): array
    {
        $body = ['text' => $reply['text']];
        if (!empty($reply['buttons'])) {
            $body['attachments'] = [['type' => 'inline_keyboard', 'payload' => ['buttons' => $reply['buttons']]]];
        }
        return $body;
    }

    /** Запрос к API MAX с токеном; ошибки — в журнал MODX без токена */
    public function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $query = array_filter($query, fn($v) => $v !== null && $v !== '');
        try {
            [$code, $data] = call_user_func($this->transport, $method, $path, $query, $body);
        } catch (\Throwable $e) {
            [$code, $data] = [0, ['message' => $e->getMessage()]];
        }
        if ($code !== 200) {
            $this->booking->modx->log(modX::LOG_LEVEL_ERROR, '[booking] бот MAX: ' . $method . ' ' . $path . ' → ' . $code . ' ' . mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 300));
        }
        return [$code, $data];
    }

    public function http(string $method, string $path, array $query, ?array $body): array
    {
        $url = self::API . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        $headers = ['Authorization: ' . $this->token(), 'Accept: application/json'];
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $path === '/updates' ? 60 : 15];
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }
        $opt[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return [0, ['message' => $err]];
        }
        $data = json_decode((string)$raw, true);
        return [$code, is_array($data) ? $data : ['raw' => mb_substr((string)$raw, 0, 300)]];
    }

    /** Запасной режим без домена: забрать новые события и ответить. Возвращает число обработанных */
    public function poll(int $timeout = 30): int
    {
        $modx = $this->booking->modx;
        $opt = [\xPDO\xPDO::OPT_CACHE_KEY => 'booking'];
        $marker = $modx->cacheManager->get('bot/marker', $opt);
        [$code, $data] = $this->call('GET', '/updates', ['timeout' => $timeout, 'limit' => 100, 'marker' => $marker ?: null,
            'types' => implode(',', self::UPDATE_TYPES)]);
        if ($code !== 200) {
            return -1;
        }
        if (isset($data['marker'])) {
            $modx->cacheManager->set('bot/marker', $data['marker'], 0, $opt);
        }
        $n = 0;
        foreach ($data['updates'] ?? [] as $u) {
            if (is_array($u) && strpos($this->handle($u), 'answered') === 0) $n++;
        }
        return $n;
    }

    /** Подключить вебхук: MAX будет присылать события на $url с секретом в заголовке x-max-bot-api-secret */
    public function subscribe(string $url): array
    {
        return $this->call('POST', '/subscriptions', [], ['url' => $url, 'update_types' => self::UPDATE_TYPES, 'secret' => $this->secret()]);
    }

    public function unsubscribe(string $url): array
    {
        return $this->call('DELETE', '/subscriptions', ['url' => $url]);
    }

    /** Секрет вебхука: создаётся при первом обращении и хранится в системной настройке booking.bot_secret */
    public function secret(): string
    {
        $modx = $this->booking->modx;
        $s = trim((string)$modx->getOption('booking.bot_secret', null, ''));
        if ($s !== '') {
            return $s;
        }
        $s = bin2hex(random_bytes(24));
        $setting = $modx->getObject(\MODX\Revolution\modSystemSetting::class, ['key' => 'booking.bot_secret'])
            ?: $modx->newObject(\MODX\Revolution\modSystemSetting::class);
        $setting->fromArray(['key' => 'booking.bot_secret', 'xtype' => 'text-password', 'namespace' => 'booking', 'area' => 'booking'], '', true);
        $setting->set('value', $s);
        $setting->save();
        $modx->setOption('booking.bot_secret', $s);
        $modx->cacheManager->refresh(['system_settings' => []]);
        return $s;
    }

    public function checkSecret(string $header): bool
    {
        $s = trim((string)$this->booking->modx->getOption('booking.bot_secret', null, ''));
        return $s !== '' && hash_equals($s, $header);
    }

    protected function once(string $id): bool
    {
        $modx = $this->booking->modx;
        $opt = [\xPDO\xPDO::OPT_CACHE_KEY => 'booking'];
        $key = 'bot/seen-' . md5($id);
        if ($modx->cacheManager->get($key, $opt)) {
            return false;
        }
        $seen = 1;
        $modx->cacheManager->set($key, $seen, 3600, $opt);
        return true;
    }
}
