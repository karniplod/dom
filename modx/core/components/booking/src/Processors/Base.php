<?php
namespace Booking\Processors;

use Booking\Service\Booking;
use Booking\Service\BookingError;
use MODX\Revolution\modX;
use MODX\Revolution\Processors\Processor;

/**
 * Базовый процессор компонента: роль, единый формат ответа, ошибки бизнес-правил с HTTP-кодом.
 * Проверки одинаковы для CMP, сайта, PWA и MAX (ст. 3.6, 7.8).
 */
abstract class Base extends Processor
{
    /** guest — доступно всем; admin — только администратору */
    public static string $role = 'admin';
    /** Только из админки MODX (выпуск токенов, перенос заявок) */
    public static bool $cmpOnly = false;

    protected Booking $booking;

    public function __construct(modX $modx, array $properties = [])
    {
        parent::__construct($modx, $properties);
        $this->booking = $modx->services->get('booking');
    }

    public function booking(): Booking
    {
        return $this->booking;
    }

    public function getLanguageTopics()
    {
        return ['booking:default'];
    }

    public function checkPermissions()
    {
        return static::$role === 'guest' || $this->booking->isAdmin();
    }

    /** Логика действия: вернуть данные ответа или бросить BookingError */
    abstract protected function handle(): array;

    public function process()
    {
        $this->modx->error->reset();
        try {
            $data = $this->handle();
            return $this->success('OK', $data);
        } catch (BookingError $e) {
            return $this->failure($e->getMessage(), ['_status' => $e->status, '_data' => $e->data]);
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[booking] ' . static::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            return $this->failure('Внутренняя ошибка, попробуйте ещё раз', ['_status' => 500, '_data' => []]);
        }
    }

    // ---------------------------------------------------------------- чтение параметров

    protected function str(string $key, int $max = 255): string
    {
        $v = $this->getProperty($key);
        // без HTML и тегов MODX «[[…]]» — см. Bookings::clean()
        return is_scalar($v) ? mb_substr(\Booking\Service\Bookings::clean((string)$v), 0, $max) : '';
    }

    protected function int(string $key, ?int $default = null): ?int
    {
        $v = $this->getProperty($key);
        if ($v === null || $v === '') {
            return $default;
        }
        if (!is_scalar($v) || !preg_match('~^-?\d+$~', trim((string)$v))) {
            throw BookingError::field($key, 'Неверное число: ' . $key);
        }
        return (int)$v;
    }

    protected function bool(string $key): bool
    {
        $v = $this->getProperty($key);
        return in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    protected function arr(string $key): array
    {
        $v = $this->getProperty($key);
        if (is_string($v) && $v !== '' && ($v[0] === '{' || $v[0] === '[')) {
            $v = json_decode($v, true);
        }
        return is_array($v) ? $v : [];
    }

    protected function requireId(): int
    {
        $id = $this->int('id');
        if (!$id) {
            throw new BookingError('Не указан номер', 400);
        }
        return $id;
    }

    /** Транзакция: всё или ничего */
    protected function transaction(callable $fn)
    {
        $this->modx->beginTransaction();
        try {
            $result = $fn();
            $this->modx->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->modx->rollBack();
            throw $e;
        }
    }
}
