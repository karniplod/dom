# Contract: элементы MODX компонента booking

Все элементы — в категории «Бронирование», имена с префиксом `booking.` (ст. 7.1). На страницы сайта попадают только вызовами (ст. 2.2).

## Сниппеты

### `[[!booking.form? &mode=`full|compact` &house=`` ]]`
- `mode=full` — страница `/booking`: выбор дома, календарь на 2 месяца, гости, питомцы, (баня отдельно — если включена у дома), расчёт, контакты, согласие.
- `mode=compact` — главная и места прежней формы сайта: дом, даты (календарь по клику), гости, расчёт, контакты.
- `&house` — код дома, выбранного заранее (страница дома). Без него — пусто / из `data-house` кнопки, открывшей форму.
- Некэшируемый (`!`): выводит токен SendIt и защиту от ботов. Разметка — чанк `booking.form.tpl` (+ `booking.form.compact.tpl`), подключает `assets/components/booking/js/booking.js` и `css/booking.css` один раз на страницу.
- Форма: `data-si-form="booking_request"`, `data-si-preset="booking_request"`, поля `house, addon, date_from, date_to, time_from, time_to, guests, pets, name, phone, comment, agree, sms`, места ошибок `data-si-error="…"`. Текст о залоге: «Администратор может назначить залог 5 000–20 000 ₽ — скажет при подтверждении».

### `[[booking.prices? &house=`en` &tpl=`booking.prices.tpl`]]`
- Цены суток дома по типам дней из БД (ст. 6.3) — та же разметка, что блок «Цена за сутки» сайта (`.price-days`), чтобы встать на место без правки стилей. Кэшируемый; кэш сбрасывается при изменении цен (процессор `booking_manage_prices` чистит кэш ресурсов).

### `[[!booking.request]]`
- Страница «Моя заявка» (`/booking/zayavka`): по `?id=&t=` показывает заявку и кнопку «Попросить отменить» (через api.php).

### `booking.hook` (хук SendIt / FormIt, не для страниц)
- Вызывает процессор `booking_create_request`; при ошибке — `$hook->addError(поле, текст)` и `false`; при успехе — кладёт id заявки и код гостя в сессию `booking.last` и значения для письма (`booking_id`, `house_name`, `dates`, `total`) в хук.

## Пресет SendIt `booking_request`

Файл `core/components/booking/presets/booking_request.inc.php`, подключается резолвером (настройка SendIt `si_path_to_presets` дополняется, а не заменяется — пресеты сайта сохраняются).

```
'booking_request' => [
  'extends' => 'default',
  'hooks' => 'booking.hook,email,maxNotify3SendIt',   // maxNotify3SendIt — только если MaxNotify3 установлен
  'emailTo' => <настройка booking.admin_email>,
  'emailTpl' => 'booking.email.request',
  'emailSubject' => 'Новая заявка на бронирование',
  'validate' => 'house:required,date_from:required,date_to:required,guests:required,name:required,phone:required:minLength=^10^,agree:checkbox:required',
  'successMessage' => 'Заявка отправлена. Администратор перезвонит и подтвердит бронь',
]
```

## Чанки
`booking.form.tpl`, `booking.form.compact.tpl`, `booking.prices.tpl`, `booking.request.tpl`, `booking.email.request` (письмо: номер, дом, даты, гости, питомцы, сумма, гость, телефон, комментарий, ссылка в админку), `booking.email.cancel`.

## Системные настройки (namespace `booking`)
`booking.admin_email`, `booking.site_discount` (5), `booking.request_link_page` (id страницы «Моя заявка»), `booking.form_page` (id `/booking`), `booking.timezone` (Europe/Moscow), `booking.rate_limit` (30). Токены MAX — в настройках MaxNotify3 (`maxnotify3.*`), не в коде (ст. 7.4).

## Админка (CMP)
Пространство имён `booking`, меню «Пакеты → Бронирование». Вкладки: Календарь, Заявки, Дома и бани, Цены, Праздники, Закрытые даты, Токены, Перенос заявок. Право `booking_admin`.

## PWA / мини-приложение MAX
`/assets/components/booking/app/` — `index.html`, `manifest.webmanifest`, `sw.js` (кеш только статики, `api.php` — только сеть). Вкладка «Администратор» — ввод токена (хранится в `localStorage` устройства), без токена недоступна.

## Уточнения при реализации (22.10.2026)

- **Пресет SendIt** подключается не через `si_path_to_presets`, а в сессию посетителя (как штатный `RenderForm`), плюс запись в `window.siConfig.protectionMap` — файл пресетов сайта не меняется, защита PoW и подпись поведения работают.
- **Приложение** — страница MODX `/booking/app` на шаблоне компонента `booking.app` (статичный HTML не получает токен SendIt), Service Worker — страница `/booking/sw.js` (сниппет `booking.sw`).
- **Скидка** — только при бронировании через ВКонтакте: настройки `booking.vk_discount` (5) и `booking.vk_url`; заявки с сайта, PWA и MAX без скидки; администратор ставит её галочкой в брони по телефону. Настройка `booking.site_discount` удалена.
- **Действие `booking_houses`** (гость): активные Дома для формы и приложения.
- **`booking_get_availability`** возвращает и `busy_bath` — занятость дня для бани (только ночи Дома и закрытия; сеансы бани проверяются по часам).
- **Сайт**: чанк `bn.form` → `[[!bnOrderForm]]` (booking.form или прежняя форма `bn.form.legacy`, если компонента нет); цены Домов на сайте — из компонента (`Bania::bookingPrices`), блок «Цена за сутки» — `booking.prices`.
- **Токен администратора** принимается и в заголовке `X-Booking-Token` (Apache в режиме CGI/FPM может не передать `Authorization`).
