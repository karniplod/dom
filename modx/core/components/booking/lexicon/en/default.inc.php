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
$_lang['setting_booking.admin_email_desc'] = 'Where new requests and cancellation requests go. Comma-separated. Empty — emailsender.';
$_lang['setting_booking.vk_discount'] = 'VK booking discount, %';
$_lang['setting_booking.vk_discount_desc'] = 'Not applied on the website: the admin ticks it in phone bookings for guests from the VK group.';
$_lang['setting_booking.vk_url'] = 'VK group';
$_lang['setting_booking.vk_url_desc'] = 'Where the "Get discount" button in the form leads.';
$_lang['setting_booking.request_link_page'] = '"My request" page';
$_lang['setting_booking.request_link_page_desc'] = 'Resource ID with [[!booking.request]].';
$_lang['setting_booking.form_page'] = 'Booking page';
$_lang['setting_booking.form_page_desc'] = 'Resource ID of /booking with [[!booking.form? &mode=`full`]].';
$_lang['setting_booking.timezone'] = 'Time zone';
$_lang['setting_booking.timezone_desc'] = 'For check-in dates and "today".';
$_lang['setting_booking.rate_limit'] = 'Guest requests per minute';
$_lang['setting_booking.rate_limit_desc'] = 'API rate limit per IP.';
