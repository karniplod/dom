<?php
namespace Booking\Service;

/**
 * Ошибка бизнес-правила: текст для человека, HTTP-код и данные (поле формы, пересечение).
 */
class BookingError extends \RuntimeException
{
    public int $status;
    public array $data;

    public function __construct(string $message, int $status = 400, array $data = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->data = $data;
    }

    public static function field(string $field, string $message, int $status = 400): self
    {
        return new self($message, $status, ['field' => $field]);
    }
}
