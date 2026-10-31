<?php
$_lang['booking'] = 'Booking';
$_lang['booking.menu_desc'] = 'Calendar, requests, houses, prices, holidays';
$_lang['booking_admin'] = 'Manage bookings: confirm, cancel, deposit, prices';

$_lang['booking.status_new'] = 'New';
$_lang['booking.status_confirmed'] = 'Confirmed';
$_lang['booking.status_cancelled'] = 'Cancelled';
$_lang['booking.status_completed'] = 'Completed';

$_lang['booking.deposit_not_set'] = 'Not set';
$_lang['booking.deposit_pending'] = 'Pending';
$_lang['booking.deposit_paid'] = 'Paid';
$_lang['booking.deposit_returned'] = 'Returned';

$_lang['booking.err_overlap'] = 'Dates are taken: overlaps booking #[[+id]]';
$_lang['booking.err_deposit'] = 'Deposit must be 5,000–20,000 RUB';

$_lang['setting_booking.admin_email'] = 'Admin email';
$_lang['setting_booking.admin_email_desc'] = 'Where new requests and cancellation requests go. Comma-separated. Empty — ClientConfig bn_lead_email, then emailsender.';
$_lang['setting_booking.vk_url'] = 'VK group';
$_lang['setting_booking.vk_url_desc'] = 'The "We are on VK" link under the form.';
$_lang['setting_booking.request_link_page'] = '"My request" page';
$_lang['setting_booking.request_link_page_desc'] = 'Resource ID with [[!booking.request]].';
$_lang['setting_booking.form_page'] = 'Booking page';
$_lang['setting_booking.form_page_desc'] = 'Resource ID of /booking with [[!booking.form? &mode=`full`]].';
$_lang['setting_booking.timezone'] = 'Time zone';
$_lang['setting_booking.timezone_desc'] = 'For check-in dates and "today".';
$_lang['setting_booking.rate_limit'] = 'Guest requests per minute';
$_lang['setting_booking.rate_limit_desc'] = 'API rate limit per IP.';
$_lang['setting_booking.requests_per_phone'] = 'Requests per phone per day';
$_lang['setting_booking.requests_per_phone_desc'] = 'New (unconfirmed) requests allowed from one phone within 24 hours; counted per phone and IP together; an identical repeat is refused. 0 — no check.';
$_lang['setting_booking.requests_per_ip'] = 'Requests per IP per hour';
$_lang['setting_booking.requests_per_ip_desc'] = 'Flood protection per IP. 0 — no check.';
$_lang['setting_booking.ip_header'] = 'Client IP header';
$_lang['setting_booking.ip_header_desc'] = 'Only behind the hosting proxy: header set by the proxy, e.g. HTTP_X_REAL_IP. Empty — REMOTE_ADDR.';
$_lang['setting_booking.prepay_window'] = 'Prepayment window after request';
$_lang['setting_booking.prepay_window_desc'] = 'Yes — after a house request a window asks to call the administrator first, then offers the prepayment page. Only for houses with a prepayment page.';
$_lang['setting_booking.prepay_template'] = 'Prepayment page template';
$_lang['setting_booking.prepay_template_desc'] = 'Template name of the house prepayment page (child of the house page).';
$_lang['setting_booking.bot_enabled'] = 'MAX bot answers questions';
$_lang['setting_booking.bot_enabled_desc'] = 'Yes — the MAX bot answers general questions of guests from site data. Bot token: maxnotify3.max_token.';
$_lang['setting_booking.bot_secret'] = 'Bot webhook secret';
$_lang['setting_booking.bot_secret_desc'] = 'Created automatically on webhook subscription.';
