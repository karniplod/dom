# Implementation Plan: Система бронирования домов (компонент booking)

**Branch**: `001-booking-system` | **Date**: 2026-10-22 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/001-booking-system/spec.md`

## Summary

Изолированное дополнение MODX 3 `booking` (транспортный пакет): свои таблицы домов, бань, цен, праздников (с особыми ценами дат), броней, занятых ночей, закрытых дат, токенов и журнала. Все изменения — через процессоры, которые одинаково вызываются из админки MODX (`connector.php`) и из сайта/PWA/MAX (`api.php`). Гость оформляет заявку через единую форму `booking.form` (full/compact), отправка — SendIt (пресет `booking_request`: хук компонента → письмо → MaxNotify3). Даты занимают только подтверждённые брони: проверка пересечений в транзакции с блокировкой строки дома и уникальным ключом ночей. Цены на сайте — `booking.prices`. Прежние «Заявки» сайта переносятся и выводятся из работы. Детали — [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.2+ (MODX 3.2.x); JavaScript ES2017 без сборщика (vanilla); HTML/CSS (токены сайта)

**Primary Dependencies**: MODX 3.2 (xPDO 3), SendIt 3.1+ (modstore.pro), MaxNotify3 (modstore.pro, хук `maxNotify3SendIt`), ClientConfig (уже на сайте, не обязателен компоненту)

**Storage**: MySQL/MariaDB (штатная база MODX), таблицы `{prefix}booking_*` — [data-model.md](data-model.md)

**Testing**: CLI-тесты процессоров на стенде (`_build/tests/run.php`), Playwright (Python) для форм, CMP и PWA, проверка одновременности параллельными запросами — [quickstart.md](quickstart.md)

**Target Platform**: хостинг Beget, Apache + PHP 8.2/8.3, MODX 3.2; браузеры сайта, PWA на Android/iOS, мини-приложение MAX

**Project Type**: дополнение CMS (серверные процессоры + JSON API + сниппеты + CMP + PWA)

**Performance Goals**: занятость и расчёт — ответ < 0,5 с на хостинге; оформление заявки гостем < 2 мин (SC-001)

**Constraints**: занятость не кешируется (ни HTTP, ни Service Worker); деньги — целые рубли; даты Y-m-d, Europe/Moscow; никаких полей предоплаты; нет правок `bn*`, `src/`, шаблонов сайта кроме вставки вызовов `[[!booking.*]]`

**Scale/Scope**: 3 дома (+ до 3 бань), сотни броней в год, 1–3 администратора, ~20 процессоров, 8 вкладок CMP, 1 PWA

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Статья | Проверка | Статус |
|---|---|---|
| 1.1–1.3 Изоляция | отдельный пакет `booking`, свои таблицы; сайт не зависит от компонента (SC-007, сценарий 17) | ✅ |
| 1.4 Только процессоры | api.php и connector.php вызывают процессоры; из JS нет SQL | ✅ |
| 2.1, 7.2 Предоплата | ни одного поля/действия предоплаты в data-model и API | ✅ |
| 2.2 Шаблоны сайта | только вставка `[[!booking.form]]`, `[[booking.prices]]` — отдельный последний этап | ✅ (риск 4 в research) |
| 2.3, 7.6 bn* | не изменяются; вывод прежних «Заявок» — по п. 2.4 | ✅ |
| 3.1–3.6, 7.7, 7.8 Роли | одни процессоры, роль из токена/сессии, одинаковые проверки | ✅ |
| 4 Залог | необязателен, 5 000–20 000, только админ | ✅ |
| 5.1 Транзакции | FOR UPDATE + PK ночей | ✅ |
| 5.2 Занятость | только confirmed + закрытые; no-store; SW только статика | ✅ |
| 5.3 Гость | status/deposit_* игнорируются, админ-действия → 401 | ✅ |
| 6 Цены | в БД, правило праздников в коде, особые цены дат — данные, `booking.prices` | ✅ |
| 7.1 Пути файлов | весь код в `modx/core/components/booking/`, `modx/assets/components/booking/` | ⚠️ нужна правка `tools/modx_build.py` — см. Complexity Tracking |
| 7.3 Один календарь | один JS-модуль `booking.js` (режимы guest/admin-grid) | ✅ |
| 7.4 Секреты | токены — хеши в БД, MAX — настройки MaxNotify3 | ✅ |
| 7.5 MAX endpoint | `https://platform-api2.max.ru/messages` (MaxNotify3 по умолчанию) | ✅ |
| 8 Уведомления | SendIt `booking_request`, email + `maxNotify3SendIt`; гостю — после подтверждения | ✅ (риск: MaxNotify3 ↔ miniShop3) |

**Повторная проверка после Phase 1**: data-model, contracts и quickstart не вводят нарушений; единственное исключение — правка сборщика сайта (ниже), требует санкции владельца до начала реализации.

## Project Structure

### Documentation (this feature)

```text
specs/001-booking-system/
├── plan.md              # этот файл
├── research.md          # решения и риски
├── data-model.md        # таблицы, переходы статусов, правила проверки
├── quickstart.md        # сценарии проверки на стенде
├── contracts/
│   ├── api.md           # действия api.php / connector.php
│   └── elements.md      # сниппеты, чанки, пресет SendIt, настройки, CMP, PWA
└── tasks.md             # /speckit-tasks (ещё не создан)
```

### Source Code (repository root)

```text
modx/core/components/booking/
├── bootstrap.php                 # PSR-4 Booking\ → src/, сервис booking
├── composer.json
├── schema/booking.mysql.schema.xml
├── src/
│   ├── Model/                    # сгенерированные классы xPDO (booking_*)
│   ├── Service/                  # Booking (вход), Pricing, Availability, Access, Log, Notify
│   └── Processors/               # Availability/Get, Quote, Request/{Create,Get,Cancel},
│                                 # Booking/{List,Grid,Get,Confirm,Cancel,Complete,SetDeposit,CreateManual,Update},
│                                 # House, Addon, Prices, Holiday, Block, Token, Migrate
├── controllers/index.class.php   # CMP «Бронирование»
├── elements/snippets/            # booking.form, booking.prices, booking.request, booking.hook
├── elements/chunks/              # booking.form.tpl, …, booking.email.request
├── presets/booking_request.inc.php
├── lexicon/ru/, lexicon/en/
└── _build/                       # build.schema.php, build.transport.php, resolvers/, tests/

modx/assets/components/booking/
├── api.php                       # сайт, PWA, MAX
├── connector.php                 # CMP
├── js/booking.js                 # один модуль: форма гостя + сетка админа
├── css/booking.css
└── app/                          # PWA: index.html, manifest.webmanifest, sw.js
```

**Structure Decision**: один компонент-дополнение в двух разрешённых конституцией папках; сайт (`src/`, `build.py`, `tools/modx/`, пакет `bn`) не меняется, кроме одной строки сборщика (ниже) и финальной вставки вызовов на страницы.

## Phases (обзор для /speckit-tasks)

1. **Каркас и модель**: bootstrap, схема, генерация классов, резолвер таблиц и стартовых данных (3 дома, цены сайта, баня у каждого дома выключена отдельно), настройки, право `booking_admin`, сборка пакета.
2. **Ядро**: Pricing (все правила), Availability, Access (роли, токены), Log; процессоры гостя и админа; api.php и connector.php; тесты процессоров и одновременности.
3. **Форма гостя (P1)**: `booking.form` full/compact, `booking.js` календарь и расчёт, пресет SendIt + `booking.hook`, письма, MaxNotify3; страницы `/booking` и «Моя заявка».
4. **Админка (P1–P2)**: CMP — календарь-сетка, заявки, подтверждение/отмена/залог, ручная бронь; дома, бани, цены, праздники с особыми ценами, закрытые даты, токены.
5. **Гость: моя заявка и отмена (P2)**, **баня отдельно (P3)**.
6. **PWA/MAX (P3)**: приложение, вкладка админа по токену, SW без кеша занятости.
7. **Перенос и внедрение**: перенос `bania_leads`, вывод прежних «Заявок»; вставка `[[!booking.form]]` и `[[booking.prices]]` на страницы — отдельным согласованным шагом.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| Правка `tools/modx_build.py` (вне разрешённых путей ст. 7.1): вместо удаления всей папки `modx/` чистить только свои подпапки сборщика сайта | сборщик сайта при каждом запуске стирает `modx/` целиком — исходники `booking` в `modx/core/components/booking/` (путь из ст. 7.1) были бы уничтожены | хранить исходники booking в другом месте — нарушает пути ст. 7.1; не запускать сборщик сайта — ломает выпуск пакета сайта. **Требует явного разрешения владельца до начала реализации.** |
| Отдельный минимальный хук `booking.max` (только если MaxNotify3 нельзя поставить без miniShop3) | ст. 8.3 требует MaxNotify3, но его страница указывает зависимость от miniShop3 | ставить miniShop3 ради уведомлений — лишний магазинный модуль на сайте; решение после пробной установки на стенде |
