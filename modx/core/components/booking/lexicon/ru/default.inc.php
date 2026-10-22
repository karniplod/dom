<?php
$_lang['booking'] = 'Бронирование';
$_lang['booking.menu_desc'] = 'Календарь, заявки, дома, цены, праздники';
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
$_lang['setting_booking.site_discount'] = 'Скидка при брони на сайте, %';
$_lang['setting_booking.site_discount_desc'] = 'Применяется ко всей сумме заявки с сайта, из приложения и MAX.';
$_lang['setting_booking.request_link_page'] = 'Страница «Моя заявка»';
$_lang['setting_booking.request_link_page_desc'] = 'ID страницы со сниппетом [[!booking.request]].';
$_lang['setting_booking.form_page'] = 'Страница бронирования';
$_lang['setting_booking.form_page_desc'] = 'ID страницы /booking с формой [[!booking.form? &mode=`full`]].';
$_lang['setting_booking.timezone'] = 'Часовой пояс';
$_lang['setting_booking.timezone_desc'] = 'Для дат заезда и «сегодня».';
$_lang['setting_booking.rate_limit'] = 'Запросов гостя в минуту';
$_lang['setting_booking.rate_limit_desc'] = 'Ограничение запросов к API с одного IP.';
