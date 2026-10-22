<?php
/**
 * Доходные дома Ефремовых — общая логика сайта на MODX 3: дома, галереи, цены, отзывы, статьи, заявки.
 * Лежит в core/components/bania/Bania.php, подключается сниппетами bn* и страницей «Заявки» в админке.
 *
 * Разметка повторяет статичную сборку (build.py) — классы и порядок тегов те же, стили и скрипт общие.
 * Данные — из ресурсов и полей (TV): дом — шаблон bn-house, статья — bn-post, отзыв — bn-review.
 * Списки в текстовых полях: одна строка — один элемент, части через «|» («Вопрос | Ответ»).
 */

use MODX\Revolution\modX;
use MODX\Revolution\modResource;
use MODX\Revolution\modTemplate;
use MODX\Revolution\Mail\modMail;

class Bania
{
    /** @var modX */
    public static $modx;
    private static $houses = null;
    private static $tpl = [];

    const DAYS = ['Понедельник – четверг', 'Пятница', 'Суббота', 'Воскресенье'];
    const DAYS_SHORT = ['Пн – Чт*', 'Пятница*', 'Суббота', 'Воскресенье*'];   // * — праздничные дни, сноска под таблицей
    const STATUSES = ['new' => 'Новая', 'contacted' => 'Связались', 'confirmed' => 'Подтверждена', 'done' => 'Выехали', 'declined' => 'Отказ'];
    const ICONS = [
        'kitchen' => '<path d="M5 3v8a2 2 0 0 0 2 2v8M9 3v8a2 2 0 0 1-2 2M7 3v6"/><path d="M17 21V3c-2.2 1-3.5 3.6-3.5 7.5V13H17"/>',
        'steam' => '<path d="M3 20h18"/><path d="M5 16c1.5-1 3-1 4.5 0s3 1 4.5 0 3-1 4.5 0"/><path d="M8 11c-1-1.5 1-2.5 0-4s1-2.5 0-4M12 11c-1-1.5 1-2.5 0-4s1-2.5 0-4M16 11c-1-1.5 1-2.5 0-4s1-2.5 0-4"/>',
        'cue' => '<circle cx="8" cy="15" r="4"/><circle cx="16.5" cy="17.5" r="2.5"/><path d="M11 12 21 2"/>',
        'fire' => '<path d="M12 21c-4 0-7-2.6-7-6.5 0-3.6 3-5.5 4-9 2 1.5 3 3.5 3 5.5 1-1 1.5-2 1.5-3.5 2.5 2 4.5 4.6 4.5 7 0 3.9-2 6.5-6 6.5Z"/>',
        'bed' => '<path d="M3 19v-8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8M3 15h18M3 19v2M21 19v2"/><path d="M6 9V6a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3M12 9V6a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/>',
        'snow' => '<path d="M12 2v20M3.3 7l17.4 10M3.3 17 20.7 7"/><path d="m9 4 3 2 3-2M9 20l3-2 3 2"/>',
        'tv' => '<rect x="3" y="5" width="18" height="12" rx="1"/><path d="M8 21h8M12 17v4"/>',
        'car' => '<path d="M5 17h14M4 17v-4l2-5h12l2 5v4M4 17v2M20 17v2"/><circle cx="7.5" cy="14" r="1"/><circle cx="16.5" cy="14" r="1"/>',
        'chair' => '<path d="M7 3h6a2 2 0 0 1 2 2v7l4 3v3H9l-4-5V5a2 2 0 0 1 2-2Z"/><path d="M9 18v3M17 18v3"/>',
        'floor' => '<path d="M3 20h18M5 16h14"/><path d="M8 12c-1-1.5 1-2.5 0-4M12 12c-1-1.5 1-2.5 0-4M16 12c-1-1.5 1-2.5 0-4"/>',
        'phone' => '<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2Z"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4V5Z"/><path d="M8 10h8M8 13h5"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'cube' => '<path d="M12 3 4 7.5v9L12 21l8-4.5v-9L12 3Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
        'card' => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 10h18M7 15h4"/>',
    ];
    const MONTHS = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    public static function init(modX $modx) { self::$modx = $modx; }

    // ------------------------------------------------------------------ мелочи
    public static function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
    public static function money($n) { return number_format((int)$n, 0, '', ' ') . ' ₽'; }
    public static function icon($n) { return isset(self::ICONS[$n]) ? '<svg viewBox="0 0 24 24" aria-hidden="true">' . self::ICONS[$n] . '</svg>' : ''; }
    public static function opt($key, $default = '') { $v = self::$modx->getOption($key, null, $default); return $v === null ? $default : $v; }
    /** Время из поля MODX: число или строка «2026-10-20 10:00:00» */
    public static function ts($v) { return is_numeric($v) ? (int)$v : ((int)strtotime((string)$v) ?: 0); }
    /** Сырое значение поля (без обработки вывода: у флажков MODX склеивает id без разделителя) */
    public static function tvRaw(modResource $r, $name) {
        $tv = self::$modx->getObject(\MODX\Revolution\modTemplateVar::class, ['name' => $name]);
        return $tv ? (string)$tv->getValue($r->get('id')) : '';
    }
    public static function ruDate($date) {
        $t = self::ts($date);
        return $t ? date('j', $t) . ' ' . self::MONTHS[(int)date('n', $t) - 1] . ' ' . date('Y', $t) : '';
    }
    public static function plural($n, $a, $b, $c) {
        $m10 = $n % 10; $m100 = $n % 100;
        return $m10 === 1 && $m100 !== 11 ? $a : ($m10 >= 2 && $m10 <= 4 && ($m100 < 10 || $m100 >= 20) ? $b : $c);
    }
    /** Строки поля: «a | b | c» → [[a, b, c], …]; пустые строки и строки с # в начале пропускаются */
    public static function lines($text, $cols = 2) {
        $out = [];
        foreach (preg_split('~\r?\n~', (string)$text) as $ln) {
            $ln = trim($ln);
            if ($ln === '' || $ln[0] === '#') continue;
            $parts = array_map('trim', explode('|', $ln, $cols));
            $out[] = array_pad($parts, $cols, '');
        }
        return $out;
    }
    /** Путь к картинке: «pool.jpg» → /assets/img/<код дома>/pool.jpg, «assets/…» → /assets/…, ссылка — как есть */
    public static function src($v, $code = '') {
        $v = trim((string)$v);
        if ($v === '') return '';
        if (preg_match('~^(https?:)?//~', $v) || $v[0] === '/') return $v;
        if (strpos($v, '/') === false && $code !== '') return self::opt('base_url', '/') . 'assets/img/' . $code . '/' . $v;
        return self::opt('base_url', '/') . ltrim($v, '/');
    }
    public static function img($src, $alt, $cls = '', $lazy = true) {
        return '<img src="' . self::e($src) . '" alt="' . self::e($alt) . '"' . ($cls ? ' class="' . $cls . '"' : '') . ($lazy ? ' loading="lazy"' : '') . ' decoding="async">';
    }
    public static function chunk($name, array $ph) { return self::$modx->getChunk($name, $ph); }
    public static function url($id, $scheme = '') { return $id ? self::$modx->makeUrl((int)$id, '', '', $scheme ?: 'abs') : ''; }
    public static function templateId($name) {
        if (!isset(self::$tpl[$name])) { $t = self::$modx->getObject(modTemplate::class, ['templatename' => $name]); self::$tpl[$name] = $t ? (int)$t->get('id') : 0; }
        return self::$tpl[$name];
    }
    public static function full($path) { return rtrim(self::opt('site_url'), '/') . '/' . ltrim($path, '/'); }

    // ------------------------------------------------------------------ дома
    /** Все опубликованные дома (шаблон bn-house) по порядку в дереве. Ключ — код дома (en, ru, sv). */
    public static function houses() {
        if (self::$houses !== null) return self::$houses;
        self::$houses = [];
        $tid = self::templateId('bn-house');
        if (!$tid) return self::$houses;
        $c = self::$modx->newQuery(modResource::class, ['template' => $tid, 'published' => 1, 'deleted' => 0]);
        $c->sortby('menuindex', 'ASC');
        foreach (self::$modx->getIterator(modResource::class, $c) as $r) {
            $h = self::house($r);
            self::$houses[$h['code']] = $h;
        }
        return self::$houses;
    }
    /** Цены суток из компонента бронирования (одна база цен для сайта и брони, ст. 6.3); нет компонента — null */
    public static function bookingPrices($code) {
        if (!self::$modx->services->has('booking') || !class_exists('Booking\\Model\\House')) return null;
        $h = self::$modx->getObject(\Booking\Model\House::class, ['code' => $code]);
        if (!$h) return null;
        $rules = self::$modx->services->get('booking')->pricing()->rules($h->get('id'));
        $out = [];
        foreach (['weekday', 'friday', 'saturday', 'sunday'] as $t) {
            if (!isset($rules[$t])) return null;
            $out[] = (int)$rules[$t]->get('price');
        }
        return $out;
    }
    public static function houseById($id) {
        foreach (self::houses() as $h) if ((int)$h['id'] === (int)$id) return $h;
        $r = self::$modx->getObject(modResource::class, (int)$id);
        return $r && (int)$r->get('template') === self::templateId('bn-house') ? self::house($r) : null;
    }
    public static function house(modResource $r) {
        $tv = function ($n, $d = '') use ($r) { $v = $r->getTVValue($n); return ($v === null || $v === '') ? $d : $v; };
        $code = preg_replace('~[^a-z0-9_-]~', '', (string)$tv('bn_code', 'h' . $r->get('id')));
        $name = (string)$r->get('pagetitle');
        $prices = self::bookingPrices($code) ?: [(int)$tv('bn_price_wd'), (int)$tv('bn_price_fri'), (int)$tv('bn_price_sat'), (int)$tv('bn_price_sun')];
        $gallery = [];
        foreach (self::lines($tv('bn_gallery')) as [$f, $cap]) { $s = self::src($f, $code); if ($s) $gallery[] = ['src' => $s, 'cap' => $cap]; }
        $prepay = self::$modx->getObject(modResource::class, ['parent' => $r->get('id'), 'template' => self::templateId('bn-prepay'), 'published' => 1, 'deleted' => 0]);
        $tourText = (string)$tv('bn_tour_text');
        $street = (string)$tv('bn_street', $tv('bn_addr'));
        $pos = array_filter($prices);
        return [
            'id' => (int)$r->get('id'), 'url' => self::url($r->get('id')), 'code' => $code, 'name' => $name, 'name_lc' => $name,   // «Дом» с большой буквы везде (решение владельца 22.10.2026)
            'addr' => (string)$tv('bn_addr'), 'street' => $street, 'street_q' => rawurlencode('Пермь, ' . $street),
            'kicker' => (string)$tv('bn_kicker'), 'intro' => (string)$tv('bn_intro'),
            'area' => (string)$tv('bn_area'), 'floors' => (string)$tv('bn_floors', 'Дом, 2 этажа'), 'rooms' => (int)$tv('bn_rooms'), 'plot' => (string)$tv('bn_plot'),
            'sleep' => (int)$tv('bn_sleep'), 'max' => (int)$tv('bn_max', 20), 'base' => (int)$tv('bn_base', 10), 'extra' => (int)$tv('bn_extra', 0),
            'checkin' => (string)$tv('bn_checkin'), 'checkout' => (string)$tv('bn_checkout'), 'view' => (string)$tv('bn_view'), 'pool' => (string)$tv('bn_pool'),
            'prices' => $prices, 'min' => $pos ? min($pos) : 0, 'max_price' => $pos ? max($pos) : 0, 'min_price' => $pos ? self::money(min($pos)) : '',
            'tour' => (string)$tv('bn_tour'), 'tour_text' => $tourText, 'tour_points' => preg_match('~(\d+) точ~u', $tourText, $m) ? $m[1] . ' точек обзора' : '3D-тур',
            'sbp' => (string)$tv('bn_sbp'), 'hero' => self::src($tv('bn_hero'), $code), 'hero_alt' => (string)$tv('bn_hero_alt', 'фото'), 'gallery' => $gallery, 'photo' => $gallery[0]['src'] ?? '', 'photo2' => $gallery[1]['src'] ?? ($gallery[0]['src'] ?? ''),
            'features' => array_map(function ($l) { return $l[0]; }, self::lines($tv('bn_features'), 1)), 'table_extra' => (string)$tv('bn_table_extra'),
            'comfort' => self::lines($tv('bn_comfort'), 3), 'faq' => self::lines($tv('bn_faq')), 'seo' => (string)$tv('bn_seo'),
            'prepay_id' => $prepay ? (int)$prepay->get('id') : 0, 'prepay_url' => $prepay ? self::url($prepay->get('id')) : (string)$tv('bn_sbp'),
            'title' => (string)$r->get('longtitle'), 'description' => (string)$r->get('description'),
        ];
    }
    /** Плейсхолдеры дома для чанков (карточка, плитка 3D-тура, пункт меню…) */
    public static function housePh(array $h, $i = 0) {
        $ph = $h;
        foreach (['gallery', 'comfort', 'faq', 'features', 'prices'] as $k) unset($ph[$k]);
        $ph['idx'] = $i;
        $ph['media'] = self::cardMedia($h);
        $ph['features'] = implode('', array_map(function ($f) { return '<li>' . $f . '</li>'; }, $h['features']));
        $ph['discount'] = self::opt('bn_discount', '5');
        $ph['icon_cube'] = self::icon('cube'); $ph['icon_card'] = self::icon('card'); $ph['icon_pin'] = self::icon('pin');
        foreach (['wd', 'fri', 'sat', 'sun'] as $j => $d) $ph['price_' . $d] = self::money($h['prices'][$j]);
        return $ph;
    }
    public static function cardMedia(array $h) {
        $g = $h['gallery'];
        $hint = '<span class="hcard__zoom"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5M11 8.5v5M8.5 11h5"/></svg>Смотреть все ' . count($g) . ' фото</span>';
        $out = '';
        foreach ($g as $i => $p) {
            $cap = self::e($h['name'] . ': ' . $p['cap']);
            if ($i < 3) $out .= '<a href="' . self::e($p['src']) . '" data-lb="card-' . $h['code'] . '" data-cap="' . $cap . '" aria-label="' . self::e($h['name']) . ': открыть фото">'
                . self::img($p['src'], $h['name'] . ': ' . ($p['cap'] !== '' ? $p['cap'] : 'фото')) . ($i === 0 ? $hint : '') . '</a>';
            else $out .= '<a href="' . self::e($p['src']) . '" data-lb="card-' . $h['code'] . '" data-cap="' . $cap . '" hidden></a>';
        }
        return $out;
    }
    public static function listHouses($tpl, $exclude = '', $only = '') {
        $ex = array_filter(array_map('trim', preg_split('~\|\||,~', (string)$exclude)));
        $on = array_filter(array_map('trim', preg_split('~\|\||,~', (string)$only)));
        $out = ''; $i = 0;
        foreach (self::houses() as $h) {
            if ($ex && (in_array((string)$h['id'], $ex, true) || in_array($h['code'], $ex, true))) continue;
            if ($on && !in_array((string)$h['id'], $on, true) && !in_array($h['code'], $on, true)) continue;
            $out .= self::chunk($tpl, self::housePh($h, $i++));
        }
        return $out;
    }
    public static function minPrice() {
        $m = 0;
        foreach (self::houses() as $h) if ($h['min'] && (!$m || $h['min'] < $m)) $m = $h['min'];
        return $m ? self::money($m) : '';
    }

    // ------------------------------------------------------------------ страница дома
    public static function facts(array $h) {
        $f = [[$h['area'] . ' м²', $h['floors']], [$h['sleep'] . ' / ' . $h['max'], 'ночуют / отдыхают'], [$h['checkin'] . ' – ' . $h['checkout'], 'заезд и выезд']];
        return implode('', array_map(function ($x) { return '<div><b class="tnum">' . $x[0] . '</b><span>' . $x[1] . '</span></div>'; }, $f));
    }
    public static function gallery(array $h) {
        $n = count($h['gallery']);
        $sizes = [0 => 'big', 1 => 't', 4 => 'w', 7 => 'w'];
        $rem = ($n + 6) % 4;
        for ($j = 0; $j < (4 - $rem) % 4; $j++) if (!isset($sizes[$n - 1 - $j])) $sizes[$n - 1 - $j] = 'w';
        $out = '';
        foreach ($h['gallery'] as $i => $p) {
            $out .= '<a class="' . ($sizes[$i] ?? '') . '" href="' . self::e($p['src']) . '" data-lb="g-' . $h['code'] . '" data-cap="' . self::e($p['cap']) . '">'
                . self::img($p['src'], $h['name'] . ': ' . $p['cap']) . '<span>' . self::e($p['cap']) . '</span></a>';
        }
        return $out;
    }
    public static function comfort(array $h) {
        return implode('', array_map(function ($c) { return '<li>' . self::icon($c[0]) . '<div><b>' . $c[1] . '</b><span>' . $c[2] . '</span></div></li>'; }, $h['comfort']));
    }
    public static function priceDays(array $h) {
        // блок «Цена за сутки» — сниппет компонента бронирования, если он установлен (та же разметка .price-days)
        if (self::$modx->getCount(\MODX\Revolution\modSnippet::class, ['name' => 'booking.prices'])) {
            $out = self::$modx->runSnippet('booking.prices', ['house' => $h['code']]);
            if ($out !== '') return $out;
        }
        $hot = 0;
        foreach ($h['prices'] as $i => $v) if ($v > $h['prices'][$hot]) $hot = $i;
        $out = '';
        foreach ($h['prices'] as $i => $v) $out .= '<div' . ($i === $hot ? ' class="hot"' : '') . '><span>' . self::DAYS[$i] . '</span><b class="tnum">' . self::money($v) . '</b></div>';
        return $out;
    }
    public static function hinfo(array $h) {
        $phone = self::e(self::opt('bn_phone')); $raw = self::e(self::opt('bn_phone_raw'));
        $pool = $h['pool'] !== '' ? '<div><h3>Бассейн</h3><p>Работает круглый год: ' . $h['pool'] . '.</p></div>'
                                  : '<div><h3>Бассейн-джакузи</h3><p>Бассейн всесезонный, с функцией джакузи. Рядом баня.</p></div>';
        return '<div><h3>Посмотреть Дом</h3><p>Ежедневно ' . $h['view'] . ', между заездами гостей. Позвоните заранее: <a class="nb tnum" href="tel:' . $raw . '">' . $phone . '</a></p></div>'
            . "\n    " . $pool . "\n    " . '<div><h3>Повар</h3><p>' . self::opt('bn_services', '') . ' Скажите об этом при бронировании.</p></div>';
    }

    // ------------------------------------------------------------------ правила, вопросы
    public static function homeId() { return (int)self::opt('site_start', 1); }
    public static function homeTv($name) {
        $r = self::$modx->getObject(modResource::class, self::homeId());
        return $r ? (string)$r->getTVValue($name) : '';
    }
    /** Правила: строки поля «Правила домов» главной. Для дома — свои строки сверху, общая строка про гостей заменяется своей. */
    public static function rules($houseId = 0) {
        $rows = self::lines(self::homeTv('bn_rules'));
        if ($houseId && ($h = self::houseById($houseId))) {
            $rows = array_values(array_filter($rows, function ($r) { return mb_stripos($r[0], 'Гости сверх') !== 0; }));
            $rows = array_merge([['Заезд / выезд', 'с ' . $h['checkin'] . ' / до ' . $h['checkout']], ['Ночуют / отдыхают', $h['sleep'] . ' / до ' . $h['max'] . ' гостей'],
                                 ['Гости сверх ' . $h['base'], '+' . $h['extra'] . ' ₽ за человека']], $rows);
        }
        return '<ul class="rules">' . implode('', array_map(function ($r) { return '<li><span>' . $r[0] . '</span><span class="tnum">' . $r[1] . '</span></li>'; }, $rows)) . '</ul>';
    }
    public static function faqItems($id = 0) {
        $r = self::$modx->getObject(modResource::class, $id ?: self::$modx->resource->get('id'));
        return $r ? self::lines($r->getTVValue('bn_faq')) : [];
    }
    public static function faqHtml(array $items) {
        $out = '';
        foreach ($items as $i => [$q, $a]) $out .= '<details' . ($i === 0 ? ' open' : '') . '><summary>' . self::e($q) . '</summary><div class="faq__a"><p>' . self::e($a) . '</p></div></details>';
        return $out;
    }

    // ------------------------------------------------------------------ главная: таблица цен, лента фото
    public static function priceTable() {
        $H = self::houses();
        $th = ''; foreach ($H as $h) $th .= '<th scope="col" class="h-' . $h['code'] . '">' . $h['name'] . '</th>';
        $row = function ($label, $fn) use ($H) { $s = '<tr><th scope="row">' . $label . '</th>'; foreach ($H as $h) $s .= '<td>' . $fn($h) . '</td>'; return $s . '</tr>'; };
        $rows = $row('Дом и участок', function ($h) { return $h['area'] . ' м², ' . $h['rooms'] . ' комнат' . ($h['rooms'] < 5 ? 'ы' : '') . ', ' . $h['plot']; })
              . $row('Ночуют / отдыхают', function ($h) { return $h['sleep'] . ' / до ' . $h['max'] . ' гостей'; })
              . $row('Заезд / выезд', function ($h) { return 'с ' . $h['checkin'] . ' / до ' . $h['checkout']; })
              . $row('В цене гостей', function ($h) { return 'до ' . $h['base'] . ', далее +' . $h['extra'] . ' ₽ за человека'; })
              . $row('Для отдыха', function ($h) { return $h['table_extra']; });
        foreach (self::DAYS_SHORT as $i => $d) {
            $rows .= '<tr class="money' . ($i === 0 ? ' money-first' : '') . '"><th scope="row">' . $d . '</th>';
            foreach ($H as $h) $rows .= '<td class="tnum">' . self::money($h['prices'][$i]) . '</td>';
            $rows .= '</tr>';
        }
        $rows .= '<tr><th scope="row"><span class="sr">Ссылки</span></th>';
        foreach ($H as $h) $rows .= '<td><a href="' . $h['url'] . '">Подробнее о доме</a><br><a class="btn btn--sm" href="' . $h['prepay_url'] . '">Внести предоплату</a></td>';
        $rows .= '</tr>';
        return '<div class="table-wrap"><table class="cmp"><thead><tr><th scope="col"><span class="sr">Параметр</span></th>' . $th . '</tr></thead><tbody>' . $rows . '</tbody></table></div>';
    }
    public static function rail($items, $label, $cls = '') {
        return '<div class="rail ' . $cls . '" data-rail><div class="strip" aria-label="' . $label . '">' . $items . '</div>' . "\n"
            . '  <div class="rail__nav"><button class="rail__btn" type="button" data-step="-1" aria-label="Листать назад"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button><button class="rail__btn" type="button" data-step="1" aria-label="Листать вперёд"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button></div></div>';
    }
    public static function strip() {
        $items = '';
        foreach (self::lines(self::homeTv('bn_strip')) as [$f, $c]) {
            $s = self::src($f);
            $items .= '<a href="' . self::e($s) . '" data-lb="strip" data-cap="' . self::e($c) . '">' . self::img($s, $c) . '<span>' . self::e($c) . '</span></a>';
        }
        return self::rail($items, 'Фото домов');
    }

    // ------------------------------------------------------------------ отзывы
    const REVIEW_PLATFORMS = ['2ГИС' => ['#19AA1E', '2Г', '2gis'], 'Яндекс Карты' => ['#FC3F1D', 'Я', 'yandex'], 'Авито' => ['#00AAFF', 'A', 'avito']];

    /** По Дому: площадки (ссылки — настройки bn_rev_<код>_2gis|yandex|avito), QR «оставить отзыв», заглушки скриншотов */
    public static function reviewPanels($house = 0) {
        $out = '';
        foreach (self::houses() as $h) {
            if ($house && $h['id'] !== (int)$house) continue;
            $logos = '';
            foreach (self::REVIEW_PLATFORMS as $name => [$color, $sign, $key]) {
                $u = trim((string)self::opt('bn_rev_' . $h['code'] . '_' . $key));
                $inner = '<span class="plat__mark" style="background:' . $color . '">' . $sign . '</span><span class="plat__name">' . $name . '</span>';
                $logos .= $u !== '' ? '<a class="plat" href="' . self::e($u) . '" target="_blank" rel="noopener nofollow">' . $inner . '</a>' : '<span class="plat">' . $inner . '</span>';
            }
            $shots = '';
            foreach (['Яндекс Карт', '2ГИС', 'Авито'] as $p) $shots .= '<div class="rshot rshot--empty"><span>Здесь будет скриншот отзыва с ' . $p . '</span></div>';
            $qr = '<figure class="hrev__qr"><img src="' . self::$modx->getOption('base_url') . 'assets/img/qr/rev-' . $h['code'] . '.svg" alt="QR-код: оставить отзыв, ' . self::e($h['name']) . '" width="150" height="150" loading="lazy"><figcaption>Оставить отзыв: наведите камеру телефона</figcaption></figure>';
            $out .= '<div class="hrev h-' . $h['code'] . '"><div class="hrev__head"><h3>' . self::e($h['name']) . '</h3><div class="plats">' . $logos . '</div></div>'
                . '<div class="hrev__body"><div class="rshots">' . $shots . '</div>' . $qr . '</div></div>';
        }
        return $out;
    }

    public static function reviews($house = 0) {
        $tid = self::templateId('bn-review');
        $items = [];
        if ($tid) {
            $c = self::$modx->newQuery(modResource::class, ['template' => $tid, 'published' => 1, 'deleted' => 0]);
            $c->sortby('menuindex', 'ASC'); $c->sortby('publishedon', 'DESC');
            foreach (self::$modx->getIterator(modResource::class, $c) as $r) {
                $hid = (int)self::tvRaw($r, 'bn_rev_house');
                if ($house && $hid !== (int)$house) continue;
                $items[] = ['r' => $r, 'house' => $hid];
            }
        }
        $links = '';
        foreach (['Яндекс Карты' => 'bn_review_yandex', '2ГИС' => 'bn_review_2gis', 'ВКонтакте' => 'bn_vk_url'] as $n => $k) {
            $u = trim((string)self::opt($k));
            if ($u !== '') $links .= '<a class="btn btn--ghost btn--sm" href="' . self::e($u) . '" target="_blank" rel="noopener">' . $n . '</a>';
        }
        $cta = $links ? '<div class="rev-cta"><p>Отдыхали у нас? Расскажите, как всё прошло:</p>' . $links . '</div>' : '';
        $panels = self::reviewPanels($house);
        if (!$items) {
            return '<section class="band band--dark" id="reviews" aria-labelledby="rev-title"><div class="wrap">' . "\n"
                . '  <div class="sec-head"><h2 class="h-sec" id="rev-title">Отзывы гостей' . ($house ? ' об этом Доме' : '') . '</h2><p class="lead">Отзывы о каждом Доме — в 2ГИС, Яндекс Картах и на Авито. Показываем их как есть, скриншотами.</p></div>' . "\n"
                . '  <div class="hrevs">' . $panels . '</div>' . "\n"
                . '  ' . $cta . "\n" . '</div></section>';
        }
        $cards = ''; $sum = 0;
        foreach ($items as $it) {
            $r = $it['r']; $stars = max(1, min(5, (int)($r->getTVValue('bn_rev_rating') ?: 5))); $sum += $stars;
            $h = $it['house'] ? self::houseById($it['house']) : null;
            $date = (string)$r->getTVValue('bn_rev_date');
            $meta = implode(' · ', array_filter([$h ? $h['name'] : '', $date ? self::ruDate($date) : '']));
            $srcName = (string)$r->getTVValue('bn_rev_source'); $srcUrl = (string)$r->getTVValue('bn_rev_url');
            $text = '';
            foreach (preg_split('~\r?\n~', strip_tags((string)$r->get('content'))) as $p) if (trim($p) !== '') $text .= '<p>' . self::e(trim($p)) . '</p>';
            $cards .= self::chunk('bn.tpl.review', [
                'stars' => $stars, 'stars_on' => str_repeat('★', $stars), 'stars_off' => str_repeat('★', 5 - $stars), 'text' => $text, 'name' => self::e($r->get('pagetitle')),
                'meta' => $meta ? '<span>' . self::e($meta) . '</span>' : '',
                'source' => $srcName === '' ? '' : ($srcUrl !== '' ? '<a class="rev__src" href="' . self::e($srcUrl) . '" target="_blank" rel="noopener nofollow">' . self::e($srcName) . '</a>' : '<span class="rev__src">' . self::e($srcName) . '</span>'),
            ]);
        }
        $n = count($items);
        $avg = str_replace('.', ',', sprintf('%.1f', $sum / $n));
        $summary = '<p class="rev-sum"><b class="tnum">' . $avg . '</b> из 5 · ' . $n . ' ' . self::plural($n, 'отзыв', 'отзыва', 'отзывов') . '</p>';
        return '<section class="band band--dark" id="reviews" aria-labelledby="rev-title"><div class="wrap">' . "\n"
            . '  <div class="sec-row"><div class="sec-head"><h2 class="h-sec" id="rev-title">Отзывы гостей' . ($house ? ' об этом доме' : '') . '</h2><p class="lead">Отзывы публикуем слово в слово и пишем, где гость их оставил.</p></div>' . $summary . '</div>' . "\n"
            . '  ' . self::rail($cards, 'Отзывы гостей', 'rail--dark rail--rev') . "\n" . '  ' . $cta . "\n" . '</div></section>';
    }

    // ------------------------------------------------------------------ блог
    public static function cats() {
        $out = [];
        foreach (self::lines(self::opt('bn_blog_cats', "prazdnik | Праздники\nceny | Цены и бронь\nsauna | Баня и бассейн\nkorporativ | Корпоратив")) as [$k, $v]) $out[$k] = $v;
        return $out;
    }
    public static function readMin(modResource $r) {
        $words = preg_split('~\s+~u', trim(strip_tags($r->get('introtext') . ' ' . $r->get('content'))), -1, PREG_SPLIT_NO_EMPTY);
        return max(2, (int)ceil(count($words) / 180));
    }
    public static function posts($limit = 0, $exclude = 0, $bigFirst = false, $tpl = 'bn.tpl.postCard') {
        $tid = self::templateId('bn-post');
        if (!$tid) return '';
        $c = self::$modx->newQuery(modResource::class, ['template' => $tid, 'published' => 1, 'deleted' => 0]);
        if ($exclude) $c->where(['id:!=' => (int)$exclude]);
        $c->sortby('publishedon', 'DESC'); $c->sortby('menuindex', 'ASC');
        if ($limit) $c->limit((int)$limit);
        $cats = self::cats(); $out = ''; $i = 0;
        foreach (self::$modx->getIterator(modResource::class, $c) as $r) {
            $big = $bigFirst && $i === 0;
            $cat = (string)$r->getTVValue('bn_post_cat');
            $out .= self::chunk($tpl, [
                'url' => self::url($r->get('id')), 'title' => $r->get('pagetitle'), 'cat' => $cat, 'cat_name' => $cats[$cat] ?? $cat, 'min' => self::readMin($r),
                'cover' => self::src($r->getTVValue('bn_cover')), 'cover_alt' => self::e($r->getTVValue('bn_cover_alt')),
                'text' => $big ? $r->get('introtext') : $r->get('description'), 'big' => $big ? ' pcard--big' : '',
            ]);
            $i++;
        }
        return $out;
    }
    public static function blogChips() {
        $tid = self::templateId('bn-post'); $used = [];
        if ($tid) foreach (self::$modx->getIterator(modResource::class, ['template' => $tid, 'published' => 1, 'deleted' => 0]) as $r) $used[(string)$r->getTVValue('bn_post_cat')] = 1;
        $out = '<button type="button" data-c="all" aria-pressed="true">Все статьи</button>';
        foreach (self::cats() as $k => $v) if (isset($used[$k])) $out .= '<button type="button" data-c="' . self::e($k) . '" aria-pressed="false">' . self::e($v) . '</button>';
        return $out;
    }
    /** Текст статьи: разделы <section id><h2>…; у заголовков без id появляется id. Середина — призыв оставить заявку. */
    public static function postParts(modResource $r) {
        $html = (string)$r->get('content');
        $toc = [];
        $html = preg_replace_callback('~<h2([^>]*)>(.*?)</h2>~su', function ($m) use (&$toc) {
            $title = trim(strip_tags($m[2]));
            $id = preg_match('~id="([^"]+)"~', $m[1], $x) ? $x[1] : self::slug($title);
            $toc[] = [$id, $title];
            return strpos($m[1], 'id=') === false && strpos($m[0], '</section>') === false ? '<h2 id="' . $id . '"' . $m[1] . '>' . $m[2] . '</h2>' : $m[0];
        }, $html);
        // id раздела: у <section id> уже есть, заголовок внутри — без id (как в статичной версии)
        $html = preg_replace('~(<section id="([^"]+)">)<h2 id="\2">~u', '$1<h2>', $html);
        $cta = '<aside class="callout"><p><b>Хотите узнать, свободны ли даты?</b> Оставьте заявку — администратор перезвонит и назовёт точную сумму.</p><button class="btn btn--sm" type="button" data-order>Оставить заявку</button></aside>';
        $parts = preg_split('~(?=<section id=)~u', $html);
        if (count($parts) > 2) {
            $head = array_shift($parts);
            $mid = intdiv(count($parts), 2);
            $html = $head . implode('', array_slice($parts, 0, $mid)) . $cta . implode('', array_slice($parts, $mid));
        }
        $tocHtml = implode('', array_map(function ($t) { return '<li><a href="#' . $t[0] . '">' . self::e($t[1]) . '</a></li>'; }, $toc));
        return ['body' => $html, 'toc' => $tocHtml];
    }
    public static function slug($s) {
        $tr = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'j','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
        $s = strtr(mb_strtolower($s), $tr);
        return trim(preg_replace('~[^a-z0-9]+~', '-', $s), '-') ?: 'part';
    }

    // ------------------------------------------------------------------ хлебные крошки и разметка для поисковиков
    public static function crumbs(modResource $r) {
        $items = [['Главная', self::url(self::homeId())]];
        $chain = [];
        for ($p = $r; $p && (int)$p->get('id') !== self::homeId(); $p = $p->get('parent') ? self::$modx->getObject(modResource::class, $p->get('parent')) : null) array_unshift($chain, $p);
        foreach ($chain as $i => $p) $items[] = [$p->get('pagetitle'), $i === count($chain) - 1 ? '' : self::url($p->get('id'))];
        // у предоплаты последняя крошка — «Предоплата», а не заголовок страницы
        if ((int)$r->get('template') === self::templateId('bn-prepay')) $items[count($items) - 1][0] = 'Предоплата';
        $html = implode(' <span aria-hidden="true">/</span> ', array_map(function ($it) { return $it[1] !== '' ? '<a href="' . $it[1] . '">' . self::e($it[0]) . '</a>' : '<span aria-current="page">' . self::e($it[0]) . '</span>'; }, $items));
        $ld = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => []];
        foreach ($items as $i => $it) { $e = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $it[0]]; if ($it[1] !== '') $e['item'] = self::full($it[1]); $ld['itemListElement'][] = $e; }
        return ['html' => '<nav class="crumbs" aria-label="Навигация">' . $html . '</nav>', 'ld' => $ld];
    }
    public static function org() {
        return ['@type' => 'Organization', 'name' => self::opt('site_name', 'Доходные дома Ефремовых'), 'url' => self::full(''), 'logo' => self::full('assets/img/fox.svg')];
    }
    public static function ldScript(array $data) { return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    public static function ld(modResource $r) {
        $tpl = self::$modx->getObject(modTemplate::class, $r->get('template'));
        $name = $tpl ? $tpl->get('templatename') : '';
        $out = [];
        $phone = self::opt('bn_phone'); $mail = self::opt('bn_email');
        if ($name === 'bn-home') {
            $out[] = ['@context' => 'https://schema.org'] + self::org() + ['image' => self::full(ltrim((string)$r->getTVValue('bn_hero'), '/')), 'telephone' => $phone, 'email' => $mail,
                'sameAs' => array_values(array_filter([self::opt('bn_vk_url')])),
                'department' => array_values(array_map(function ($h) { return ['@type' => 'LodgingBusiness', 'name' => $h['name'], 'url' => self::full($h['url'])]; }, self::houses()))];
        }
        if ($name === 'bn-house' && ($h = self::houseById($r->get('id')))) {
            $out[] = ['@context' => 'https://schema.org', '@type' => 'LodgingBusiness', 'name' => $h['name'] . ' — ' . self::opt('site_name'), 'url' => self::full($h['url']), 'telephone' => $phone,
                'image' => array_values(array_map(function ($p) { return self::full($p['src']); }, array_slice($h['gallery'], 0, 3))),
                'address' => ['@type' => 'PostalAddress', 'streetAddress' => str_replace('ул. ', 'улица ', $h['addr']), 'addressLocality' => 'Пермь', 'addressRegion' => 'Пермский край', 'addressCountry' => 'RU'],
                'priceRange' => $h['min'] . '–' . $h['max_price'] . ' RUB', 'checkinTime' => $h['checkin'], 'checkoutTime' => $h['checkout'], 'numberOfRooms' => $h['rooms'],
                'petsAllowed' => true, 'smokingAllowed' => false,
                'amenityFeature' => array_map(function ($c) { return ['@type' => 'LocationFeatureSpecification', 'name' => $c[1], 'value' => true]; }, $h['comfort']),
                'makesOffer' => ['@type' => 'Offer', 'priceCurrency' => 'RUB', 'price' => $h['min'], 'description' => 'Аренда дома на сутки']];
        }
        if (in_array($name, ['bn-home', 'bn-house'], true) && ($faq = self::faqItems($r->get('id')))) {
            $out[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(function ($x) { return ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]]; }, $faq)];
        }
        if ($name === 'bn-post') {
            $cats = self::cats(); $cat = (string)$r->getTVValue('bn_post_cat');
            $out[] = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $r->get('pagetitle'), 'description' => $r->get('description'),
                'image' => self::full(ltrim((string)$r->getTVValue('bn_cover'), '/')), 'datePublished' => date('Y-m-d', self::ts($r->get('publishedon')) ?: time()),
                'dateModified' => date('Y-m-d', self::ts($r->get('editedon') ?: $r->get('publishedon')) ?: time()), 'inLanguage' => 'ru', 'articleSection' => $cats[$cat] ?? $cat,
                'author' => self::org(), 'publisher' => self::org(), 'mainEntityOfPage' => self::full(self::url($r->get('id')))];
        }
        if ($name === 'bn-blog') {
            $tid = self::templateId('bn-post'); $posts = [];
            if ($tid) foreach (self::$modx->getIterator(modResource::class, ['template' => $tid, 'published' => 1, 'deleted' => 0]) as $p)
                $posts[] = ['@type' => 'BlogPosting', 'headline' => $p->get('pagetitle'), 'url' => self::full(self::url($p->get('id'))), 'datePublished' => date('Y-m-d', self::ts($p->get('publishedon')) ?: time())];
            $out[] = ['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => 'Блог — ' . self::opt('site_name'), 'url' => self::full(self::url($r->get('id'))), 'inLanguage' => 'ru', 'publisher' => self::org(), 'blogPost' => $posts];
        }
        if ($name === 'bn-contacts') {
            $out[] = ['@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => $r->get('pagetitle'), 'url' => self::full(self::url($r->get('id'))),
                'mainEntity' => self::org() + ['telephone' => $phone, 'email' => $mail, 'contactPoint' => [['@type' => 'ContactPoint', 'telephone' => $phone, 'contactType' => 'reservations', 'areaServed' => 'RU', 'availableLanguage' => 'ru']]]];
        }
        if (in_array($name, ['bn-home', 'bn-contacts'], true) && $name !== 'bn-home') $out[] = ['@context' => 'https://schema.org'] + self::org() + ['telephone' => $phone, 'email' => $mail, 'sameAs' => array_values(array_filter([self::opt('bn_vk_url')]))];
        if ((int)$r->get('id') !== self::homeId()) $out[] = self::crumbs($r)['ld'];
        return implode('', array_map([self::class, 'ldScript'], $out));
    }
    /** Данные домов для расчёта стоимости в форме (window.HOUSES) */
    public static function housesJs() {
        $d = ['_discount' => 0];   // скидка только через ВКонтакте — форма сайта её не считает (решение владельца 22.10.2026)
        foreach (self::houses() as $h) {
            $d[$h['code']] = ['name' => $h['name'], 'prices' => array_combine(['wd', 'fri', 'sat', 'sun'], $h['prices']), 'max' => $h['max'], 'checkin' => $h['checkin'],
                              'checkout' => $h['checkout'], 'prepay' => $h['prepay_url'], 'base' => $h['base'], 'extra' => $h['extra']];
        }
        return '<script>window.HOUSES = ' . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
    }

    // ------------------------------------------------------------------ заявки и брони (таблицы bania_leads, bania_nights)
    public static function table($t) { return '`' . self::$modx->getOption('table_prefix') . 'bania_' . $t . '`'; }
    /** Таблицы создаются установщиком и при первом обращении; повторный вызов ничего не ломает */
    public static function ensureTables() {
        $db = self::$modx;
        $db->exec('CREATE TABLE IF NOT EXISTS ' . self::table('leads') . ' (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            created_at DATETIME NOT NULL, updated_at DATETIME NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'new\',
            house_id INT UNSIGNED NOT NULL DEFAULT 0, house_name VARCHAR(100) NOT NULL DEFAULT \'\',
            checkin DATE NULL, checkout DATE NULL, nights SMALLINT UNSIGNED NOT NULL DEFAULT 0, guests SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            estimate INT UNSIGNED NOT NULL DEFAULT 0, discount TINYINT UNSIGNED NOT NULL DEFAULT 0,
            occasion VARCHAR(30) NOT NULL DEFAULT \'\', contact VARCHAR(30) NOT NULL DEFAULT \'\',
            name VARCHAR(100) NOT NULL DEFAULT \'\', phone VARCHAR(30) NOT NULL DEFAULT \'\', comment TEXT NULL,
            sms_consent TINYINT(1) NOT NULL DEFAULT 0, source VARCHAR(255) NOT NULL DEFAULT \'\', ip VARCHAR(45) NOT NULL DEFAULT \'\',
            note TEXT NULL,
            KEY status (status), KEY house_dates (house_id, checkin), KEY created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        // предоплата не учитывается в заявках (ст. 2.1 конституции): колонку из ранних версий убираем
        $col = $db->query("SHOW COLUMNS FROM " . self::table('leads') . " LIKE 'prepaid'");
        if ($col && $col->fetch()) $db->exec('ALTER TABLE ' . self::table('leads') . ' DROP COLUMN prepaid');
        // ночи подтверждённых броней: одна ночь дома — одна строка; уникальный ключ не даёт продать ночь дважды
        $db->exec('CREATE TABLE IF NOT EXISTS ' . self::table('nights') . ' (
            house_id INT UNSIGNED NOT NULL, night DATE NOT NULL, lead_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (house_id, night), KEY lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
    /** Сумма по дням недели (как в форме): Пт, Сб, Вс — свои цены, остальные — будни; гости сверх нормы — разово */
    public static function estimate(array $h, $in, $out, $guests) {
        $a = strtotime($in . ' 12:00'); $b = strtotime($out . ' 12:00');
        if (!$a || !$b || $b <= $a) return [0, 0];
        $n = (int)round(($b - $a) / 86400); $sum = 0;
        for ($i = 0; $i < $n; $i++) { $w = (int)date('w', $a + $i * 86400); $sum += $h['prices'][$w === 5 ? 1 : ($w === 6 ? 2 : ($w === 0 ? 3 : 0))]; }
        $over = max(0, min((int)$guests, $h['max']) - $h['base']);
        return [$n, $sum + $over * $h['extra']];
    }
    /** Приём заявки с сайта. Возвращает ['ok'=>bool, 'id'=>?, 'message'=>?] */
    public static function acceptLead(array $in) {
        $modx = self::$modx;
        if (!empty($in['website'])) return ['ok' => true, 'id' => 0];   // ловушка для ботов: делаем вид, что всё хорошо
        $token = (string)($in['bn_token'] ?? '');
        if ($token === '' || !hash_equals((string)($_SESSION['bn_token'] ?? ''), $token)) return ['ok' => false, 'code' => 400, 'message' => 'Страница устарела. Обновите её и отправьте заявку ещё раз.'];
        // не чаще 5 заявок за 10 минут с одного адреса
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $key = 'bania/rate/' . md5($ip);
        $hits = (array)$modx->cacheManager->get($key);
        $hits = array_filter($hits, function ($t) { return $t > time() - 600; });
        if (count($hits) >= 5) return ['ok' => false, 'code' => 429, 'message' => 'Слишком много заявок подряд. Позвоните нам, пожалуйста.'];
        $name = trim(mb_substr(strip_tags((string)($in['name'] ?? '')), 0, 100));
        $phone = preg_replace('~\D~', '', (string)($in['phone'] ?? ''));
        $houses = self::houses(); $h = $houses[(string)($in['house'] ?? '')] ?? null;
        $err = [];
        if ($name === '') $err[] = 'укажите имя';
        if (strlen($phone) !== 11) $err[] = 'проверьте телефон';
        if (!$h) $err[] = 'выберите дом';
        if (empty($in['agree'])) $err[] = 'отметьте согласие на обработку данных';
        $d1 = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($in['checkin'] ?? '')) ? $in['checkin'] : null;
        $d2 = preg_match('~^\d{4}-\d{2}-\d{2}$~', (string)($in['checkout'] ?? '')) ? $in['checkout'] : null;
        if ($d1 && $d2 && $d2 <= $d1) $err[] = 'дата выезда должна быть позже заезда';
        if ($err) return ['ok' => false, 'code' => 422, 'message' => 'Чтобы отправить заявку, ' . implode(', ', $err) . '.'];
        $guests = max(0, min(99, (int)($in['guests'] ?? 0)));
        [$nights, $sum] = ($d1 && $d2) ? self::estimate($h, $d1, $d2, $guests) : [0, 0];
        $allowed = function ($v, $list) { return in_array($v, $list, true) ? $v : ''; };
        self::ensureTables();
        $st = $modx->prepare('INSERT INTO ' . self::table('leads') . ' (created_at, status, house_id, house_name, checkin, checkout, nights, guests, estimate, discount, occasion, contact, name, phone, comment, sms_consent, source, ip)
                              VALUES (NOW(), \'new\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $ok = $st && $st->execute([$h['id'], $h['name'], $d1, $d2, $nights, $guests, $sum, 0,
            $allowed((string)($in['occasion'] ?? ''), ['birthday', 'corporate', 'friends', 'family', 'other']), $allowed((string)($in['contact'] ?? ''), ['Позвонить', 'Telegram', 'MAX', 'WhatsApp']),
            $name, '+' . $phone, mb_substr(strip_tags((string)($in['comment'] ?? '')), 0, 2000), empty($in['sms']) ? 0 : 1, mb_substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255), $ip]);
        if (!$ok) { $modx->log(modX::LOG_LEVEL_ERROR, '[bania] заявка не сохранилась: ' . print_r($st ? $st->errorInfo() : $modx->errorInfo(), true)); return ['ok' => false, 'code' => 500, 'message' => 'Не получилось сохранить заявку. Позвоните нам, пожалуйста.']; }
        $id = (int)$modx->lastInsertId();
        $hits[] = time(); $modx->cacheManager->set($key, $hits, 600);
        self::notify($id);
        return ['ok' => true, 'id' => $id];
    }
    public static function lead($id) {
        $st = self::$modx->prepare('SELECT * FROM ' . self::table('leads') . ' WHERE id = ?');
        return $st && $st->execute([(int)$id]) ? ($st->fetch(\PDO::FETCH_ASSOC) ?: null) : null;
    }
    public static function leadText(array $l) {
        $occ = ['birthday' => 'День рождения', 'corporate' => 'Корпоратив', 'friends' => 'Отдых с друзьями', 'family' => 'Семья', 'other' => 'Другое'];
        $dates = $l['checkin'] ? date('d.m.Y', strtotime($l['checkin'])) . ' – ' . ($l['checkout'] ? date('d.m.Y', strtotime($l['checkout'])) : '?') . ' (' . $l['nights'] . ' сут.)' : 'не указаны';
        return "Заявка №{$l['id']}\nДом: {$l['house_name']}\nДаты: $dates\nГостей: " . ($l['guests'] ?: '—') . "\nПримерно: " . ($l['estimate'] ? self::money($l['estimate']) . ' (без скидки ' . $l['discount'] . '%)' : '—')
            . "\nПовод: " . ($occ[$l['occasion']] ?? '—') . "\nСвязь: {$l['contact']}\nИмя: {$l['name']}\nТелефон: {$l['phone']}\nКомментарий: " . ($l['comment'] ?: '—') . "\nSMS: " . ($l['sms_consent'] ? 'можно' : 'нет');
    }
    /** Письмо на почту из «Конфигурации» и сообщение в Telegram (если заданы токен бота и чат) */
    public static function notify($id) {
        $modx = self::$modx; $l = self::lead($id); if (!$l) return;
        $text = self::leadText($l) . "\n\nВсе заявки: " . self::full(MODX_MANAGER_URL . '?a=index&namespace=bania');
        $to = trim((string)self::opt('bn_lead_email', self::opt('bn_email')));
        if ($to !== '') {
            try {
                $mail = $modx->getService('mail', \MODX\Revolution\Mail\modPHPMailer::class);
                $host = preg_replace('~^www\.~', '', (string)parse_url(self::opt('site_url'), PHP_URL_HOST));
                $from = self::opt('bn_email_from') ?: (strpos($host, '.') !== false ? 'noreply@' . $host : self::opt('emailsender'));
                $mail->set(modMail::MAIL_BODY, nl2br(self::e($text)));
                $mail->set(modMail::MAIL_FROM, $from); $mail->set(modMail::MAIL_FROM_NAME, self::opt('site_name'));
                $mail->set(modMail::MAIL_SUBJECT, 'Заявка №' . $l['id'] . ': ' . $l['house_name'] . ', ' . $l['name']);
                foreach (array_map('trim', explode(',', $to)) as $addr) if ($addr !== '') $mail->address('to', $addr);
                $mail->setHTML(true);
                if (!$mail->send()) $modx->log(modX::LOG_LEVEL_ERROR, '[bania] письмо о заявке №' . $id . ' не отправилось: ' . $mail->mailer->ErrorInfo);
                $mail->reset();
            } catch (\Throwable $e) { $modx->log(modX::LOG_LEVEL_ERROR, '[bania] письмо: ' . $e->getMessage()); }
        }
        $tok = trim((string)self::opt('bn_tg_token')); $chat = trim((string)self::opt('bn_tg_chat'));
        if ($tok !== '' && $chat !== '' && function_exists('curl_init')) {
            $ch = curl_init('https://api.telegram.org/bot' . $tok . '/sendMessage');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['chat_id' => $chat, 'text' => $text], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
            if (curl_exec($ch) === false) $modx->log(modX::LOG_LEVEL_ERROR, '[bania] Telegram: ' . curl_error($ch));
            curl_close($ch);
        }
    }
    /**
     * Смена статуса заявки. «Подтверждена» занимает ночи дома (таблица bania_nights, уникальная пара дом+ночь):
     * если хоть одна ночь уже занята другой подтверждённой заявкой — статус не меняется и возвращается её номер.
     * Другой статус освобождает ночи. Основа для будущего онлайн-бронирования и календаря занятости.
     */
    public static function setStatus($id, $status, $note = null) {
        $modx = self::$modx;
        if (!isset(self::STATUSES[$status])) return 'неизвестный статус';
        $l = self::lead($id); if (!$l) return 'заявка не найдена';
        $modx->beginTransaction();
        try {
            $modx->prepare('DELETE FROM ' . self::table('nights') . ' WHERE lead_id = ?')->execute([(int)$id]);
            if (in_array($status, ['confirmed', 'done'], true) && $l['checkin'] && $l['checkout'] && $l['house_id']) {
                $ins = $modx->prepare('INSERT INTO ' . self::table('nights') . ' (house_id, night, lead_id) VALUES (?, ?, ?)');
                for ($t = strtotime($l['checkin']); $t < strtotime($l['checkout']); $t += 86400) {
                    try { $okIns = $ins->execute([(int)$l['house_id'], date('Y-m-d', $t), (int)$id]); } catch (\PDOException $e) { $okIns = false; }
                    if (!$okIns) {
                        $q = $modx->prepare('SELECT lead_id FROM ' . self::table('nights') . ' WHERE house_id = ? AND night = ?');
                        $q->execute([(int)$l['house_id'], date('Y-m-d', $t)]);
                        $other = (int)$q->fetchColumn();
                        $modx->rollBack();
                        return 'ночь ' . date('d.m.Y', $t) . ' уже занята подтверждённой заявкой №' . $other;
                    }
                }
            }
            $sql = 'UPDATE ' . self::table('leads') . ' SET status = ?, updated_at = NOW()' . ($note !== null ? ', note = ?' : '') . ' WHERE id = ?';
            $args = [$status]; if ($note !== null) $args[] = mb_substr($note, 0, 2000); $args[] = (int)$id;
            $modx->prepare($sql)->execute($args);
            $modx->commit();
        } catch (\Throwable $e) { $modx->rollBack(); return $e->getMessage(); }
        return '';
    }
    /** Занятые ночи дома — для будущего календаря на сайте */
    public static function busyNights($houseId, $from, $to) {
        $st = self::$modx->prepare('SELECT night FROM ' . self::table('nights') . ' WHERE house_id = ? AND night >= ? AND night < ? ORDER BY night');
        return $st && $st->execute([(int)$houseId, $from, $to]) ? $st->fetchAll(\PDO::FETCH_COLUMN) : [];
    }
}
