<?php
namespace Booking\Service;

use MODX\Revolution\Mail\modMail;
use MODX\Revolution\Mail\modPHPMailer;
use MODX\Revolution\modX;

/**
 * Уведомления администратору вне SendIt (запрос отмены гостем): письмо и MAX через MaxNotify3.
 * Сбой уведомления пишется в журнал MODX и не откатывает действие (FR-029).
 * Токены MAX — только в настройках MaxNotify3 (maxnotify3.*), не в коде (ст. 7.4).
 */
class Notify
{
    protected Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function adminEmail(): string
    {
        $modx = $this->booking->modx;
        return trim((string)$modx->getOption('booking.admin_email', null, '')) ?: (string)$modx->getOption('emailsender');
    }

    /** Письмо и сообщение в MAX администратору по чанку */
    public function admin(string $subject, string $chunk, array $placeholders, string $maxText): void
    {
        $this->email($subject, $chunk, $placeholders);
        $this->max($maxText);
    }

    public function email(string $subject, string $chunk, array $placeholders): bool
    {
        $modx = $this->booking->modx;
        $to = $this->adminEmail();
        if ($to === '') {
            return false;
        }
        try {
            $body = $modx->getChunk($chunk, $placeholders);
            /** @var modPHPMailer $mail */
            $mail = $modx->services->has('mail') ? $modx->services->get('mail') : new modPHPMailer($modx);
            $mail->set(modMail::MAIL_BODY, $body);
            $mail->set(modMail::MAIL_FROM, $modx->getOption('emailsender'));
            $mail->set(modMail::MAIL_FROM_NAME, $modx->getOption('site_name'));
            $mail->set(modMail::MAIL_SUBJECT, $subject);
            foreach (array_filter(array_map('trim', explode(',', $to))) as $addr) {
                $mail->address('to', $addr);
            }
            $mail->setHTML(true);
            $ok = $mail->send();
            if (!$ok) {
                $modx->log(modX::LOG_LEVEL_ERROR, '[booking] письмо не отправлено: ' . $mail->mailer->ErrorInfo);
            }
            $mail->reset();
            return (bool)$ok;
        } catch (\Throwable $e) {
            $modx->log(modX::LOG_LEVEL_ERROR, '[booking] письмо не отправлено: ' . $e->getMessage());
            return false;
        }
    }

    /** Сообщение в MAX, если установлен и включён MaxNotify3 */
    public function max(string $text): bool
    {
        $modx = $this->booking->modx;
        $path = $modx->getOption('maxnotify3.core_path', null, $modx->getOption('core_path') . 'components/maxnotify3/');
        if (!is_file($path . 'model/maxnotify3/maxnotify3.class.php') || !$modx->getOption('maxnotify3.enabled', null, false)) {
            return false;
        }
        try {
            $svc = $modx->getService('maxnotify3', 'MaxNotify3', $path . 'model/maxnotify3/');
            if (!$svc || (method_exists($svc, 'isConfigured') && !$svc->isConfigured())) {
                return false;
            }
            return (bool)$svc->send($text);
        } catch (\Throwable $e) {
            $modx->log(modX::LOG_LEVEL_ERROR, '[booking] MAX: ' . $e->getMessage());
            return false;
        }
    }
}
