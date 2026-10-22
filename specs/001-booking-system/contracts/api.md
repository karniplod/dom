# Contract: API компонента booking

Один вход для сайта, PWA и мини-приложения MAX: `POST /assets/components/booking/api.php` (JSON или form-data), поле `action`. Админка MODX ходит в `/assets/components/booking/connector.php` с теми же `action` — оба вызывают одни и те же процессоры (ст. 3.3, 7.7).

Ответ всегда: `{"success": bool, "message": "…", "data": {…}}`, заголовки `Content-Type: application/json; charset=utf-8`, `Cache-Control: no-store` (занятость не кешируется — ст. 5.2). Коды HTTP: 200 успех; 400 неверные данные; 401 нужен токен; 403 нет прав; 409 пересечение дат; 429 слишком часто.

Роль определяется так: заголовок `Authorization: Bearer <токен>` с действующим токеном → администратор (владелец токена); иначе → гость. В админке — сессия менеджера с правом `booking_admin`. Гостевые действия ограничены 30 запросами в минуту с IP.

## Гостевые действия

| action | Вход | data в ответе | Ошибки |
|---|---|---|---|
| `booking_get_availability` | house (code), from, to (≤ 92 дня) [, addon, date — для бани] | `nights_busy: [Y-m-d]`, `nights_blocked: [Y-m-d]` (для гостя — один список `busy`), для бани `slots_busy: [{from,to}]` | 400 диапазон |
| `booking_quote` | house, date_from, date_to, guests, pets [, addon, time_from, time_to] | `lines: [{label, qty, amount}]`, `discount`, `total`, `checkin`, `checkout` | 400; `price_missing` — цена дня не задана |
| `booking_get_request` | id + token, или id + phone, или mine=1 (сессия после отправки) | заявка без служебных полей: id, house, даты, гости, питомцы, total, status, cancellation_requested, deposit_amount, deposit_status, `link` | 403 неверный код/телефон |
| `booking_request_cancel` | id + token (или id + phone), reason | заявка (cancellation_requested = 1) | 403; 400 уже отменена/завершена |

Создание заявки гостем идёт **не** через api.php, а через SendIt (пресет `booking_request`) → хук `booking.hook` → процессор `booking_create_request` (ст. 8.1). Ошибки хука возвращаются SendIt как ошибки полей: `dates` (заняты/неверны), `guests` (больше максимума), `house`.

Любые поля `status`, `deposit_amount`, `deposit_status`, `quote_*`, `source` в гостевом запросе игнорируются, а попытка вызвать админское действие без токена → 401 (ст. 5.3, FR-026b).

## Админские действия (токен или сессия менеджера)

| action | Вход | Результат |
|---|---|---|
| `booking_list` | status?, house?, from?, to?, q?, page | список заявок с краткими полями |
| `booking_grid` | from, to | строки «дом / баня», ячейки по датам: статус, id, имя гостя, отметка отмены — для сетки |
| `booking_get` | id | полная карточка + журнал |
| `booking_confirm` | id, deposit_amount? (5000–20000) | confirmed; 409 + `conflict: {booking_id, nights}` при пересечении |
| `booking_cancel` | id, reason | cancelled, ночи освобождены |
| `booking_complete` | id | completed |
| `booking_set_deposit` | id, deposit_amount?, deposit_status | 400 вне диапазона или неверный переход |
| `booking_create_manual` | как заявка + guest_name, guest_phone, deposit_amount? | сразу confirmed (та же проверка, 409) |
| `booking_update` | id, admin_note?, guests_count?, pets_count?, даты? | для confirmed смена дат — с проверкой пересечений |
| `booking_manage_house` | op=list/save/toggle, поля дома | |
| `booking_manage_addon` | op=list/save/toggle, поля бани | |
| `booking_manage_prices` | house, rules[day_type→price], extra_guest_price, extra_guest_limit, pet_price | |
| `booking_manage_holiday` | op=list/save/delete, date, holiday_type, comment, prices{house→price} | |
| `booking_manage_block` | op=list/save/delete, house, addon?, from, to, reason | 409 при пересечении с confirmed |
| `booking_issue_token` | op=issue/list/revoke, label | токен показывается один раз (только из CMP) |
| `booking_migrate_leads` | op=preview/run/retire | отчёт; только из CMP |

Каждое админское действие пишет запись в `booking_log` с actor и interface (ст. 3.5).
