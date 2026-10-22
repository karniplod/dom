---

description: "Задачи реализации компонента бронирования booking"
---

# Tasks: Система бронирования домов (компонент booking)

**Input**: Design documents from `/specs/001-booking-system/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api.md, contracts/elements.md, quickstart.md

**Tests**: включены только для того, что спецификация требует проверять измеримо: расчёт цены (SC-003), пересечения и одновременность (SC-002, ст. 5.1), права гостя/админа (FR-016, FR-026b, ст. 5.3). Тесты пишутся раньше реализации своей части и сначала падают.

**Organization**: по пользовательским сценариям spec.md (US1–US6); перенос прежних заявок и внедрение на страницы сайта — финальная фаза.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: можно параллельно (разные файлы, нет зависимости от незавершённых задач)
- **[Story]**: сценарий из spec.md (US1…US6)

## Path Conventions

- `C/` = `modx/core/components/booking/`
- `A/` = `modx/assets/components/booking/`
- Стенд: `C:\cloud\modx-bania` (MODX 3.2.4, база `bania`, порт 8091) — см. quickstart.md
- Никаких правок `src/`, сниппетов `bn*`, шаблонов сайта (кроме T090–T092 — вставка вызовов) и полей предоплаты (ст. 2, 7)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: каркас дополнения и стенд

- [X] T001 (разрешено владельцем 22.10.2026) В `tools/modx_build.py` заменить `shutil.rmtree(OUT)` на очистку только своих путей сборщика сайта (`modx/chunks`, `modx/templates`, `modx/snippets`, `modx/component`, `modx/resources.json`, `modx/bn-install*.php`, `modx/INSTALL.md`, `modx/.htaccess`), чтобы `modx/core/` и `modx/assets/` сохранялись; проверить `python tools/modx_build.py` — пакет сайта собирается как раньше
- [X] T002 Создать структуру каталогов по plan.md: `C/{src/Model,src/Service,src/Processors,controllers,elements/snippets,elements/chunks,presets,lexicon/ru,lexicon/en,schema,_build/resolvers,_build/tests}` и `A/{js,css,app}`
- [X] T003 [P] Создать `C/composer.json` (PSR-4 `"Booking\\": "src/"`) и `C/bootstrap.php`: регистрация автозагрузки `Booking\` и сервиса `booking` (`Booking\Service\Booking`) в `$modx->services`
- [X] T004 [P] Создать лексиконы `C/lexicon/ru/default.inc.php` и `C/lexicon/en/default.inc.php` (меню «Бронирование», статусы new/confirmed/cancelled/completed, статусы залога not_set/pending/paid/returned, тексты ошибок пересечения и диапазона залога)
- [X] T005 [P] Создать `C/_build/build.config.php` (имя `booking`, версия 1.0.0-pl, пути `C/`, `A/`) и `C/_build/build.transport.php` по правилам MODX 3 (modPackageBuilder, registerNamespace `booking` → `{core_path}components/booking/`, категория «Бронирование», файловые vehicles для `C/` и `A/`, резолверы, setPackageAttributes, pack) — выход в `core/packages/` стенда
- [X] T006 Поставить на стенд SendIt (уже в кеше пакетов `C:\cloud\modx-test\www\core\packages\sendit-3.1.6-pl.transport.zip`) и пробно MaxNotify3 (modstore.pro); записать в `specs/001-booking-system/research.md` (R8) итог: ставится ли без miniShop3

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: модель, сервисы, точки входа — блокирует все сценарии

**⚠️ CRITICAL**: сценарии начинаются только после этой фазы

- [X] T007 Описать схему `C/schema/booking.mysql.schema.xml` (`package="Booking\Model"`, `version="3.0"`) по data-model.md: `booking_houses` (code VARCHAR(20) UNIQUE, slug UNIQUE, capacity_sleep, capacity_party, checkin_time/checkout_time TIME, addon_bookable_separately TINYINT(1) DEFAULT 0, active, sort, resource_id NULL), `booking_addons` (house_id FK, price_per_hour INT NULL, min_hours ≥ 1, buffer_minutes DEFAULT 30), `booking_price_rules` (UNIQUE(house_id, day_type), day_type ENUM(weekday,friday,saturday,sunday), price, extra_guest_price DEFAULT 500, extra_guest_limit, pet_price DEFAULT 1000), `booking_holidays` (date UNIQUE, holiday_type ENUM(pre_holiday_workday,holiday_before_dayoff,holiday_before_workday), comment), `booking_holiday_prices` (PK(holiday_id, house_id), price), `booking_bookings` (поля data-model.md; status ENUM(new,confirmed,cancelled,completed); deposit_amount INT NULL «NULL или 5 000…20 000»; deposit_status ENUM(not_set,pending,paid,returned) DEFAULT not_set; source ENUM(site,pwa,max,admin,migrated); legacy_lead_id UNIQUE NULL; guest_token_hash CHAR(64); quote_total, quote_discount INT; quote_lines JSON; индексы (house_id,status,date_from),(status),(guest_phone)), `booking_nights` (PRIMARY KEY(house_id, night), booking_id), `booking_blocked_slots`, `booking_admin_tokens` (token_hash CHAR(64) UNIQUE, revoked_at NULL), `booking_log`; ни одного поля предоплаты
- [X] T008 Создать `C/_build/build.schema.php` (генерация классов `C/src/Model/` через `xPDO\Om\xPDOGenerator::parseSchema`) и сгенерировать модель на стенде
- [X] T009 Создать резолвер `C/_build/resolvers/tables.php`: `createObjectContainer` для всех классов, на обновлении — `addField`/`addIndex` с проверкой существования; ничего не удалять
- [X] T010 Создать резолвер `C/_build/resolvers/seed.php` (только при первой установке, если таблица домов пуста): дома Английский (en, 15:00/12:00, ночуют 12, отдыхают 20, норма 10), Русский (ru, 17:00/14:00, 12/20, норма 10), Свиязева (sv, 14:00/11:00, 8/15, норма 8); цены weekday/friday/saturday/sunday: en и ru 18000/22000/27000/19000, sv 15000/20000/25000/17000; extra_guest_price 500, pet_price 1000; у каждого дома баня `active=1`, `addon_bookable_separately=0`, `price_per_hour=NULL`, `min_hours=2`, `buffer_minutes=30`; праздников и особых цен нет
- [X] T011 Создать резолвер `C/_build/resolvers/settings.php`: системные настройки namespace `booking` — `booking.admin_email`, `booking.site_discount`=5, `booking.request_link_page`, `booking.form_page`, `booking.timezone`=Europe/Moscow, `booking.rate_limit`=30; право доступа `booking_admin` в политике и выдача группе Administrator
- [X] T012 [P] Сервис журнала `C/src/Service/Log.php`: `write(entity, entity_id, action, old, new, actor, interface)` в `booking_log`
- [X] T013 [P] Сервис доступа `C/src/Service/Access.php`: определение роли (сессия менеджера с правом `booking_admin` → admin/cmp; `Authorization: Bearer` → поиск по SHA-256 в `booking_admin_tokens`, не отозван → admin/api с владельцем; иначе guest), обновление `last_used_at`, ограничение частоты гостевых запросов (`booking.rate_limit` в минуту с IP, кеш MODX)
- [X] T014 Тесты расчёта `C/_build/tests/PricingTest.php` (сначала падают): Пн–Чт/Пт/Сб/Вс по дате заезда суток; праздник pre_holiday_workday→friday, holiday_before_dayoff→saturday, holiday_before_workday→sunday; особая цена дома на праздничную дату перекрывает правило (FR-003a); гости сверх extra_guest_limit × 500 один раз; питомцы × 1000 один раз; скидка booking.site_discount на всю сумму отдельной строкой; целые рубли; пример quickstart №1 (Русский 5–7 декабря, 14 гостей)
- [X] T015 Сервис цены `C/src/Service/Pricing.php`: `quote(house, from, to, guests, pets, addon=null, timeFrom=null, timeTo=null)` → `lines[{label,qty,amount}]`, `discount`, `total`; правило праздников в коде (ст. 6.2); цена бани = price_per_hour × часы; при отсутствии цены дня — признак `price_missing`; T014 проходит
- [X] T016 Тесты пересечений `C/_build/tests/AvailabilityTest.php` (сначала падают): ночи дома date_from…date_to−1; выезд и заезд в один день не пересекаются; confirmed пересекается, new — нет; закрытые даты; баня: буфер 30 минут, сеанс в ночь confirmed-брони своего дома запрещён
- [X] T017 Сервис занятости `C/src/Service/Availability.php`: `busyNights(house, from, to)` (только confirmed + закрытые), `slotsBusy(addon, date)`, `assertFree(houseId, …)` внутри транзакции: `SELECT … FOR UPDATE` строки `booking_houses`, поиск пересечений, запись/удаление строк `booking_nights`, исключение с номером пересекающейся брони; T016 проходит
- [X] T018 Главный сервис `C/src/Service/Booking.php`: доступ к Pricing/Availability/Access/Log, путь процессоров, `runAction(action, params, actor)` — единая точка для `api.php` и `connector.php`
- [X] T019 Точка входа `A/api.php`: загрузка MODX (web), `Access` → роль, `runAction` → JSON `{success, message, data}`, заголовки `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store`; коды 400/401/403/409/429; админские действия без роли admin → 401; поля `status`, `deposit_amount`, `deposit_status`, `quote_*`, `source` из гостевого запроса отбрасываются
- [X] T020 Точка входа `A/connector.php` для CMP (стандартный коннектор MODX 3, `HTTP_MODAUTH`) → те же процессоры через `runAction`
- [X] T021 Тесты прав `C/_build/tests/AccessTest.php`: гость не вызывает booking_confirm/cancel/set_deposit/manage_* (401), подделанные status/deposit_* в гостевом запросе игнорируются, отозванный токен не работает; запускатель `C/_build/tests/run.php` (MODX initialize('mgr'), прогон всех *Test.php)

**Checkpoint**: модель, расчёт, занятость, роли и обе точки входа готовы; `php C/_build/tests/run.php` — зелёный

---

## Phase 3: User Story 1 — Гость видит свободные даты и отправляет заявку (Priority: P1) 🎯 MVP

**Goal**: форма `booking.form` (full/compact) с календарём занятости, расчётом и отправкой через SendIt; заявка «новая», письмо админу, MAX

**Independent Test**: quickstart №1–3, №9, №18: заявка на свободные даты сохранена со статусом new и суммой формы, админ получил письмо; на занятые/закрытые даты — отказ «даты заняты»; вторая заявка на те же даты принимается

- [X] T022 [P] [US1] Процессор `booking_get_availability` в `C/src/Processors/Availability/Get.php` (house code, from, to ≤ 92 дня; для гостя — один список `busy`; для бани — `slots_busy`)
- [X] T023 [P] [US1] Процессор `booking_quote` в `C/src/Processors/Quote.php` (вызов Pricing, валидация дат и гостей ≤ capacity_party)
- [X] T024 [US1] Процессор `booking_create_request` в `C/src/Processors/Request/Create.php`: валидация (дата заезда не в прошлом, date_to > date_from, guests 1…capacity_party, pets ≥ 0, имя, телефон «+7 и 10 цифр»), проверка отсутствия пересечения с confirmed и закрытыми (без блокировки, new не занимает даты), сервер пересчитывает цену, сохраняет status=new, deposit_amount=NULL, deposit_status=not_set, source, guest_token_hash (SHA-256 случайных 32 hex), журнал; возвращает id и код гостя
- [X] T025 [US1] Хук SendIt `booking.hook` в `C/elements/snippets/booking.hook.php`: `booking_create_request` через сервис; ошибки → `$hook->addError('dates'|'guests'|'house', текст)` и false; успех → сессия `booking.last` (id, код), значения для письма (`booking_id`, `house_name`, `dates`, `guests`, `pets`, `total`, `link`)
- [X] T026 [P] [US1] Пресет SendIt `C/presets/booking_request.inc.php` по contracts/elements.md (`extends default`, `hooks` = `booking.hook,email,maxNotify3SendIt` — последний только если MaxNotify3 установлен, `emailTo` из `booking.admin_email`, `emailTpl` `booking.email.request`, validate с `agree:checkbox:required`, usePoW/useBehaviorSign). Подключается сниппетом `booking.form` в сессию посетителя, как штатный `RenderForm` SendIt, плюс запись в `siConfig.protectionMap` — файл пресетов сайта и `si_path_to_presets` не меняются (резолвер не нужен)
- [X] T027 [P] [US1] Чанк письма `C/elements/chunks/booking.email.request.tpl` (номер, дом, даты, гости, питомцы, сумма и скидка, гость, телефон, комментарий, ссылка в админку)
- [X] T028 [P] [US1] Чанки формы `C/elements/chunks/booking.form.tpl` (full) и `C/elements/chunks/booking.form.compact.tpl` (compact): поля `house, addon, date_from, date_to, time_from, time_to, guests, pets, name, phone, comment, agree, sms`, `data-si-form="booking_request" data-si-preset="booking_request"`, места ошибок `data-si-error`, блок расчёта, текст «Администратор может назначить залог 5 000–20 000 ₽ — скажет при подтверждении»; классы и кнопки в стиле сайта (`btn`, Prata, `--fox-btn`)
- [X] T029 [US1] Сниппет `booking.form` в `C/elements/snippets/booking.form.php` (`&mode=full|compact`, `&house`): список активных домов, подключение `A/js/booking.js` и `A/css/booking.css` один раз на страницу, выбор чанка по mode
- [X] T030 [US1] JS-модуль `A/js/booking.js`, режим guest: календарь (2 месяца full, 1 — compact), занятые даты серые и не выбираются (данные `booking_get_availability` при каждом показе, без кеша), расчёт через `booking_quote` при изменении полей, маска телефона, события SendIt: при успехе — запрос `booking_get_request&mine=1` и показ номера и ссылки, при ошибке — ошибки у полей
- [X] T031 [P] [US1] Стили `A/css/booking.css` в токенах сайта (занято — серый, свободно — белый/зелёный, выбрано — `--fox`), мобильная ширина 390 px без горизонтального скролла
- [X] T032 [US1] Ресурс `/booking` (шаблон сайта для текстовой страницы, контент `[[!booking.form? &mode=`full`]]`) создаётся резолвером `C/_build/resolvers/resources.php`; id — в `booking.form_page`
- [X] T033 [US1] E2E Playwright `C/_build/tests/e2e_us1.py`: quickstart №1–3, №9 (гостевые поля игнорируются), №18 (сбой почты — заявка сохранена), скриншоты 390/1440, консоль чистая

**Checkpoint**: гость оформляет заявку; MVP можно показывать

---

## Phase 4: User Story 2 — Администратор подтверждает, отменяет и ведёт залог (Priority: P1)

**Goal**: CMP «Бронирование»: календарь-сетка, заявки, подтверждение с залогом, отмена, завершение, ручная бронь

**Independent Test**: quickstart №4–7: подтверждение делает ночи серыми; пересекающееся подтверждение — 409 с номером; параллельные подтверждения — ровно один успех; залог 3 000 — отказ

- [X] T034 [P] [US2] Процессоры списка и сетки `C/src/Processors/Booking/GetList.php` (`booking_list`: фильтры status, house, from, to, q, страницы) и `C/src/Processors/Booking/Grid.php` (`booking_grid`: строки дом/баня, ячейки по датам — статус, id, имя гостя, отметка отмены)
- [X] T035 [P] [US2] Процессор карточки `C/src/Processors/Booking/Get.php` (`booking_get`: все поля + журнал)
- [X] T036 [US2] Процессор `booking_confirm` в `C/src/Processors/Booking/Confirm.php`: только new → confirmed; `Availability::assertFree` в транзакции + запись `booking_nights`; необязательный deposit_amount «NULL или 5 000…20 000» → deposit_status pending; 409 с `conflict {booking_id, nights}`; повторное подтверждение не создаёт дублей; журнал
- [X] T037 [P] [US2] Процессоры `C/src/Processors/Booking/Cancel.php` (`booking_cancel`: reason обязателен, освобождает `booking_nights`, бронь не удаляется, статус залога сохраняется) и `C/src/Processors/Booking/Complete.php` (`booking_complete`: confirmed → completed)
- [X] T038 [P] [US2] Процессор `booking_set_deposit` в `C/src/Processors/Booking/SetDeposit.php`: сумма 5 000–20 000 или NULL; переходы not_set → pending → paid → returned; только confirmed/completed/cancelled-с-залогом; журнал
- [X] T039 [US2] Процессор `booking_create_manual` в `C/src/Processors/Booking/CreateManual.php` (решение владельца 22.10.2026: бронь за гостя по телефону) — сразу confirmed через ту же проверку, source=admin
- [X] T040 [US2] Тест одновременности `C/_build/tests/concurrency_confirm.php`: две пересекающиеся заявки, два отдельных процесса PHP (свои соединения с БД) в одну миллисекунду вызывают `booking_confirm` → ровно один 200 и один 409 (SC-002); 10 раундов. (PHP вместо Python: встроенный сервер PHP на Windows однопоточный, гонку через HTTP не воспроизвести)
- [X] T041 [US2] Контроллер CMP `C/controllers/index.class.php` (`BookingIndexManagerController`, право `booking_admin`) и резолвер `C/_build/resolvers/menu.php` (пункт «Пакеты → Бронирование»)
- [X] T042 [US2] Режим admin-grid в `A/js/booking.js` (тот же модуль, ст. 7.3): сетка «дома и бани × даты», подсказка при наведении (имя гостя, кратко), карточка по клику с кнопками «Подтвердить» (+ поле залога), «Отменить» (причина), «Завершить», «Залог»; цвета docs/04 (подтверждено — зелёный, запрошена отмена — оранжевый)
- [X] T043 [US2] Вкладки CMP «Календарь» и «Заявки» (список с фильтрами, ручная бронь) в `C/controllers/index.class.php` + `A/css/booking.css`
- [X] T044 [US2] E2E `C/_build/tests/e2e_us2.py`: quickstart №4–7 в CMP

**Checkpoint**: US1 + US2 — полный цикл «заявка → подтверждение»

---

## Phase 5: User Story 3 — Гость смотрит свою заявку и просит отмену (Priority: P2)

**Goal**: страница «Моя заявка» по ссылке или номеру и телефону, запрос отмены

**Independent Test**: quickstart №8: запрос отмены ставит отметку, статус и занятость не меняются, админ уведомлён; чужая заявка/неверный телефон — не показывается

- [X] T045 [P] [US3] Процессор `booking_get_request` в `C/src/Processors/Request/Get.php`: доступ по id+код (сравнение SHA-256), id+телефон или `mine=1` (сессия `booking.last`); отдаёт только гостевые поля (id, дом, даты, гости, питомцы, total, status, cancellation_requested, deposit_amount, deposit_status, link)
- [X] T046 [US3] Процессор `booking_request_cancel` в `C/src/Processors/Request/Cancel.php`: cancellation_requested=1, cancellation_requested_by=guest, reason; статус и `booking_nights` не меняются; уведомление админу письмом (`booking.email.cancel`) и в MAX (если MaxNotify3 установлен); журнал
- [X] T047 [P] [US3] Чанки `C/elements/chunks/booking.request.tpl` и `C/elements/chunks/booking.email.cancel.tpl`
- [X] T048 [US3] Сниппет `booking.request` в `C/elements/snippets/booking.request.php` и ресурс «Моя заявка» в `C/_build/resolvers/resources.php` (id — `booking.request_link_page`); режим «моя заявка» в `A/js/booking.js` (ввод номера и телефона, кнопка «Попросить отменить»)
- [X] T049 [US3] E2E `C/_build/tests/e2e_us3.py`: quickstart №8 и проверка чужой заявки

---

## Phase 6: User Story 4 — Администратор управляет домами, ценами, праздниками и блокировками (Priority: P2)

**Goal**: справочники в CMP; цены на сайте через `booking.prices`

**Independent Test**: quickstart №10, №11, №15: особая цена праздника, закрытая дата серая, новая цена видна в `booking.prices` и в расчёте

- [ ] T050 [P] [US4] Процессор `booking_manage_house` в `C/src/Processors/House.php` (list/save/toggle; checkin_time ≥ checkout_time; capacity_party ≥ capacity_sleep)
- [ ] T051 [P] [US4] Процессор `booking_manage_prices` в `C/src/Processors/Prices.php` (цены weekday/friday/saturday/sunday > 0, extra_guest_price, extra_guest_limit, pet_price — во всех строках дома; очистка кеша ресурсов)
- [ ] T052 [P] [US4] Процессор `booking_manage_holiday` в `C/src/Processors/Holiday.php` (list/save/delete; holiday_type из трёх значений; особые цены по домам в `booking_holiday_prices`)
- [ ] T053 [P] [US4] Процессор `booking_manage_block` в `C/src/Processors/Block.php` (reason обязателен; 409 при пересечении с confirmed — не создавать молча)
- [ ] T054 [US4] Сниппет `booking.prices` в `C/elements/snippets/booking.prices.php` и чанк `C/elements/chunks/booking.prices.tpl` — разметка `.price-days` как в блоке «Цена за сутки» сайта (самый дорогой день — `class="hot"`)
- [ ] T055 [US4] Вкладки CMP «Дома и бани», «Цены», «Праздники» (с особыми ценами по домам), «Закрытые даты» в `C/controllers/index.class.php` и `A/js/booking.js`
- [ ] T056 [US4] E2E `C/_build/tests/e2e_us4.py`: quickstart №10, №11, №15

---

## Phase 7: User Story 5 — Администратор работает с телефона (PWA / MAX) (Priority: P3)

**Goal**: приложение с гостевой частью и вкладкой администратора по токену, те же процессоры

**Independent Test**: quickstart №13, №14: без токена админ-вкладка недоступна; с токеном подтверждение даёт те же отказы; офлайн занятость не показывается как актуальная

- [ ] T057 [US5] Процессор `booking_issue_token` в `C/src/Processors/Token.php` (issue — токен показывается один раз, хранится SHA-256; list; revoke) и вкладка CMP «Токены»
- [ ] T058 [P] [US5] PWA `A/app/index.html` + `A/app/manifest.webmanifest`: вкладки «Забронировать» (`booking.js` guest), «Моя заявка», «Администратор» (ввод токена → localStorage устройства, режим admin-grid с карточками); запросы — только `A/api.php` с `Authorization: Bearer`
- [ ] T059 [P] [US5] Service Worker `A/app/sw.js`: кеш только статики (html, css, js, иконки); `api.php` — только сеть, без кеша; офлайн — сообщение «нет связи, занятость не показывается»
- [ ] T060 [US5] Инструкция для мини-приложения MAX в `C/docs/max.md` (адрес приложения для кнопки бота, где взять токен) — без токенов в файле (ст. 7.4)
- [ ] T061 [US5] E2E `C/_build/tests/e2e_us5.py`: quickstart №13, №14 (Playwright, offline), повтор T040 через PWA

---

## Phase 8: User Story 6 — Гость бронирует баню отдельно (Priority: P3)

**Goal**: почасовая бронь бани у домов, где администратор её включил

**Independent Test**: quickstart №12: 18:00 после сеанса 15–18 недоступно, 18:30 доступно; баня в ночь confirmed-брони дома — отказ

- [ ] T062 [P] [US6] Процессор `booking_manage_addon` в `C/src/Processors/Addon.php` (price_per_hour обязателен при включении addon_bookable_separately; min_hours ≥ 1; buffer_minutes) и поля бани во вкладке «Дома и бани»
- [ ] T063 [US6] Бронь бани в `C/src/Processors/Request/Create.php` и `C/src/Processors/Booking/Confirm.php`: addon_id, date_from=date_to, time_from/time_to (≥ min_hours), проверка буфера и ночей дома через `Availability`
- [ ] T064 [US6] Выбор «только баня» и почасовой выбор в `A/js/booking.js` и `C/elements/chunks/booking.form.tpl` (только если у дома включено)
- [ ] T065 [US6] E2E `C/_build/tests/e2e_us6.py`: quickstart №12

---

## Phase 9: Перенос прежних заявок и внедрение на сайт (FR-034, FR-004, ст. 2.4)

**Purpose**: перевод сайта на booking — отдельными согласованными шагами

- [ ] T066 Процессор `booking_migrate_leads` в `C/src/Processors/Migrate.php`: preview (чтение `{prefix}bania_leads` только на чтение), run (статусы new→new, contacted→new + заметка, confirmed→confirmed через `Availability::assertFree`, done→completed, declined→cancelled; дом по коду/названию; legacy_lead_id; повтор ничего не дублирует; отчёт о конфликтах), retire (снять пункт меню «Заявки» и пространство имён bania после подтверждения в CMP; таблицы bania_* — переименовать в `*_archived`, не удалять)
- [ ] T067 Вкладка CMP «Перенос заявок» (предпросмотр → перенос → вывод из работы) в `C/controllers/index.class.php`
- [ ] T068 E2E `C/_build/tests/e2e_migrate.py`: quickstart №16 на копии базы стенда
- [ ] T069 ⚠️ После согласования с владельцем: заменить форму в чанках сайта `bn.form` (окно и блок «Оставьте заявку») вызовом `[[!booking.form? &mode=`compact` &house=`[[+house]]`]]` — только вставка вызова на место формы (ст. 2.2); выполнять в админке стенда/хостинга, не в `src/` и не в `tools/modx/`
- [ ] T070 ⚠️ После согласования: в шаблоне дома `bn-house` заменить вызов блока цен (`[[bnHouse? &part=`prices`]]`) на `[[booking.prices? &house=`[[*bn_code]]`]]` — только вставка вызова
- [ ] T071 Сравнить страницы сайта до и после T069–T070 (сценарий сравнения страниц MODX со статичными) — отличия только в форме и блоке цен; quickstart №17 (отключить пакет — сайт работает)

---

## Phase 10: Polish & Cross-Cutting Concerns

- [ ] T072 [P] README пакета `C/docs/README.md` (установка, настройки, MaxNotify3, токены, cron не нужен, перенос заявок) и `C/docs/changelog.txt`
- [ ] T073 Сборка `booking-1.0.0-pl.transport.zip`, чистая установка на свежий MODX стенда и обновление поверх — данные сохраняются (чек-лист skill MODX: установка, обновление, журнал ошибок пуст, одновременность)
- [ ] T074 Проверка перед сдачей по конституции: нет SQL из JS, серверная проверка пересечений в транзакции, гостевой запрос не меняет status/deposit_*, нет токенов в коде, нет полей предоплаты, `src/` и `bn*` без изменений (`git diff --stat -- src tools/modx`)
- [ ] T075 Прогон quickstart.md целиком на стенде, скриншоты 390 и 1440 px, консоль чистая

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (1)**: T001 выполнена; остальное — сразу
- **Foundational (2)**: после Setup; блокирует все сценарии
- **US1 (3), US2 (4)**: после Foundational; US2 для проверки удобнее делать после US1 (заявки создаются формой), но может опираться на ручную бронь T039
- **US3 (5)**: после US1 (нужна заявка гостя)
- **US4 (6)**: после Foundational, независим от US1–US3
- **US5 (7)**: после US2 (админские процессоры и сетка)
- **US6 (8)**: после US1 и US4 (форма и управление банями)
- **Перенос и внедрение (9)**: после US1, US2, US4; T069–T070 — только с согласия владельца
- **Polish (10)**: после всех нужных сценариев

### Within Each User Story

- Тесты (где есть) пишутся первыми и падают
- Процессоры → точки входа уже готовы (Phase 2) → чанки/сниппеты → JS → E2E

### Parallel Opportunities

- Setup: T003, T004, T005 параллельно
- Foundational: T012, T013 параллельно; T014 и T016 (тесты) параллельно до T015/T017
- US1: T022, T023, T026, T027, T028, T031 параллельно
- US2: T034, T035, T037, T038 параллельно
- US4: T050–T053 параллельно
- US5: T058, T059 параллельно

---

## Parallel Example: User Story 1

```text
Task: "T022 [US1] Процессор booking_get_availability в C/src/Processors/Availability/Get.php"
Task: "T023 [US1] Процессор booking_quote в C/src/Processors/Quote.php"
Task: "T026 [US1] Пресет SendIt C/presets/booking_request.inc.php"
Task: "T027 [US1] Чанк письма C/elements/chunks/booking.email.request.tpl"
Task: "T028 [US1] Чанки формы booking.form.tpl / booking.form.compact.tpl"
Task: "T031 [US1] Стили A/css/booking.css"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 → Phase 2
2. Phase 3 (US1): форма, расчёт, отправка через SendIt
3. **STOP and VALIDATE**: quickstart №1–3, №9, №18
4. Показать владельцу на стенде

### Incremental Delivery

1. US1 → показ (заявки приходят, админ видит их в журнале/письме)
2. US2 → полный цикл «заявка → подтверждение → занятые даты»
3. US4 → цены и праздники из админки, `booking.prices`
4. US3 → «моя заявка» и отмена
5. US5 → телефон (PWA/MAX)
6. US6 → баня отдельно (когда владелец назовёт цены)
7. Перенос заявок и внедрение на страницы сайта — с согласия владельца

---

## Notes

- [P] = разные файлы, нет зависимостей; [Story] связывает задачу со сценарием
- Каждый сценарий проверяется отдельно по quickstart.md
- Коммит после каждой задачи или логической группы; перед коммитом — проверка T074
- ⚠️ T069, T070 — только после явного «да» владельца (T001 разрешена 22.10.2026)
