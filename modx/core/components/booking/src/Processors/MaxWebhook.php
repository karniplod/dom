<?php
namespace Booking\Processors;

use Booking\Service\BookingError;
use MODX\Revolution\modSystemSetting;

/**
 * booking_max_webhook — бот MAX отвечает гостям: подключить или отключить вебхук без SSH. Только из админки MODX.
 *  op=status — подключён ли адрес приёма, включён ли бот;
 *  op=on     — адрес …/assets/components/booking/bot.php в MAX (с секретом) и booking.bot_enabled = Да;
 *  op=off    — отключить адрес и booking.bot_enabled = Нет.
 * MAX доставляет сообщения боту только на подключённый адрес https; без него бот молчит.
 */
class MaxWebhook extends Base
{
    public static bool $cmpOnly = true;

    protected function handle(): array
    {
        $bot = $this->booking->maxBot();
        $url = $this->url();
        $op = $this->str('op', 10) ?: 'status';
        if ($bot->token() === '') {
            if ($op === 'status') {   // вкладка «Токены» открывается и без бота — это не ошибка
                return ['url' => $url, 'webhook' => false, 'enabled' => false, 'no_token' => true];
            }
            throw new BookingError('Нет токена бота: впишите его в системную настройку maxnotify3.max_token', 400);
        }
        if ($op === 'on') {
            if (strpos($url, 'https://') !== 0) {
                throw new BookingError('MAX принимает только адрес https: включите https на сайте (site_url начинается с https://)', 400);
            }
            $this->drop($bot);   // прежняя подписка с устаревшим секретом (пакет переустанавливали) — убрать
            [$code, $data] = $bot->subscribe($url);
            if ($code !== 200 || empty($data['success'])) {
                throw new BookingError('MAX не подключил адрес (код ' . $code . '): ' . ($data['message'] ?? 'попробуйте позже'), 400);
            }
            $this->enable(true);
        } elseif ($op === 'off') {
            $this->drop($bot);
            $this->enable(false);
        }
        return $this->status($bot, $url);
    }

    protected function url(): string
    {
        $site = rtrim((string)$this->modx->getOption('site_url'), '/');
        $assets = '/' . trim((string)$this->modx->getOption('assets_url', null, '/assets/'), '/') . '/';
        return $site . $assets . 'components/booking/bot.php';
    }

    /** Подписки на bot.php этого сайта */
    protected function mine($bot): array
    {
        [, $data] = $bot->call('GET', '/subscriptions');
        $host = parse_url($this->url(), PHP_URL_HOST);
        return array_values(array_filter((array)($data['subscriptions'] ?? []), function ($s) use ($host) {
            return parse_url((string)($s['url'] ?? ''), PHP_URL_HOST) === $host && substr((string)$s['url'], -7) === 'bot.php';
        }));
    }

    protected function drop($bot): void
    {
        foreach ($this->mine($bot) as $s) {
            $bot->unsubscribe((string)$s['url']);
        }
    }

    protected function enable(bool $on): void
    {
        $s = $this->modx->getObject(modSystemSetting::class, ['key' => 'booking.bot_enabled']) ?: $this->modx->newObject(modSystemSetting::class);
        $s->fromArray(['key' => 'booking.bot_enabled', 'xtype' => 'combo-boolean', 'namespace' => 'booking', 'area' => 'booking'], '', true);
        $s->set('value', $on ? '1' : '0');
        $s->save();
        $this->modx->setOption('booking.bot_enabled', $on ? '1' : '0');
        $this->modx->cacheManager->refresh(['system_settings' => []]);
    }

    protected function status($bot, string $url): array
    {
        $mine = $this->mine($bot);
        return [
            'url' => $url,
            'webhook' => (bool)$mine,
            'enabled' => (bool)$this->modx->getOption('booking.bot_enabled', null, false),
        ];
    }
}
