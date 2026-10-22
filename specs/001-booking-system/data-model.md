# Data Model: компонент booking

Все таблицы — с префиксом сайта и `booking_` (например `modx_booking_houses`), InnoDB, utf8mb4. Деньги — целые рубли (`INT UNSIGNED`). Даты — `DATE` (Y-m-d), время — `TIME`, часовой пояс Europe/Moscow. Классы xPDO — `Booking\Model\*` из `schema/booking.mysql.schema.xml`. Предоплаты нет ни в одной таблице (ст. 2.1, 7.2).

## Сущности

### House — `booking_houses`
| Поле | Тип | Правила |
|---|---|---|
| id | INT PK | |
| code | VARCHAR(20) UNIQUE | en / ru / sv — совпадает с кодом дома на сайте (цвета, фото) |
| name | VARCHAR(100) | обязательно |
| slug | VARCHAR(100) UNIQUE | |
| description | TEXT | |
| address | VARCHAR(255) | |
| resource_id | INT NULL | страница дома на сайте (только для ссылки, без зависимости) |
| capacity_sleep | SMALLINT | > 0 |
| capacity_party | SMALLINT | ≥ capacity_sleep; максимум гостей в заявке |
| checkin_time / checkout_time | TIME | checkin ≥ checkout (иначе выезд и заезд в один день пересекаются) |
| addon_bookable_separately | TINYINT(1) | 0 по умолчанию |
| active | TINYINT(1) | неактивный дом не виден в форме |
| sort | SMALLINT | порядок в форме и сетке |

### Addon (баня) — `booking_addons`
| Поле | Тип | Правила |
|---|---|---|
| id, house_id (FK → houses) | INT | баня принадлежит дому (не отдельный дом) |
| name | VARCHAR(100) | «Баня» |
| price_per_hour | INT NULL | обязательно, если у дома включена отдельная бронь |
| min_hours | TINYINT | ≥ 1 |
| buffer_minutes | SMALLINT | 30 по умолчанию |
| active | TINYINT(1) | |

### PriceRule — `booking_price_rules`
| Поле | Тип | Правила |
|---|---|---|
| id | INT PK | |
| house_id | INT FK | UNIQUE(house_id, day_type) |
| day_type | ENUM(weekday, friday, saturday, sunday) | |
| price | INT | > 0 |
| extra_guest_price | INT | 500 по умолчанию; за всё время отдыха |
| extra_guest_limit | SMALLINT | норма гостей в цене: 10 (Свиязева 8) |
| pet_price | INT | 1 000 по умолчанию; за питомца за всё время отдыха |

`extra_guest_*` и `pet_price` хранятся в каждой строке правил дома; расчёт берёт их из строки weekday (одинаковы для всех типов дней — админка меняет их сразу во всех строках дома).

### Holiday — `booking_holidays`
| Поле | Тип | Правила |
|---|---|---|
| id | INT PK | |
| date | DATE UNIQUE | |
| holiday_type | ENUM(pre_holiday_workday, holiday_before_dayoff, holiday_before_workday) | → friday / saturday / sunday — правило в коде (ст. 6.2) |
| comment | VARCHAR(255) | |

### HolidayPrice (особая цена праздника) — `booking_holiday_prices`
| Поле | Тип | Правила |
|---|---|---|
| holiday_id (FK), house_id (FK) | INT | PK(holiday_id, house_id) |
| price | INT | > 0; если есть — берётся вместо тарифа по правилу (FR-003a) |

### Booking — `booking_bookings`
| Поле | Тип | Правила |
|---|---|---|
| id | INT PK | номер заявки для гостя |
| house_id | INT FK | |
| addon_id | INT NULL FK | заполнено — бронь бани отдельно (часы) |
| date_from / date_to | DATE | дом: заезд / выезд, date_to > date_from; баня: дата сеанса (date_to = date_from) |
| time_from / time_to | TIME NULL | только баня; длительность ≥ min_hours |
| guests_count | SMALLINT | 1…capacity_party |
| pets_count | TINYINT | ≥ 0 |
| guest_name | VARCHAR(100) | обязательно |
| guest_phone | VARCHAR(20) | +7 и 10 цифр |
| guest_comment | TEXT | |
| quote_total | INT | итог расчёта сервера на момент заявки |
| quote_discount | INT | сумма скидки |
| quote_lines | JSON | строки расчёта (сутки, доплаты, скидка) — для карточки |
| status | ENUM(new, confirmed, cancelled, completed) | см. переходы |
| cancellation_requested | TINYINT(1) | гость попросил отмену |
| cancellation_requested_by | ENUM(guest, admin) NULL | |
| cancellation_reason | TEXT | |
| deposit_amount | INT NULL | NULL или 5 000…20 000 |
| deposit_status | ENUM(not_set, pending, paid, returned) | not_set при создании |
| source | ENUM(site, pwa, max, admin, migrated) | откуда пришла |
| legacy_lead_id | INT NULL UNIQUE | номер прежней заявки сайта (перенос) |
| guest_token_hash | CHAR(64) | SHA-256 персонального кода гостя |
| admin_note | TEXT | |
| created_at, updated_at | DATETIME | |
| updated_by | VARCHAR(100) | пользователь MODX / владелец токена / «гость» |

Индексы: (house_id, status, date_from), (status), (guest_phone).

### BookedNight — `booking_nights` (страховка от двойной брони)
| Поле | Тип | Правила |
|---|---|---|
| house_id, night | INT, DATE | PRIMARY KEY (house_id, night) |
| booking_id | INT FK | |

Строки появляются только у подтверждённых броней дома и удаляются при отмене. Закрытые даты сюда не пишутся (проверяются запросом).

### BlockedSlot — `booking_blocked_slots`
| Поле | Тип | Правила |
|---|---|---|
| id | INT PK | |
| house_id | INT FK | |
| addon_id | INT NULL | закрыта только баня |
| date_from / date_to | DATE | включительно по ночам: закрыты ночи date_from … date_to−1 (как у брони) |
| time_from / time_to | TIME NULL | для бани |
| reason | VARCHAR(255) | обязательно |
| created_by, created_at | | |

### AdminToken — `booking_admin_tokens`
| Поле | Тип | Правила |
|---|---|---|
| id | INT PK | |
| user_id | INT | пользователь MODX — владелец |
| label | VARCHAR(100) | «Телефон Ольги» |
| token_hash | CHAR(64) UNIQUE | сам токен показывается один раз при выпуске |
| created_at, last_used_at, revoked_at | DATETIME | отозванный не принимается |

### LogEntry — `booking_log`
| Поле | Тип |
|---|---|
| id, created_at | |
| entity (booking / house / price / holiday / block / token), entity_id | |
| action (create, confirm, cancel, deposit, update…) | |
| old_value, new_value | JSON |
| actor | VARCHAR(100) — пользователь, владелец токена или «гость» |
| interface | ENUM(cmp, api, pwa, max, site, cli) |

## Переходы статусов брони

```
new ──confirm (админ, проверка пересечений)──▶ confirmed ──complete (админ)──▶ completed
 │                                               │
 └──cancel (админ)──▶ cancelled ◀──cancel (админ)─┘
гость: request_cancel → cancellation_requested = 1 (статус не меняется)
админ: create_manual → сразу confirmed (та же проверка пересечений)
```

Залог: `not_set → pending → paid → returned`; назначается только при `confirmed` (сумма 5 000–20 000), меняется только администратором. Отмена брони не меняет статус залога.

## Правила проверки (сервер)

- date_from не в прошлом (для гостя), date_to > date_from; гостей ≤ capacity_party; дом активен.
- Гость не передаёт и не может изменить: status, deposit_amount, deposit_status, quote_* (пересчитываются сервером), source, updated_by.
- Подтверждение и ручная бронь: транзакция, `SELECT … FOR UPDATE` строки дома, пересечение с confirmed-бронями дома (по ночам), закрытыми датами, сеансами бани своего дома; запись `booking_nights` (PK отвергает дубль).
- Баня: сеанс [time_from − buffer, time_to + buffer) не пересекает другие confirmed-сеансы этой бани и закрытые слоты; дата сеанса не входит в ночи confirmed-брони дома.
