<?php
/**
 * Пресет SendIt booking_request — отправка формы бронирования (ст. 8.1–8.3).
 * Подключается сниппетом booking.form в сессию посетителя (как штатный RenderForm SendIt),
 * поэтому файл пресетов сайта и настройка si_path_to_presets не меняются.
 * Хуки: booking.hook (проверка и сохранение заявки) → email (письмо администратору) → maxNotify3SendIt (MAX).
 * @var \MODX\Revolution\modX $modx
 */
$hooks = ['booking.hook', 'email'];
if ($modx->getCount(\MODX\Revolution\modSnippet::class, ['name' => 'maxNotify3SendIt'])) {
    $hooks[] = 'maxNotify3SendIt';
}
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
        // защита SendIt от ботов: доказательство вычислений и подпись поведения (как у пресета default)
        'usePoW' => 1,
        'powDifficulty' => 18,
        'useBehaviorSign' => 1,
        'minFillTime' => 3,
        'pauseBetweenSending' => 15,
        'sendingPerSession' => 6,
        'maxnotify3_title' => 'Новая заявка на бронирование',
    ],
];
