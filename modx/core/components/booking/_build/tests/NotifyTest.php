<?php
/**
 * Куда уходят письма о заявках: booking.admin_email → «Почта для заявок» из «Конфигурации» (bn_lead_email) → emailsender.
 */
return [
    'почта для заявок: своя бронирования, иначе из «Конфигурации»' => function (BookingTest $t) {
        $modx = $t->modx;
        $notify = $t->booking->notify();
        $was = (string)$modx->getOption('booking.admin_email', null, '');
        $lead = $t->booking->faq()->cc('bn_lead_email');
        try {
            $modx->setOption('booking.admin_email', '');
            $t->eq($lead !== '' ? $lead : (string)$modx->getOption('emailsender'), $notify->adminEmail(), 'пусто — «Почта для заявок» из «Конфигурации»');
            $modx->setOption('booking.admin_email', 'test-booking@example.com');
            $t->eq('test-booking@example.com', $notify->adminEmail(), 'задана своя почта бронирования — она');
        } finally {
            $modx->setOption('booking.admin_email', $was);
        }
    },
];
