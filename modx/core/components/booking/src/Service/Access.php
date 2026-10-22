<?php
namespace Booking\Service;

use Booking\Model\AdminToken;
use MODX\Revolution\modUser;

/**
 * Кто выполняет действие. Администратор — сессия менеджера с правом booking_admin (CMP)
 * или персональный токен в заголовке Authorization: Bearer (PWA, MAX). Иначе — гость.
 * Токены и коды гостей хранятся только как SHA-256 (ст. 7.4).
 */
class Access
{
    public const PERMISSION = 'booking_admin';

    protected Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** Администратор по сессии менеджера MODX — или null */
    public function fromManagerSession(string $interface = 'cmp'): ?array
    {
        $modx = $this->booking->modx;
        if (!$modx->user || !$modx->user->isAuthenticated('mgr')) {
            return null;
        }
        if (!$this->managerCan()) {
            return null;
        }
        return ['role' => 'admin', 'name' => $modx->user->get('username'), 'user_id' => (int)$modx->user->get('id'), 'interface' => $interface];
    }

    /** Право booking_admin в контексте mgr (sudo — всегда) */
    protected function managerCan(): bool
    {
        $modx = $this->booking->modx;
        if ($modx->user->get('sudo')) {
            return true;
        }
        $ctx = $modx->getContext('mgr');
        return $ctx ? (bool)$ctx->checkPolicy(self::PERMISSION) : false;
    }

    /** Администратор по токену Authorization: Bearer — или null */
    public function fromBearer(string $interface): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $header = $v;
                }
            }
        }
        if (preg_match('~^Bearer\s+([A-Za-z0-9_\-]{20,128})$~', trim($header), $m)) {
            return $this->byToken($m[1], $interface);
        }
        // запасной заголовок: Apache в режиме CGI/FPM часто не передаёт Authorization в PHP
        $alt = trim((string)($_SERVER['HTTP_X_BOOKING_TOKEN'] ?? ''));
        if (preg_match('~^[A-Za-z0-9_\-]{20,128}$~', $alt)) {
            return $this->byToken($alt, $interface);
        }
        return null;
    }

    public function byToken(string $token, string $interface): ?array
    {
        $modx = $this->booking->modx;
        /** @var AdminToken $t */
        $t = $modx->getObject(AdminToken::class, ['token_hash' => self::hash($token), 'revoked_at:IS' => null]);
        if (!$t) {
            return null;
        }
        $user = $modx->getObject(modUser::class, (int)$t->get('user_id'));
        if (!$user || !$user->get('active')) {
            return null;
        }
        $t->set('last_used_at', $this->booking->now());
        $t->save();
        $name = $user->get('username') . ($t->get('label') !== '' ? ' (' . $t->get('label') . ')' : '');
        return ['role' => 'admin', 'name' => mb_substr($name, 0, 100), 'user_id' => (int)$user->get('id'), 'interface' => $interface, 'token_id' => (int)$t->get('id')];
    }

    /** Выпустить токен: сам токен возвращается один раз, в БД — только хеш */
    public function issueToken(int $userId, string $label): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(30)), '+/', '-_'), '=');
        $t = $this->booking->modx->newObject(AdminToken::class);
        $t->fromArray(['user_id' => $userId, 'label' => mb_substr($label, 0, 100), 'token_hash' => self::hash($token), 'created_at' => $this->booking->now()]);
        $t->save();
        return ['id' => (int)$t->get('id'), 'token' => $token];
    }

    /** Персональный код гостя к его заявке: 32 hex */
    public static function guestCode(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Ограничение частоты гостевых запросов с одного IP (booking.rate_limit в минуту).
     * Возвращает false, если лимит превышен.
     */
    public function rateLimit(string $ip): bool
    {
        $modx = $this->booking->modx;
        $limit = (int)$modx->getOption('booking.rate_limit', null, 30);
        if ($limit <= 0) {
            return true;
        }
        $key = 'rl/' . md5($ip) . '-' . floor(time() / 60);
        $opt = [\xPDO\xPDO::OPT_CACHE_KEY => 'booking'];
        $n = (int)$modx->cacheManager->get($key, $opt) + 1;
        $modx->cacheManager->set($key, $n, 120, $opt);
        return $n <= $limit;
    }
}
