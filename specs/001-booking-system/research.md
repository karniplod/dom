# Research: компонент бронирования booking

Дата: 2026-10-22. Все пункты Technical Context закрыты; открытые риски — в конце.

## R1. Форма пакета и место исходников

- **Decision**: отдельное дополнение MODX 3 `booking`, ставится как транспортный пакет `booking-<версия>-pl.transport.zip` через «Пакеты → Установщик». Исходники — строго в `modx/core/components/booking/` (код, схема, процессоры, элементы, лексиконы, `_build/`) и `modx/assets/components/booking/` (коннекторы, JS, CSS, PWA). Сборка — `_build/build.transport.php`, запускается под локальным MODX (`C:\cloud\modx-bania`).
- **Rationale**: конституция ст. 1.1, 7.1; пакет ставится и обновляется независимо от установщика сайта `bn-install.php`, обновление не теряет данные (миграции в резолвере).
- **Alternatives**: встроить в установщик сайта (`bn-install`) — отклонено: нарушает изоляцию (ст. 1.3) и правку чужих файлов (ст. 7.1).
- **Конфликт со сборкой сайта**: `tools/modx_build.py` при каждой сборке удаляет папку `modx/` целиком и уничтожил бы исходники `booking`. Нужна правка одной строки сборщика (чистить только свои подпапки `modx/chunks|templates|snippets|component` и файлы установщика). Файл вне разрешённых путей → требуется разрешение владельца (см. plan.md, Complexity Tracking).

## R2. Каркас ModExtra3

- **Decision**: структура по ModExtra (modx-pro/modExtra) и правилам MODX 3: `bootstrap.php` (PSR-4 `Booking\` → `src/`), `src/Model` (сгенерировано из `schema/booking.mysql.schema.xml`, `package="Booking\Model"`, `version="3.0"`), `src/Processors`, `src/Service`, `elements/`, `lexicon/ru|en/`, `_build/` (build.schema.php, build.transport.php, resolvers/). Источник: [modExtra](https://docs.modx.com/3.x/en/extras/modextra), [структура компонента](https://docs.modx.com/current/en/extending-modx/creating-components/component-structure).
- **Rationale**: штатный путь MODX 3, транспортный пакет, обновление через Package Manager.
- **Alternatives**: «папка + установщик» как у сайта — отклонено (не даёт штатного обновления/удаления дополнения).

## R3. Единая точка изменения данных — процессоры

- **Decision**: все изменения — процессоры MODX 3 (`Booking\Processors\…`, наследники `MODX\Revolution\Processors\Processor`/`ModelProcessor`), имена-действия по docs/01: `booking_get_availability`, `booking_quote`, `booking_create_request`, `booking_get_request`, `booking_request_cancel`, `booking_confirm`, `booking_cancel`, `booking_complete`, `booking_set_deposit`, `booking_create_manual`, `booking_manage_house`, `booking_manage_addon`, `booking_manage_prices`, `booking_manage_holiday`, `booking_manage_block`, `booking_list`, `booking_grid`, `booking_migrate_leads`, `booking_issue_token`. Два входа: `assets/components/booking/connector.php` (CMP, сессия менеджера + `HTTP_MODAUTH`) и `assets/components/booking/api.php` (сайт, PWA, MAX). Оба вызывают одни и те же процессоры через `$modx->runProcessor(…, ['processors_path' => …])`.
- **Rationale**: ст. 1.4, 3.3, 7.7; docs/01 «Процессоры — единственная точка изменения данных».
- **Alternatives**: отдельные эндпоинты для PWA — запрещено ст. 7.7.

## R4. Авторизация

- **Decision**:
  - Администратор в CMP — сессия менеджера MODX и право `booking_admin` (политика доступа создаётся резолвером, по умолчанию у группы Administrator).
  - Администратор в PWA/MAX — персональный токен: выпускается в CMP (`booking_issue_token`), в БД хранится только хеш (SHA-256), передаётся заголовком `Authorization: Bearer`, можно отозвать; у токена есть владелец (пользователь MODX) — он и пишется в журнал.
  - `api.php` определяет «кто» (гость / администратор по токену) и передаёт процессору; админские процессоры проверяют роль в `checkPermissions()` — одинаково для CMP и API (ст. 3.6, 7.8).
  - Гость — к своей заявке по персональному коду (32 hex, в БД хеш) или по паре «номер + телефон».
- **Rationale**: ст. 3.1–3.6, 5.3; токены не попадают в код и репозиторий (ст. 7.4).
- **Alternatives**: общий секрет в настройке — отклонено (нельзя отозвать у одного человека, нет автора в журнале).

## R5. Пересечения и одновременность

- **Decision**: проверка в процессоре внутри транзакции: `SELECT … FOR UPDATE` строки дома (блокирует параллельные подтверждения этого дома) → поиск пересечений с подтверждёнными бронями, закрытыми датами и (для бани) сеансами с учётом буфера → запись. Дополнительная гарантия в БД: таблица занятых ночей `booking_nights` с первичным ключом (house_id, night) — вторую подтверждённую ночь база не примет. Ночь дома = сутки с заездом в эту дату; выезд и заезд в один день не пересекаются (время заезда ≥ время выезда у всех домов). Баня: интервалы времени, буфер `buffer_minutes` (30) между сеансами; сеанс бани запрещён в ночь подтверждённой брони своего дома.
- **Rationale**: ст. 5.1, SC-002; проверенный в «Заявках» подход (уникальный ключ ночей) + блокировка строки для интервалов бани, где уникальный ключ невозможен.
- **Alternatives**: только проверка без блокировки — гонка двух подтверждений; только уникальный ключ — не покрывает интервалы бани.

## R6. Расчёт цены

- **Decision**: один сервис `Booking\Service\Pricing::quote(house, from, to, guests, pets, addon?)`, вызывается и из `booking_quote` (для формы), и из `booking_create_request` (сервер пересчитывает, цифрам из браузера не верит). Правила: тип суток по дате заезда этих суток — Пт → friday, Сб → saturday, Вс → sunday, иначе weekday; если дата в `booking_holidays`: особая цена дома на эту дату (если задана) → иначе тип по правилу праздников (предпраздничный рабочий = friday, праздник перед выходным = saturday, праздник перед рабочим = sunday) — правило в коде (ст. 6.2). Гости сверх нормы × доплату — один раз за отдых; питомцы × цену — один раз; скидка (настройка `booking.site_discount`, 5) — на всю сумму, отдельной строкой; деньги — целые рубли. Ответ: строки расчёта + итог.
- **Rationale**: ст. 6, docs/03, FR-003a, SC-003.

## R7. Форма гостя и SendIt

- **Decision**: `booking.form` (сниппет, параметр `mode=full|compact`) выводит форму через чанк `booking.form.tpl` и подключает один JS-модуль календаря `assets/components/booking/js/booking.js` (ст. 7.3 — одна реализация; режимы guest-month и admin-grid в одном модуле). Отправка — SendIt, пресет `booking_request`: хуки `booking.hook,email,maxNotify3SendIt`. `booking.hook` вызывает процессор `booking_create_request`; ошибки (даты заняты, гостей больше максимума) возвращаются через `$hook->addError()` — SendIt отдаёт их форме как ошибки полей. Номер и персональная ссылка: SendIt не возвращает данные хука браузеру (проверено по `SendIt/src/Form/FormProcessor.php` 3.1.6), поэтому хук кладёт id созданной заявки в сессию, а JS после события SendIt об успехе запрашивает `api.php?action=booking_get_request&mine=1` и показывает номер и ссылку.
- **Rationale**: ст. 8.1–8.3, docs/01 «не FormIt напрямую»; ошибки показываются у полей средствами SendIt.
- **Alternatives**: отправка формы напрямую в api.php — нарушает ст. 8.1.

## R8. Уведомления

- **Decision**: письмо администратору — хук `email` пресета (`emailTo` из настройки `booking.admin_email`, `emailTpl` = чанк `booking.email.request`). MAX — хук `maxNotify3SendIt` из дополнения MaxNotify3 (бесплатное, modstore.pro; провайдер `maxbusiness`, адрес по умолчанию `https://platform-api2.max.ru/messages`, токен и получатели — в системных настройках `maxnotify3.*`, не в коде). Запрос отмены гостем — процессор `booking_request_cancel` отправляет письмо через `modPHPMailer` и, если MaxNotify3 установлен, через его сервис/сниппет. Сбой уведомления пишется в журнал MODX и не откатывает заявку (FR-029). Гостю до подтверждения ничего не отправляется (ст. 8.4). Источник: [MaxNotify3 на modstore.pro](https://modstore.pro/packages/integration/maxnotify3).
- **Риск**: в требованиях MaxNotify3 указан miniShop3 1.x. Если пакет не ставится без miniShop3 — разрешено (ст. 8.3 запрещает свой сниппет только «если компонент установлен») сделать минимальный хук `booking.max` на тот же адрес `platform-api2.max.ru/messages` (ст. 7.5). Решение — после пробной установки на стенде.

## R9. PWA и мини-приложение MAX

- **Decision**: одна страница-приложение `assets/components/booking/app/` (index.html, manifest, sw.js) на том же JS-модуле: вкладка гостя (форма, «моя заявка») и вкладка администратора (сетка, карточки, подтверждение/отмена/залог) по токену. Service Worker кеширует только статику; ответы `api.php` — `Cache-Control: no-store`, в SW — только сеть (ст. 5.2, FR-026). Мини-приложение MAX открывает тот же адрес из бота.
- **Rationale**: ст. 3.3–3.4, docs/04 «один визуал».

## R10. Админка MODX (CMP)

- **Decision**: пространство имён `booking`, пункт меню «Бронирование», контроллер с вкладками: «Календарь» (сетка дома/бани × даты, подсказка, карточка), «Заявки» (список, фильтры), «Дома и бани», «Цены», «Праздники», «Закрытые даты», «Токены», «Перенос заявок». Интерфейс — vanilla JS (тот же `booking.js`, режим admin-grid) внутри страницы менеджера; запросы — `connector.php` → те же процессоры.
- **Rationale**: docs/01 «Vanilla JS, без React/Vue»; одна реализация сетки для CMP и PWA.

## R11. Перенос прежних заявок

- **Decision**: процессор `booking_migrate_leads` (запуск кнопкой в CMP, повторный запуск безопасен — переносится только то, чего ещё нет, связь по номеру прежней заявки): читает `bania_leads` только на чтение, сопоставляет дом по коду/названию, статусы: new→new, contacted→new (+заметка), confirmed→confirmed (через ту же проверку пересечений), done→completed, declined→cancelled. Отчёт о перенесённых и конфликтах. Вывод из работы прежних «Заявок» (пункт меню bania, таблицы) — отдельная кнопка после проверки переноса (ст. 2.4).

## R12. Тестирование

- **Decision**: PHP-тесты процессоров запускаются на стенде через CLI-скрипт (`_build/tests/run.php`, MODX initialize('mgr')): расчёт цены (все типы дней, праздники, особая цена, гости, питомцы, скидка), пересечения, права гостя/админа; тест одновременности — два параллельных запроса подтверждения (curl) → ровно один успех. E2E — Playwright (Python, как у сайта): форма гостя, ошибки, SendIt, «моя заявка», CMP, PWA с токеном и без, скриншоты 390/1440.

## Открытые риски

1. MaxNotify3 и зависимость от miniShop3 (R8).
2. Правка `tools/modx_build.py` для сохранения папок booking (R1) — нужна санкция владельца.
3. Брони по телефону — принято допущение «полноценная бронь за гостя» (spec, Assumptions), не подтверждено владельцем.
4. Внедрение `booking.prices` на страницы домов и `booking.form` вместо формы сайта меняет вывод страниц (вставка вызовов в шаблоны/чанки сайта) — по ст. 2.2 разрешено только вставкой вызова; делается последним этапом, отдельным коммитом.
