<?php
/**
 * Админка MODX «Пакеты → Бронирование». Интерфейс — тот же модуль booking.js в режиме администратора
 * (одна реализация календаря для CMP и PWA, ст. 7.3); запросы — connector.php → те же процессоры.
 * Вкладки: Календарь, Заявки, Дома и бани, Цены, Праздники, Закрытые даты, Токены, Перенос заявок.
 */
class BookingIndexManagerController extends \MODX\Revolution\modExtraManagerController
{
    public function getPageTitle() { return 'Бронирование'; }
    public function getTemplateFile() { return ''; }
    public function getLanguageTopics() { return ['booking:default']; }

    public function checkPermissions()
    {
        return $this->modx->hasPermission('booking_admin');
    }

    public function loadCustomCssJs()
    {
        /** @var \Booking\Service\Booking $booking */
        $booking = $this->modx->services->get('booking');
        $v = '?v=' . \Booking\Service\Booking::VERSION;
        $this->addCss($booking->config['assetsUrl'] . 'css/booking.css' . $v);
        $this->addHtml('<script src="' . $booking->config['assetsUrl'] . 'js/booking.js' . $v . '" defer></script>');
    }

    public function process(array $scriptProperties = [])
    {
        /** @var \Booking\Service\Booking $booking */
        $booking = $this->modx->services->get('booking');
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $sitePage = (int)$this->modx->getOption('booking.form_page');
        $app = $sitePage ? $this->modx->makeUrl($sitePage, '', '', 'full') : '';
        return '<div class="bk-cmp">'
            . '<div class="bk-admin" data-bk="admin"'
            . ' data-bk-endpoint="' . $e($booking->config['connectorUrl']) . '"'
            . ' data-bk-modauth="' . $e($this->modx->user->getUserToken('mgr')) . '"'
            . ' data-bk-tabs="grid,list,houses,prices,holidays,blocks,tokens,migrate"'
            . ' data-bk-app="' . $e($app ? rtrim($app, '/') . '/app' : '') . '"'
            . '><p class="bk-muted">Загрузка…</p></div></div>';
    }
}
