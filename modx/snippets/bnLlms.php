<?php
/** bnLlms — llms.txt: краткое описание сайта для нейросетевых поисковиков */
require_once MODX_CORE_PATH . 'components/bania/Bania.php';
Bania::init($modx);
$o = ['# ' . $modx->getOption('site_name'), '', '> Аренда трёх домов с баней, бассейном и бильярдом в Перми посуточно. Бронирование: ' . $modx->getOption('bn_phone') . '.', ''];
foreach (Bania::houses() as $h) {
    $p = $h['prices'];
    $o[] = '- [' . $h['name'] . '](' . Bania::full($h['url']) . '): ' . $h['addr'] . ', ' . $h['area'] . ' м², ночуют ' . $h['sleep'] . ', отдыхают до ' . $h['max'] . '; заезд ' . $h['checkin'] . ', выезд ' . $h['checkout'] . '; сутки: Пн–Чт ' . $p[0] . ' ₽, Пт ' . $p[1] . ' ₽, Сб ' . $p[2] . ' ₽, Вс ' . $p[3] . ' ₽.';
}
$o[] = '';
$o[] = 'Правила: залог ' . $modx->getOption('bn_deposit') . ', питомцы +1 000 ₽, курить в доме нельзя, тишина на участке с 22:00 до 9:00. Предоплата ' . $modx->getOption('bn_prepay_percent') . '% после подтверждения дат администратором.';
return implode("\n", $o) . "\n";
