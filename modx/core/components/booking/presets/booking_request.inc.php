<?php
/**
 * Пресет SendIt booking_request — отправка формы бронирования (ст. 8.1–8.3).
 * Подключается сниппетом booking.form в сессию посетителя (как штатный RenderForm SendIt),
 * поэтому файл пресетов сайта и настройка si_path_to_presets не меняются.
 * Хуки: booking.hook (проверка, сохранение заявки, сообщение в MAX) → email (письмо администратору).
 * Хук maxNotify3SendIt не подключаем: он шлёт в MAX все поля формы подряд, включая служебные.
 * @var \MODX\Revolution\modX $modx
 */
$hooks = ['booking.hook', 'email'];
// почта для заявок: своя бронирования → «Почта для заявок» из «Конфигурации» → системный адрес MODX (как Notify::adminEmail)
$to = $modx->services->has('booking') ? $modx->services->get('booking')->notify()->adminEmail()
    : (trim((string)$modx->getOption('booking.admin_email', null, '')) ?: (string)$modx->getOption('emailsender'));

return [
    'booking_request' => [
        'extends' => 'default',
        'hooks' => implode(',', $hooks),
        'emailTo' => $to,
        'emailFrom' => (string)$modx->getOption('emailsender'),
        'emailFromName' => (string)$modx->getOption('site_name'),
        'emailTpl' => 'booking.email.request',
        'emailSubject' => 'Новая заявка на бронирование',
        'emailHtml' => 1,
        'validate' => 'house:required,date_from:required,guests:required,name:required,phone:required:minLength=^10^,agree:checkbox:required',
        'fieldNames' => 'house==Дом,date_from==Дата заезда,date_to==Дата выезда,guests==Гости,name==Имя,phone==Телефон,agree==Согласие',
        'successMessage' => 'Заявка отправлена. Администратор перезвонит и подтвердит бронь',
        'clearFieldsOnSuccess' => 0,
        // защита SendIt от ботов: доказательство вычислений и подпись поведения.
        // Сложность 15 (у SendIt по умолчанию 18): в среднем 32 тыс. попыток вместо 262 тыс. — доли секунды на телефоне, а не 5–30 с;
        // от ботов держат ещё подпись поведения, время заполнения, пауза и лимит отправок
        'usePoW' => 1,
        'powDifficulty' => 15,
        'useBehaviorSign' => 1,
        'minFillTime' => 3,
        'pauseBetweenSending' => 15,
        'sendingPerSession' => 6,
    ],
];
