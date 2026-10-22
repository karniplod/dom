<?php
/** bnOrderForm — форма заявки: [[!booking.form? &mode=`compact`]] компонента бронирования, если он установлен; иначе прежняя форма (чанк bn.form.legacy). &p — префикс id полей, &house — код Дома */
$p = (string)($p ?? 'f');
$house = preg_replace('~[^a-z0-9_-]~', '', strtolower((string)($house ?? '')));
if ($modx->getCount(\MODX\Revolution\modSnippet::class, ['name' => 'booking.form']) && $modx->services->has('booking')) {
    $out = $modx->runSnippet('booking.form', ['mode' => 'compact', 'house' => $house]);
    if ($out !== '') return $out;
}
return $modx->getChunk('bn.form.legacy', ['p' => $p, 'house' => $house]);
