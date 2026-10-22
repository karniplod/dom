<?php
/**
 * booking.hook — хук SendIt/FormIt пресета booking_request.
 * Проверяет и сохраняет заявку процессором booking_create_request. Ошибка — у поля формы, отправка останавливается.
 * Успех — номер и код гостя в сессию (booking.last) и значения для письма и MAX.
 * @var \MODX\Revolution\modX $modx
 * @var \FormIt\Hook $hook
 */
/** @var \Booking\Service\Booking $booking */
$booking = $modx->services->has('booking') ? $modx->services->get('booking') : null;
if (!$booking) {
    $hook->addError('dates', 'Бронирование временно недоступно — позвоните нам');
    return false;
}
$values = $hook->getValues();
$client = in_array($values['client'] ?? '', ['pwa', 'max'], true) ? $values['client'] : 'site';
$booking->setActor(['role' => 'guest', 'name' => 'гость', 'interface' => $client]);
$r = $booking->runAction('booking_create_request', $values);
if (!$r['success']) {
    $data = (array)$r['data'];
    $field = $data['field'] ?? 'dates';
    $hook->addError($field, $r['message']);
    return false;
}
$d = (array)$r['data'];
$_SESSION['booking.last'] = ['id' => (int)$d['id'], 'code' => $d['code'], 'ts' => time()];

$row = $modx->getObject(\Booking\Model\Booking::class, (int)$d['id']);
$ph = $booking->bookings()->placeholders($row);
$ph['guest_link'] = $d['link'];
$hook->setValues($ph);
return true;
