<?php
$_lang['booking'] = 'Бронирование';
$_lang['booking.menu_desc'] = 'Календарь, заявки, Дома, цены, праздники';
$_lang['booking_admin'] = 'Управление бронированием: подтверждение, отмена, залог, цены';

$_lang['booking.status_new'] = 'Новая';
$_lang['booking.status_confirmed'] = 'Подтверждена';
$_lang['booking.status_cancelled'] = 'Отменена';
$_lang['booking.status_completed'] = 'Завершена';

$_lang['booking.deposit_not_set'] = 'Не назначен';
$_lang['booking.deposit_pending'] = 'Ожидается';
$_lang['booking.deposit_paid'] = 'Внесён';
$_lang['booking.deposit_returned'] = 'Возвращён';

$_lang['booking.err_overlap'] = 'Даты заняты: пересечение с бронью №[[+id]]';
$_lang['booking.err_deposit'] = 'Залог — от 5 000 до 20 000 ₽';

$_lang['setting_booking.admin_email'] = 'Почта администратора';
$_lang['setting_booking.admin_email_desc'] = 'Куда приходят новые заявки и запросы отмены. Несколько адресов — через запятую. Пусто — emailsender.';
$_lang['setting_booking.vk_discount'] = 'Скидка при бронировании через ВКонтакте, %';
$_lang['setting_booking.vk_discount_desc'] = 'На сайте не применяется: администратор отмечает её в брони по телефону для гостей из группы ВКонтакте.';
$_lang['setting_booking.vk_url'] = 'Группа ВКонтакте';
$_lang['setting_booking.vk_url_desc'] = 'Куда ведёт кнопка «Получить скидку» в форме.';
$_lang['setting_booking.request_link_page'] = 'Страница «Моя заявка»';
$_lang['setting_booking.request_link_page_desc'] = 'ID страницы со сниппетом [[!booking.request]].';
$_lang['setting_booking.form_page'] = 'Страница бронирования';
$_lang['setting_booking.form_page_desc'] = 'ID страницы /booking с формой [[!booking.form? &mode=`full`]].';
$_lang['setting_booking.timezone'] = 'Часовой пояс';
$_lang['setting_booking.timezone_desc'] = 'Для дат заезда и «сегодня».';
$_lang['setting_booking.rate_limit'] = 'Запросов гостя в минуту';
$_lang['setting_booking.rate_limit_desc'] = 'Ограничение запросов к API с одного IP.';
$_lang['setting_booking.bot_enabled'] = 'Бот MAX отвечает на вопросы';
$_lang['setting_booking.bot_enabled_desc'] = 'Да — бот отвечает гостям в личном чате MAX на общие вопросы по данным сайта: цены, заезд, баня, правила, адреса. Токен бота — maxnotify3.max_token. Подключение — INSTALL.md, раздел «MAX», и docs/max.md.';
$_lang['setting_booking.bot_secret'] = 'Секрет вебхука бота';
$_lang['setting_booking.bot_secret_desc'] = 'Создаётся сам при подключении вебхука. Не меняйте вручную.';
