<?php
namespace Booking\Service;

use Booking\Model\House;
use Booking\Processors\Base;
use MODX\Revolution\modX;

/**
 * Главный сервис: единая точка запуска действий для api.php (сайт, PWA, MAX) и connector.php (CMP).
 * Оба входа вызывают одни и те же процессоры (конституция ст. 3.3, 7.7).
 */
class Booking
{
    public const VERSION = '1.0.0-pl';

    /** action => класс процессора; роль задаёт сам процессор (Base::$role) */
    public const ACTIONS = [
        'booking_get_availability' => \Booking\Processors\Availability\Get::class,
        'booking_quote' => \Booking\Processors\Quote::class,
        'booking_create_request' => \Booking\Processors\Request\Create::class,
        'booking_get_request' => \Booking\Processors\Request\Get::class,
        'booking_request_cancel' => \Booking\Processors\Request\Cancel::class,
        'booking_houses' => \Booking\Processors\Houses::class,
        'booking_list' => \Booking\Processors\Booking\GetList::class,
        'booking_grid' => \Booking\Processors\Booking\Grid::class,
        'booking_get' => \Booking\Processors\Booking\Get::class,
        'booking_confirm' => \Booking\Processors\Booking\Confirm::class,
        'booking_cancel' => \Booking\Processors\Booking\Cancel::class,
        'booking_complete' => \Booking\Processors\Booking\Complete::class,
        'booking_set_deposit' => \Booking\Processors\Booking\SetDeposit::class,
        'booking_create_manual' => \Booking\Processors\Booking\CreateManual::class,
        'booking_update' => \Booking\Processors\Booking\Update::class,
        'booking_manage_house' => \Booking\Processors\House::class,
        'booking_manage_addon' => \Booking\Processors\Addon::class,
        'booking_manage_prices' => \Booking\Processors\Prices::class,
        'booking_manage_holiday' => \Booking\Processors\Holiday::class,
        'booking_manage_block' => \Booking\Processors\Block::class,
        'booking_issue_token' => \Booking\Processors\Token::class,
        'booking_migrate_leads' => \Booking\Processors\Migrate::class,
        'booking_max_chats' => \Booking\Processors\MaxChats::class,
        'booking_max_webhook' => \Booking\Processors\MaxWebhook::class,
        'booking_help' => \Booking\Processors\Help::class,
    ];

    /** Поля, которые гость не может передать: их считает или ставит только сервер (ст. 5.3, FR-026b) */
    public const GUEST_FORBIDDEN = ['status', 'deposit_amount', 'deposit_status', 'quote_total', 'quote_discount',
        'quote_lines', 'source', 'updated_by', 'admin_note', 'legacy_lead_id', 'cancellation_requested_by', '_actor'];

    public modX $modx;
    public array $config;

    /** Кто выполняет действие: role guest|admin, name, user_id, interface (cmp/api/pwa/max/site/cli) */
    protected ?array $actor = null;

    protected ?Pricing $pricing = null;
    protected ?Availability $availability = null;
    protected ?Access $access = null;
    protected ?Log $log = null;
    protected ?Notify $notify = null;
    protected ?Bookings $bookings = null;
    protected ?Faq $faq = null;
    protected ?MaxBot $maxBot = null;

    public function __construct(modX $modx)
    {
        $this->modx = $modx;
        $core = $modx->getOption('booking.core_path', null, $modx->getOption('core_path') . 'components/booking/');
        $assets = $modx->getOption('booking.assets_url', null, $modx->getOption('assets_url') . 'components/booking/');
        $this->config = [
            'corePath' => $core,
            'assetsUrl' => $assets,
            'apiUrl' => $assets . 'api.php',
            'connectorUrl' => $assets . 'connector.php',
        ];
        $modx->lexicon->load('booking:default');
    }

    public function pricing(): Pricing { return $this->pricing ??= new Pricing($this); }
    public function availability(): Availability { return $this->availability ??= new Availability($this); }
    public function access(): Access { return $this->access ??= new Access($this); }
    public function log(): Log { return $this->log ??= new Log($this); }
    public function notify(): Notify { return $this->notify ??= new Notify($this); }
    public function bookings(): Bookings { return $this->bookings ??= new Bookings($this); }
    public function faq(): Faq { return $this->faq ??= new Faq($this); }
    public function maxBot(): MaxBot { return $this->maxBot ??= new MaxBot($this); }

    public function setActor(array $actor): void
    {
        $this->actor = $actor + ['role' => 'guest', 'name' => 'гость', 'user_id' => 0, 'interface' => 'site'];
    }

    /** Текущий исполнитель; без явной установки — по сессии менеджера (вызов процессора из MODX) */
    public function actor(): array
    {
        if ($this->actor === null) {
            $this->setActor($this->access()->fromManagerSession('cli') ?? ['role' => 'guest']);
        }
        return $this->actor;
    }

    public function isAdmin(): bool
    {
        return $this->actor()['role'] === 'admin';
    }

    /**
     * Запустить действие от имени текущего исполнителя.
     * Возвращает ['status' => HTTP-код, 'success', 'message', 'data'].
     */
    public function runAction(string $action, array $params): array
    {
        $class = self::ACTIONS[$action] ?? null;
        if (!$class) {
            return $this->reply(400, false, 'Неизвестное действие');
        }
        /** @var Base $class */
        $role = $class::$role;
        if ($role === 'admin' && !$this->isAdmin()) {
            return $this->reply(401, false, 'Нужен вход администратора');
        }
        if ($class::$cmpOnly && $this->actor()['interface'] !== 'cmp' && $this->actor()['interface'] !== 'cli') {
            return $this->reply(403, false, 'Действие доступно только в админке MODX');
        }
        if (!$this->isAdmin()) {
            // гость не задаёт статус, залог, цену и источник — поля отбрасываются молча
            $params = array_diff_key($params, array_flip(self::GUEST_FORBIDDEN));
        }
        unset($params['action'], $params['HTTP_MODAUTH']);

        $response = $this->modx->runProcessor($class, $params);
        $object = $response->getObject();
        if (!$response->isError()) {
            return $this->reply(200, true, (string)$response->getMessage(), is_array($object) ? $object : []);
        }
        $status = is_array($object) && isset($object['_status']) ? (int)$object['_status'] : 403;
        $data = is_array($object) && isset($object['_data']) ? $object['_data'] : [];
        return $this->reply($status, false, (string)$response->getMessage(), $data);
    }

    public function reply(int $status, bool $success, string $message, array $data = []): array
    {
        return ['status' => $status, 'success' => $success, 'message' => $message, 'data' => (object)$data];
    }

    // ---------------------------------------------------------------- общие помощники

    public function house(string $code, bool $activeOnly = true): House
    {
        $where = ['code' => $code];
        if ($activeOnly) {
            $where['active'] = 1;
        }
        $house = $code !== '' ? $this->modx->getObject(House::class, $where) : null;
        if (!$house) {
            throw BookingError::field('house', 'Выберите Дом');
        }
        return $house;
    }

    public function tz(): \DateTimeZone
    {
        return new \DateTimeZone($this->modx->getOption('booking.timezone', null, 'Asia/Yekaterinburg') ?: 'Asia/Yekaterinburg');   // Пермь — UTC+5
    }

    public function today(): string
    {
        return (new \DateTimeImmutable('now', $this->tz()))->format('Y-m-d');
    }

    public function now(): string
    {
        return (new \DateTimeImmutable('now', $this->tz()))->format('Y-m-d H:i:s');
    }

    /** Проверка даты Y-m-d */
    public function date(?string $value, string $field = 'dates'): string
    {
        $value = trim((string)$value);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $this->tz());
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw BookingError::field($field, 'Укажите даты');
        }
        return $value;
    }

    /** Проверка времени ЧЧ:ММ → ЧЧ:ММ:00 */
    public function time(?string $value, string $field = 'time'): string
    {
        $value = trim((string)$value);
        if (!preg_match('~^([01]\d|2[0-3]):([0-5]\d)(:00)?$~', $value, $m)) {
            throw BookingError::field($field, 'Укажите время');
        }
        return $m[1] . ':' . $m[2] . ':00';
    }

    /** Телефон к виду +7XXXXXXXXXX */
    public function phone(?string $value): string
    {
        $digits = preg_replace('~\D+~', '', (string)$value);
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) !== 10) {
            throw BookingError::field('phone', 'Телефон: +7 и 10 цифр');
        }
        return '+7' . $digits;
    }

    public function money(int $n): string
    {
        return number_format($n, 0, '', ' ') . ' ₽';
    }

    /** Ссылка гостя на его заявку */
    public function requestLink(int $id, string $code): string
    {
        $page = (int)$this->modx->getOption('booking.request_link_page');
        $params = ['id' => $id, 't' => $code];
        if ($page > 0) {
            // makeUrl экранирует & как &amp; (xhtml_urls) — для ссылки в письме и JSON нужен обычный &
            return str_replace('&amp;', '&', $this->modx->makeUrl($page, '', $params, 'full'));
        }
        return rtrim($this->modx->getOption('site_url'), '/') . '/booking/zayavka?' . http_build_query($params);
    }
}
