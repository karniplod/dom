<?php
namespace Booking\Service;

use Booking\Model\Booking as BookingRow;
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
        // своя почта бронирования → «Почта для заявок» из «Конфигурации» (bn_lead_email) → системный адрес MODX
        return trim((string)$modx->getOption('booking.admin_email', null, ''))
            ?: ($this->booking->faq()->cc('bn_lead_email') ?: (string)$modx->getOption('emailsender'));
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

    /** Новая заявка в MAX: коротко, без служебных полей формы, с кнопками «Открыть» (приложение и админка) */
    public function request(BookingRow $row): bool
    {
        $a = $this->booking->bookings()->toAdmin($row);
        $money = fn($n) => $this->booking->money((int)$n);
        $from = ['site' => 'с сайта', 'pwa' => 'из приложения', 'max' => 'из MAX'][$a['source']] ?? '';
        $t = ["Новая заявка №{$a['id']}" . ($from !== '' ? " {$from}" : ''), '', $a['house_name'] . ($a['addon'] ? ' — баня отдельно' : ''), $a['dates']];
        $t[] = 'Гостей: ' . $a['guests'] . ($a['pets'] ? ', с животными' : '');
        $t[] = '';
        $t[] = 'Сумма: ' . $money($a['total']);
        foreach ($a['lines'] as $l) {
            $t[] = '• ' . $l['label'] . ' — ' . $money($l['amount']);
        }
        if ($a['quote_discount']) {
            $t[] = 'Скидка: ' . $money($a['quote_discount']);
        }
        $t[] = '';
        $t[] = 'Гость: ' . $a['guest_name'];
        $t[] = 'Телефон: ' . $a['guest_phone'];
        if ($a['guest_comment'] !== '') {
            $t[] = 'Комментарий: ' . $a['guest_comment'];
        }
        $modx = $this->booking->modx;
        $links = [];
        if (($app = $this->booking->faq()->appUrl()) !== '') {
            $links['Открыть в приложении'] = preg_replace('~[?&]client=max~', '', $app) . '#admin&id=' . $a['id'];
        }
        $links['Открыть в админке'] = rtrim($modx->getOption('site_url'), '/') . '/'
            . trim($modx->getOption('manager_url', null, 'manager/'), '/') . '/?a=index&namespace=booking#id=' . $a['id'];
        return $this->max(implode("\n", $t), $links);
    }

    /**
     * Сообщение в MAX, если установлен и включён MaxNotify3.
     * Группы (chat_id) — от бота напрямую, с кнопками-ссылками; иначе — через MaxNotify3, ссылки текстом.
     */
    public function max(string $text, array $links = []): bool
    {
        $modx = $this->booking->modx;
        $path = $modx->getOption('maxnotify3.core_path', null, $modx->getOption('core_path') . 'components/maxnotify3/');
        if (!is_file($path . 'model/maxnotify3/maxnotify3.class.php') || !$modx->getOption('maxnotify3.enabled', null, false)) {
            return false;
        }
        $ids = array_filter(array_map('intval', explode(',', (string)$modx->getOption('maxnotify3.max_recipient_ids', null, ''))));
        $bot = $this->booking->maxBot();
        if ($links && $ids && $modx->getOption('maxnotify3.max_recipient_type', null, 'chat_id') === 'chat_id' && $bot->token() !== '') {
            $buttons = [];
            foreach ($links as $label => $url) {
                $buttons[] = [['type' => 'link', 'text' => $label, 'url' => $url]];
            }
            $ok = false;
            foreach ($ids as $id) {
                $ok = $bot->post($id, ['text' => $text, 'buttons' => $buttons]) !== '' || $ok;
            }
            if ($ok) {
                return true;
            }
        }
        foreach ($links as $label => $url) {
            $text .= "\n{$label}: {$url}";
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
