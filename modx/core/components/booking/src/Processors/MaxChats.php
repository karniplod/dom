<?php
namespace Booking\Processors;

use Booking\Service\BookingError;

/**
 * booking_max_chats — группы и каналы, куда добавлен бот MAX: id (chat_id) и название.
 * Нужен для maxnotify3.max_recipient_ids (группа администраторов) и поста в канал — без SSH. Только из админки MODX.
 * Токен бота — maxnotify3.max_token; MAX отдаёт события так только без подключённого вебхука.
 */
class MaxChats extends Base
{
    public static bool $cmpOnly = true;

    protected function handle(): array
    {
        $bot = $this->booking->maxBot();
        if ($bot->token() === '') {
            throw new BookingError('Нет токена бота: впишите его в системную настройку maxnotify3.max_token', 400);
        }
        [$code, $me] = $bot->call('GET', '/me');
        if ($code !== 200) {
            throw new BookingError('MAX не принял токен бота (код ' . $code . '): проверьте maxnotify3.max_token', 400);
        }
        [, $subs] = $bot->call('GET', '/subscriptions');
        $items = [];
        foreach ($bot->chats() as $id => $title) {
            $items[] = ['id' => (string)$id, 'title' => $title];
        }
        return [
            'bot' => trim(($me['name'] ?? '') . ' @' . ($me['username'] ?? ''), ' @'),
            'webhook' => !empty($subs['subscriptions']),
            'recipients' => (string)$this->modx->getOption('maxnotify3.max_recipient_ids', null, ''),
            'items' => $items,
        ];
    }
}
