<?php
namespace Booking\Service;

use Booking\Model\Addon;
use Booking\Model\Holiday;
use Booking\Model\House;
use MODX\Revolution\modResource;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarResource;

/**
 * Ответы бота MAX на общие вопросы гостей — только по данным сайта (решение владельца 24.10.2026):
 * цены, часы заезда и выезда, баня, адреса — из таблиц бронирования (то же, что видит форма);
 * частые вопросы, правила, удобства — из полей страниц (bn_faq, bn_rules, bn_comfort); контакты — из «Конфигурации».
 * Ничего не выдумывает: нет ответа — предлагает позвонить. Свободные даты не обещает — ведёт в бронирование.
 * answer() возвращает ['text' => …, 'buttons' => [[['type' => 'callback'|'link', 'text' => …, 'payload'|'url' => …]]]].
 */
class Faq
{
    public const SHORT_DAYS = ['weekday' => 'Пн–Чт', 'friday' => 'Пт', 'saturday' => 'Сб', 'sunday' => 'Вс'];

    /** Темы меню: payload кнопки => подпись */
    public const TOPICS = [
        'prices' => 'Цены', 'times' => 'Заезд и выезд', 'houses' => 'Дома', 'bath' => 'Баня',
        'rules' => 'Правила', 'address' => 'Как добраться', 'contacts' => 'Контакты',
    ];

    /** Слова-признаки тем (начала слов, без ё) */
    protected const INTENTS = [
        'start' => ['/start', 'start', 'начат', 'меню', 'привет', 'здравств', 'добрый', 'добрый день', 'помощ', 'помоги'],
        'prices' => ['цен', 'стои', 'стоимост', 'прайс', 'почем', 'тариф', 'руб', '₽', 'расценк', 'сколько будет'],
        'times' => ['заезд', 'выезд', 'засел', 'выселен', 'во сколько', 'со скольки', 'до скольки', 'чек-ин', 'check'],
        'address' => ['адрес', 'где наход', 'где вы', 'добрат', 'доехат', 'проезд', 'карт', 'навигатор', 'располож', 'как найти'],
        'contacts' => ['телефон', 'позвон', 'контакт', 'администратор', 'связат', 'связь', 'номер телефон', 'менеджер'],
        'capacity' => ['вмещ', 'сколько человек', 'сколько гостей', 'сколько мест', 'спальн', 'компани', 'день рожд', 'отмет', 'праздн', 'банкет', 'корпоратив', 'юбилей', 'свадьб'],
        'book' => ['брон', 'свобод', 'занят', 'забронир', 'резерв', 'даты', 'дата', 'заявк', 'снять дом'],
        'houses' => ['какие дома', 'какие у вас', 'сколько домов', 'дома', 'фото', 'фотограф'],
        'bath' => ['бан', 'саун', 'парилк', 'парн', 'по час', 'почасов'],
        'discount' => ['скидк', 'акци', 'дешевле', 'промокод'],
        'cook' => ['повар', 'шашлык', 'плов', 'приготов', 'еда', 'питан', 'казан'],
        'rules' => ['правил', 'залог', 'предоплат', 'шум', 'музык', 'курит', 'курен', 'тишин'],
    ];

    /** Слова, которые не помогают найти ответ в частых вопросах */
    protected const STOP = ['как', 'что', 'где', 'есть', 'ли', 'у', 'в', 'во', 'на', 'и', 'а', 'с', 'со', 'по', 'за', 'для', 'от', 'до',
        'можно', 'нужно', 'надо', 'вас', 'ваш', 'ваши', 'ваше', 'это', 'мы', 'я', 'нам', 'мне', 'мой', 'наш', 'же', 'ну', 'не', 'или',
        'то', 'бы', 'ли', 'там', 'тут', 'здесь', 'ещё', 'еще', 'скажите', 'подскажите', 'пожалуйста', 'вопрос', 'хотим', 'хочу', 'будет', 'дом', 'дома', 'доме', 'домах', 'дому', 'домом', 'домов', 'сколько', 'какой', 'какая', 'какие', 'есть'];

    protected Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    // ------------------------------------------------------------------ ответ

    /** Ответ на текст гостя или на нажатую кнопку (payload «prices», «house:en» …) */
    public function answer(string $text, string $payload = ''): array
    {
        $payload = trim($payload);
        if ($payload !== '') {
            if (strpos($payload, 'house:') === 0) {
                $h = $this->house(substr($payload, 6));
                return $h ? $this->reply($this->houseCard($h)) : $this->greeting();
            }
            return $this->topic($payload, null) ?? $this->greeting();
        }
        $q = $this->norm($text);
        if ($q === '') {
            return $this->greeting();
        }
        $house = $this->detectHouse($q);
        $intents = $this->intents($q);

        // баня + цена — цена бани по часам, а не суток Дома
        if (in_array('bath', $intents, true) && in_array('prices', $intents, true)) {
            return $this->topic('bath', $house);
        }
        // цифры — только из базы бронирования: в текстах вопросов цены могли устареть
        foreach (['start', 'prices', 'times', 'address', 'contacts', 'book', 'discount', 'capacity'] as $i) {
            if (in_array($i, $intents, true) && !($i === 'book' && in_array('bath', $intents, true))) {
                if ($i === 'start' && count($intents) > 1) continue;
                return $this->topic($i, $house);
            }
        }
        if (in_array('bath', $intents, true) && !$this->faqStrong($q, $house)) {
            return $this->topic('bath', $house);
        }
        // частый вопрос с сайта или удобство Дома — что совпало лучше
        $faq = $this->faqScored($q, $house);
        $comfort = $this->comfortScored($q, $house);
        $fs = $faq && $faq[0][0] >= 2 ? $faq[0][0] : 0;
        $cs = $comfort ? $comfort['score'] : 0;
        if ($cs > 0 && $cs + 0.5 >= $fs) {
            return $this->reply($comfort['text']);
        }
        if ($fs > 0) {
            return $this->faqAnswer($q, $house);
        }
        if ($r = $this->rulesMatch($q)) {
            return $this->reply($r);
        }
        foreach (['bath', 'cook', 'rules', 'houses'] as $i) {
            if (in_array($i, $intents, true)) {
                return $this->topic($i, $house);
            }
        }
        if ($house) {
            return $this->reply($this->houseCard($house));
        }
        $phone = $this->cc('bn_phone');
        return $this->reply("Не нашёл точного ответа на этот вопрос."
            . ($phone !== '' ? "\nПозвоните администратору: {$phone} — подскажет." : '')
            . "\n\nИли выберите тему ниже.");
    }

    /** Пост для канала Домов: о Домах по данным сайта и кнопки «Задать вопрос боту» и «Забронировать» */
    public function channelPost(string $botUrl): array
    {
        $lines = [];
        $min = 0;
        foreach ($this->houses() as $h) {
            $lines[] = $h->get('name') . ' — ' . $h->get('address') . ', ночуют до ' . (int)$h->get('capacity_sleep') . ' гостей';
            foreach ($this->booking->pricing()->rules((int)$h->get('id')) as $r) {
                $min = $min === 0 ? (int)$r->get('price') : min($min, (int)$r->get('price'));
            }
        }
        $text = "Доходные Дома Ефремовых — Дома с баней и бассейном в Перми, на сутки для отдыха и праздников.\n\n"
            . implode("\n", $lines)
            . ($min > 0 ? "\n\nОт " . $this->booking->money($min) . ' за сутки, баня и бассейн входят в цену.' : '')
            . "\n\nЕсть вопрос — напишите нашему боту: сразу ответит про цены, заезд и выезд, баню и правила."
            . "\nЗабронировать — кнопкой ниже: выберите Дом и даты, администратор перезвонит и подтвердит.";
        if (($p = $this->cc('bn_phone')) !== '') {
            $text .= "\nТелефон: " . $p;
        }
        $rows = [[['type' => 'link', 'text' => 'Задать вопрос боту', 'url' => $botUrl]]];
        if ($url = $this->appUrl()) {
            $rows[] = [['type' => 'link', 'text' => 'Забронировать', 'url' => $url]];
        }
        return ['text' => $text, 'buttons' => $rows];
    }

    public function greeting(): array
    {
        return $this->reply("Здравствуйте! Я бот Доходных Домов Ефремовых в Перми.\n"
            . "Отвечу на вопросы о Домах, ценах, бане, заезде и правилах. Напишите вопрос своими словами или выберите тему.\n\n"
            . "Свободные даты и расчёт стоимости — в бронировании (кнопка ниже).");
    }

    protected function topic(string $name, ?House $house): ?array
    {
        $houses = $house ? [$house] : $this->houses();
        switch ($name) {
            case 'start':
                return $this->greeting();
            case 'prices':
                return $this->reply($this->pricesText($houses));
            case 'times':
                $out = [];
                foreach ($houses as $h) {
                    $out[] = $h->get('name') . ': заезд с ' . $this->hm($h->get('checkin_time')) . ', выезд до ' . $this->hm($h->get('checkout_time')) . ' следующего дня.';
                }
                return $this->reply(implode("\n", $out) . "\n\nРаньше заехать или позже уехать — спросите администратора: зависит от соседних броней.");
            case 'address':
                $out = [];
                $maps = [];
                foreach ($houses as $h) {
                    if ($h->get('address') === '') continue;
                    $out[] = $h->get('name') . ' — ' . $h->get('address');
                    $maps[] = [['type' => 'link', 'text' => 'Карта: ' . $h->get('name'), 'url' => $this->mapUrl($h->get('address'))]];
                }
                return $this->reply(($out ? implode("\n", $out) : 'Адрес назовёт администратор.') . "\n\nВсе Дома — в Перми. Маршрут — кнопкой «Карта».", $maps);
            case 'capacity':
                $out = [];
                foreach ($houses as $h) {
                    $out[] = $this->houseLine($h);
                }
                return $this->reply(implode("\n\n", $out) . "\n\nСколько гостей входит в цену и доплата за следующих — в разделе «Цены». "
                    . 'Праздник на большую компанию лучше обсудить с администратором по телефону.', $house ? [] : $this->houseButtons());
            case 'contacts':
                $lines = [];
                if (($p = $this->cc('bn_phone')) !== '') $lines[] = 'Телефон: ' . $p;
                if (($v = $this->cc('bn_vk_url')) !== '') $lines[] = 'ВКонтакте: ' . $v;
                if (($e = $this->cc('bn_email')) !== '') $lines[] = 'Почта: ' . $e;
                return $this->reply(($lines ? implode("\n", $lines) : 'Контакты — на сайте.') . "\n\nБронь подтверждает администратор по телефону.");
            case 'book':
                return $this->reply("Свободные даты и точная стоимость — в бронировании: выберите Дом и даты, расчёт появится сразу.\n"
                    . "После заявки администратор перезвонит и подтвердит бронь.");
            case 'houses':
                $out = [];
                foreach ($this->houses() as $h) {
                    $out[] = $this->houseLine($h);
                }
                return $this->reply(implode("\n\n", $out) . "\n\nНажмите на Дом — расскажу подробнее.", $this->houseButtons());
            case 'bath':
                return $this->reply($this->bathText($houses));
            case 'discount':
                $d = (int)$this->cc('bn_discount', (string)$this->booking->modx->getOption('booking.vk_discount', null, 0));
                $vk = $this->cc('bn_vk_url', (string)$this->booking->modx->getOption('booking.vk_url', null, ''));
                return $this->reply($d > 0
                    ? "Скидка {$d}% — при бронировании через нашу группу ВКонтакте" . ($vk !== '' ? ": {$vk}" : '.') . "\nНапишите там администратору или назовите группу при звонке."
                    : 'Сейчас скидок нет. Актуальные цены — в разделе «Цены».');
            case 'cook':
                $s = $this->cc('bn_services');
                return $this->reply($s !== '' ? $s : 'Про повара и угощения спросите администратора.');
            case 'rules':
                $home = $this->homeResource();
                $rules = $home ? $this->pairs((string)$home->getTVValue('bn_rules')) : [];
                if (!$rules) return $this->reply('Правила проживания назовёт администратор при звонке.');
                $out = [];
                foreach ($rules as [$k, $v]) $out[] = '• ' . $k . ($v !== '' ? ': ' . $v : '');
                return $this->reply("Правила Домов:\n" . implode("\n", $out));
        }
        return null;
    }

    // ------------------------------------------------------------------ тексты из базы

    protected function pricesText(array $houses): string
    {
        $pricing = $this->booking->pricing();
        $out = [];
        foreach ($houses as $h) {
            $rules = $pricing->rules((int)$h->get('id'));
            if (!$rules) continue;
            $days = [];
            foreach (Pricing::DAY_TYPES as $t) {
                if (isset($rules[$t])) $days[] = self::SHORT_DAYS[$t] . ' ' . $this->booking->money((int)$rules[$t]->get('price'));
            }
            $base = $rules['weekday'] ?? reset($rules);
            $s = $h->get('name') . ', сутки: ' . implode(' · ', $days);
            if ((int)$base->get('extra_guest_limit') > 0 && (int)$base->get('extra_guest_price') > 0) {
                $s .= "\nВ цене до " . (int)$base->get('extra_guest_limit') . ' гостей, каждый следующий +' . $this->booking->money((int)$base->get('extra_guest_price')) . '.';
            }
            if ((int)$base->get('pet_price') > 0) {
                $s .= ' Питомец +' . $this->booking->money((int)$base->get('pet_price')) . '.';
            }
            $out[] = $s;
        }
        if (!$out) {
            return 'Цены назовёт администратор.';
        }
        $text = implode("\n\n", $out) . "\n\nБаня и бассейн входят в цену суток.";
        $hol = $this->upcomingHolidays();
        $text .= "\nВ праздники и особые даты цена своя" . ($hol ? ' (ближайшие: ' . implode(', ', $hol) . ')' : '') . '.';
        return $text . "\nТочную сумму на ваши даты покажет бронирование.";
    }

    protected function bathText(array $houses): string
    {
        $pricing = $this->booking->pricing();
        $sep = [];
        foreach ($this->houses() as $h) {
            if (!(int)$h->get('addon_bookable_separately')) continue;
            $addon = $this->booking->modx->getObject(Addon::class, ['house_id' => $h->get('id'), 'active' => 1]);
            if (!$addon || (int)$addon->get('price_per_hour') <= 0) continue;
            $s = $h->get('name') . ': ' . $this->booking->money((int)$addon->get('price_per_hour')) . ' в час';
            $byDay = [];
            foreach ($pricing->rules((int)$h->get('id')) as $t => $r) {
                if ((int)$r->get('addon_price') > 0 && (int)$r->get('addon_price') !== (int)$addon->get('price_per_hour')) {
                    $byDay[] = self::SHORT_DAYS[$t] . ' ' . $this->booking->money((int)$r->get('addon_price'));
                }
            }
            if ($byDay) $s .= ' (' . implode(', ', $byDay) . ')';
            $s .= ', минимум ' . Pricing::hours((float)$addon->get('min_hours')) . '.';
            $sep[] = $s;
        }
        $text = 'Когда вы снимаете Дом, баня и бассейн уже входят в цену суток.';
        if ($sep) {
            $text .= "\n\nБаню отдельно, без Дома, можно снять по часам:\n" . implode("\n", $sep)
                . "\nСвободные часы — в бронировании: выберите Дом и «Только баня». Между гостями баню убирают.";
        }
        return $text;
    }

    protected function houseLine(House $h): string
    {
        $s = $h->get('name');
        if ($h->get('address') !== '') $s .= ' — ' . $h->get('address');
        $cap = [];
        if ((int)$h->get('capacity_sleep') > 0) $cap[] = 'спальных мест: ' . (int)$h->get('capacity_sleep');
        if ((int)$h->get('capacity_party') > 0) $cap[] = 'на празднике до ' . (int)$h->get('capacity_party') . ' гостей';
        return $s . ($cap ? "\n" . implode(', ', $cap) : '');
    }

    protected function houseCard(House $h): string
    {
        $s = $this->houseLine($h);
        $res = $this->houseResource($h);
        if ($res) {
            $comfort = [];
            foreach ($this->pairs((string)$res->getTVValue('bn_comfort'), 3) as $p) {
                $comfort[] = '• ' . $p[1] . ($p[2] !== '' ? ' — ' . $p[2] : '');
            }
            if ($comfort) $s .= "\n\nЧто есть в Доме:\n" . implode("\n", $comfort);
        }
        $s .= "\n\nЗаезд с " . $this->hm($h->get('checkin_time')) . ', выезд до ' . $this->hm($h->get('checkout_time')) . '.';
        $s .= "\n\n" . $this->pricesText([$h]);
        if ($res) {
            $s .= "\n\nФото и подробности: " . $this->url((int)$res->get('id'));
        }
        return $s;
    }

    // ------------------------------------------------------------------ частые вопросы с сайта

    /** Частые вопросы: главной (общие) и Домов — [вопрос, ответ, код дома или ''] */
    public function faqItems(): array
    {
        $items = [];
        if ($home = $this->homeResource()) {
            foreach ($this->pairs((string)$home->getTVValue('bn_faq')) as [$q, $a]) $items[] = [$q, $a, ''];
        }
        foreach ($this->houses() as $h) {
            if (!($res = $this->houseResource($h))) continue;
            foreach ($this->pairs((string)$res->getTVValue('bn_faq')) as [$q, $a]) $items[] = [$q, $a, $h->get('code')];
        }
        return $items;
    }

    protected function faqScored(string $q, ?House $house): array
    {
        $words = $this->stems($q);
        if (!$words) return [];
        $code = $house ? $house->get('code') : '';
        $scored = [];
        foreach ($this->faqItems() as $i => [$fq, $fa, $fh]) {
            if ($code !== '' && $fh !== '' && $fh !== $code) continue;   // спросили про один Дом — чужие вопросы не берём
            $qs = $this->stems($this->norm($fq));
            $as = $this->stems($this->norm($fa));
            $score = 0.0;
            foreach ($words as $w) {
                if ($this->has($qs, $w)) $score += 2;
                elseif ($this->has($as, $w)) $score += 0.7;
            }
            if ($score <= 0) continue;
            if ($code !== '' && $fh === $code) $score += 0.5;
            if ($code === '' && $fh === '') $score += 0.3;   // без Дома — общий ответ главной важнее
            $scored[] = [$score / max(1, count($words)) * min(count($words), 3), $fq, $fa, $fh];
        }
        usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
        return $scored;
    }

    protected function faqStrong(string $q, ?House $house): bool
    {
        $s = $this->faqScored($q, $house);
        return $s && $s[0][0] >= 4;
    }

    protected function faqAnswer(string $q, ?House $house): ?array
    {
        $s = $this->faqScored($q, $house);
        if (!$s || $s[0][0] < 2) {
            return null;
        }
        $name = '';
        if ($s[0][3] !== '' && ($h = $this->house($s[0][3]))) $name = $h->get('name') . ". ";
        return $this->reply($name . $s[0][1] . "\n" . $s[0][2]);
    }

    /** Удобства Домов (bn_comfort «значок | заголовок | пояснение»): ['score', 'text'] или null */
    protected function comfortScored(string $q, ?House $house): ?array
    {
        $words = $this->stems($q);
        if (!$words) return null;
        $hits = [];
        $best = 0.0;
        foreach ($house ? [$house] : $this->houses() as $h) {
            if (!($res = $this->houseResource($h))) continue;
            foreach ($this->pairs((string)$res->getTVValue('bn_comfort'), 3) as $p) {
                $ts = $this->stems($this->norm($p[1]));
                $ds = $this->stems($this->norm($p[2]));
                $score = 0.0;
                foreach ($words as $w) {
                    if ($this->has($ts, $w)) $score += 2;
                    elseif ($this->has($ds, $w)) $score += 1.5;
                }
                if ($score < 1.5) continue;
                $best = max($best, $score);
                $hits[] = [$score, $h->get('name') . ': ' . $p[1] . ($p[2] !== '' ? ' — ' . $p[2] : '')];
            }
        }
        if (!$hits) return null;
        $hits = array_values(array_filter($hits, fn($x) => $x[0] >= $best));
        // есть не во всех Домах — так и говорим, чтобы гость не решил, что это есть везде
        $all = $house || count(array_unique(array_map(fn($x) => strtok($x[1], ':'), $hits))) >= count($this->houses());
        return ['score' => $best, 'text' => ($all ? "Да, есть:\n" : "Есть в этих Домах:\n") . implode("\n", array_map(fn($x) => '• ' . $x[1], array_slice($hits, 0, 6)))];
    }

    /** Строки правил Домов, подходящие к вопросу (курение, шум, залог …) */
    protected function rulesMatch(string $q): ?string
    {
        $words = $this->stems($q);
        $home = $this->homeResource();
        if (!$words || !$home) return null;
        $out = [];
        foreach ($this->pairs((string)$home->getTVValue('bn_rules')) as [$k, $v]) {
            $ks = $this->stems($this->norm($k . ' ' . $v));
            foreach ($words as $w) {
                if ($this->has($ks, $w)) { $out[] = '• ' . $k . ($v !== '' ? ': ' . $v : ''); break; }
            }
        }
        return $out ? "Из правил Домов:\n" . implode("\n", $out) : null;
    }

    // ------------------------------------------------------------------ кнопки

    /** Меню под каждым ответом: темы по две в ряд и ссылка на бронирование */
    public function menu(): array
    {
        $rows = [];
        $row = [];
        foreach (self::TOPICS as $p => $label) {
            $row[] = ['type' => 'callback', 'text' => $label, 'payload' => $p];
            if (count($row) === 2) { $rows[] = $row; $row = []; }
        }
        if ($row) $rows[] = $row;
        if ($url = $this->appUrl()) {
            $rows[] = [['type' => 'link', 'text' => 'Забронировать', 'url' => $url]];
        }
        return $rows;
    }

    protected function houseButtons(): array
    {
        $row = [];
        foreach ($this->houses() as $h) {
            $row[] = ['type' => 'callback', 'text' => $h->get('name'), 'payload' => 'house:' . $h->get('code')];
        }
        return $row ? array_chunk($row, 2) : [];
    }

    protected function reply(string $text, array $extraRows = []): array
    {
        return ['text' => mb_substr($text, 0, 3900), 'buttons' => array_merge($extraRows, $this->menu())];
    }

    /** Адрес бронирования для гостя из MAX: /booking/app?client=max (или страница формы) */
    public function appUrl(): string
    {
        $modx = $this->booking->modx;
        $fp = (int)$modx->getOption('booking.form_page');
        if ($fp <= 0) return '';
        $app = $modx->getObject(modResource::class, ['alias' => 'app', 'parent' => $fp, 'published' => 1, 'deleted' => 0]);
        $url = $this->url($app ? (int)$app->get('id') : $fp);
        return $url === '' ? '' : $url . ($app ? (strpos($url, '?') === false ? '?' : '&') . 'client=max' : '');
    }

    // ------------------------------------------------------------------ данные

    /** @return House[] */
    public function houses(): array
    {
        $c = $this->booking->modx->newQuery(House::class, ['active' => 1]);
        $c->sortby('sort', 'ASC');
        $c->sortby('id', 'ASC');
        return array_values($this->booking->modx->getCollection(House::class, $c));
    }

    protected function house(string $code): ?House
    {
        foreach ($this->houses() as $h) {
            if ($h->get('code') === $code) return $h;
        }
        return null;
    }

    protected function detectHouse(string $q): ?House
    {
        $found = [];
        foreach ($this->houses() as $h) {
            $keys = [mb_strtolower($h->get('code'))];
            foreach (preg_split('~[\s,]+~u', $this->norm($h->get('name') . ' ' . $h->get('address'))) as $w) {
                if (mb_strlen($w) >= 5 && !in_array($w, ['космонавта', 'беляева', 'архитектора'], true)) $keys[] = mb_substr($w, 0, 5);
            }
            foreach (array_unique($keys) as $k) {
                if (mb_strlen($k) >= 5 && mb_strpos($q, $k) !== false) { $found[$h->get('code')] = $h; break; }
            }
        }
        return count($found) === 1 ? reset($found) : null;   // два Дома в вопросе — отвечаем про все
    }

    protected function homeResource(): ?modResource
    {
        $id = (int)$this->booking->modx->getOption('site_start', null, 1);
        return $this->booking->modx->getObject(modResource::class, ['id' => $id, 'deleted' => 0]);
    }

    protected function houseResource(House $h): ?modResource
    {
        $modx = $this->booking->modx;
        if ((int)$h->get('resource_id') > 0 && ($r = $modx->getObject(modResource::class, ['id' => (int)$h->get('resource_id'), 'deleted' => 0, 'published' => 1]))) {
            return $r;
        }
        $tv = $modx->getObject(modTemplateVar::class, ['name' => 'bn_code']);
        if (!$tv) return null;
        foreach ($modx->getIterator(modTemplateVarResource::class, ['tmplvarid' => $tv->get('id'), 'value' => $h->get('code')]) as $v) {
            $r = $modx->getObject(modResource::class, ['id' => (int)$v->get('contentid'), 'deleted' => 0, 'published' => 1]);
            if ($r) return $r;
        }
        return null;
    }

    /** Ближайшие (60 дней) праздники и особые даты: «4 ноября (День народного единства)» */
    protected function upcomingHolidays(): array
    {
        $modx = $this->booking->modx;
        $today = substr($this->booking->now(), 0, 10);
        $c = $modx->newQuery(Holiday::class, ['date:>=' => $today, 'date:<=' => date('Y-m-d', strtotime($today . ' +60 days'))]);
        $c->sortby('date', 'ASC');
        $c->limit(3);
        $months = [1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $out = [];
        foreach ($modx->getIterator(Holiday::class, $c) as $h) {
            $t = strtotime($h->get('date'));
            $out[] = (int)date('j', $t) . ' ' . $months[(int)date('n', $t)] . ($h->get('comment') !== '' ? ' — ' . $h->get('comment') : '');
        }
        return $out;
    }

    /** Значение из «Конфигурации» (ClientConfig) или системной настройки */
    public function cc(string $key, string $default = ''): string
    {
        $modx = $this->booking->modx;
        $v = $modx->getOption($key, null, null);
        if ($v === null || $v === '') {
            try {
                $st = $modx->prepare('SELECT `value` FROM ' . $modx->getOption('table_prefix') . 'clientconfig_setting WHERE `key` = ? LIMIT 1');
                if ($st && $st->execute([$key])) $v = $st->fetchColumn();
            } catch (\Throwable $e) {
                $v = null;   // ClientConfig не установлен
            }
        }
        $v = trim((string)$v);
        return $v !== '' ? $v : $default;
    }

    protected function url(int $id): string
    {
        $u = $this->booking->modx->makeUrl($id, '', '', 'full');
        return str_replace('&amp;', '&', (string)$u);
    }

    protected function mapUrl(string $address): string
    {
        return 'https://yandex.ru/maps/?text=' . rawurlencode('Пермь, ' . $address);
    }

    // ------------------------------------------------------------------ разбор текста

    public function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('~[^\p{L}\p{N}₽/\-\s]+~u', ' ', $s);
        return trim(preg_replace('~\s+~u', ' ', $s));
    }

    protected function intents(string $q): array
    {
        $found = [];
        $padded = ' ' . $q;
        foreach (self::INTENTS as $name => $keys) {
            foreach ($keys as $k) {
                // начало слова: «бан» находит «баня», «банька», но не «ванна»
                if (mb_strpos($padded, ' ' . $k) !== false) { $found[] = $name; break; }
            }
        }
        return $found;
    }

    /** Значимые слова вопроса (без служебных) */
    protected function stems(string $q): array
    {
        $out = [];
        foreach (explode(' ', $q) as $w) {
            if ($w === '' || in_array($w, self::STOP, true) || mb_strlen($w) < 3) continue;
            $out[] = $w;
        }
        return array_values(array_unique($out));
    }

    /** Есть ли среди слов то же слово в другой форме: общее начало от 3 букв, у более короткого отличаются не больше 3 последних букв (курить/курение, баня/бани, парковка/парковаться) */
    protected function has(array $words, string $w): bool
    {
        foreach ($words as $x) {
            $n = min(mb_strlen($x), mb_strlen($w));
            $p = 0;
            while ($p < $n && mb_substr($x, $p, 1) === mb_substr($w, $p, 1)) $p++;
            if ($n <= 4 && abs(mb_strlen($x) - mb_strlen($w)) > 2) continue;   // «пол» — не «полотенца»
            if ($p >= 3 && $p >= $n - 3) return true;
        }
        return false;
    }

    /** Строки «a | b | c» → [[a, b, c], …] (не меньше $n частей) */
    protected function pairs(string $text, int $n = 2): array
    {
        $out = [];
        foreach (preg_split('~\R~u', $text) as $line) {
            $line = trim(strip_tags($line));
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            $out[] = array_pad($parts, $n, '');
        }
        return $out;
    }

    protected function hm($time): string
    {
        return substr((string)$time, 0, 5);
    }
}
