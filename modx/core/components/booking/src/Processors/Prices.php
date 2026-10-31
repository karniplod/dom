<?php
namespace Booking\Processors;

use Booking\Model\House as HouseRow;
use Booking\Model\PriceRule;
use Booking\Service\BookingError;
use Booking\Service\Pricing;

/**
 * booking_manage_prices — цены суток дома по типам дней и доплаты.
 * op=list — все дома; op=save — house, rules{weekday,friday,saturday,sunday → цена > 0},
 * extra_guest_price, extra_guest_limit, pet_price (одинаковые во всех строках дома);
 * addon_<тип дня> — баня, ₽/час в этот тип дня (необязательно; пусто — базовая цена бани);
 * addon_rates — цены бани по часам суток (JSON, Pricing::rates), addon_guest_limit / addon_extra_guest_price /
 * addon_extra_guest_per (hour|booking) — гости бани сверх нормы. У отдельной бани (kind=bath) цен суток нет.
 * После сохранения сбрасывается кеш страниц — booking.prices покажет новые цены (ст. 6.3).
 */
class Prices extends Base
{
    protected function handle(): array
    {
        if (($this->str('op', 20) ?: 'list') === 'list') {
            $q = $this->modx->newQuery(HouseRow::class);
            $q->sortby('sort', 'ASC');
            $out = [];
            foreach ($this->modx->getIterator(HouseRow::class, $q) as $h) {
                $out[] = $this->forHouse($h);
            }
            // для прайс-листа на печать: название сайта, телефон, адрес сайта, процент предоплаты
            $faq = $this->booking->faq();
            $site = ['name' => (string)$this->modx->getOption('site_name'), 'phone' => $faq->cc('bn_phone'),
                'url' => $this->host(), 'prepay_percent' => (int)$faq->cc('bn_prepay_percent', '50')];
            return ['items' => $out, 'labels' => Pricing::DAY_LABELS, 'site' => $site];
        }
        $h = $this->modx->getObject(HouseRow::class, ['code' => $this->str('house', 20)]);
        if (!$h) {
            throw BookingError::field('house', 'Дом не найден');
        }
        $rules = $this->arr('rules');
        $prices = [];
        $bath = $h->get('kind') === 'bath';
        foreach (Pricing::DAY_TYPES as $t) {
            $v = $rules[$t] ?? $this->getProperty('price_' . $t);
            if ($bath) { $prices[$t] = 0; continue; }   // сауна только по часам
            if (!is_scalar($v) || !preg_match('~^\d+$~', trim((string)$v)) || (int)$v <= 0) {
                throw BookingError::field('price_' . $t, 'Цена «' . Pricing::DAY_LABELS[$t] . '» — целое число больше нуля');
            }
            $prices[$t] = (int)$v;
        }
        $addonPrices = [];
        foreach (Pricing::DAY_TYPES as $t) {
            $v = $this->getProperty('addon_' . $t);
            if ($v === null || trim((string)$v) === '') { $addonPrices[$t] = null; continue; }
            if (!is_scalar($v) || !preg_match('~^\d+$~', trim((string)$v)) || (int)$v <= 0) {
                throw BookingError::field('addon_' . $t, 'Баня «' . Pricing::DAY_LABELS[$t] . '» — целое число больше нуля или пусто');
            }
            $addonPrices[$t] = (int)$v;
        }
        $addon = $this->modx->getObject(\Booking\Model\Addon::class, ['house_id' => $h->get('id')]);
        // не прислали — прежние (старое приложение не знает про цены по часам)
        $rates = $this->getProperty('addon_rates') === null ? ($addon ? Pricing::rates($addon->get('rates')) : []) : Pricing::rates($this->getProperty('addon_rates'));
        $gLimit = (int)$this->int('addon_guest_limit', $addon ? (int)$addon->get('guest_limit') : 0);
        $gPrice = (int)$this->int('addon_extra_guest_price', $addon ? (int)$addon->get('extra_guest_price') : 0);
        $gPer = $this->str('addon_extra_guest_per', 10) === 'booking' ? 'booking' : 'hour';
        if ($gLimit < 0 || $gPrice < 0) {
            throw BookingError::field('addon_guest_limit', 'Гости бани: норма и доплата не могут быть отрицательными');
        }
        $extra = (int)$this->int('extra_guest_price', 500);
        $limit = (int)$this->int('extra_guest_limit', 10);
        $pet = (int)$this->int('pet_price', 1000);
        if ($extra < 0 || $pet < 0 || $limit < 1) {
            throw new BookingError('Доплаты не могут быть отрицательными, норма гостей — от 1', 400);
        }
        if (!$bath && $limit > (int)$h->get('capacity_party')) {
            throw BookingError::field('extra_guest_limit', 'Норма гостей больше, чем вмещает Дом');
        }
        $old = $this->forHouse($h);
        foreach ($prices as $t => $p) {
            $r = $this->modx->getObject(PriceRule::class, ['house_id' => $h->get('id'), 'day_type' => $t])
                ?: $this->modx->newObject(PriceRule::class, ['house_id' => $h->get('id'), 'day_type' => $t]);
            $r->fromArray(['price' => $p, 'extra_guest_price' => $extra, 'extra_guest_limit' => $limit, 'pet_price' => $pet, 'addon_price' => $addonPrices[$t]]);
            $r->save();
        }
        if ($addon) {
            $addon->fromArray(['rates' => $rates ?: null, 'guest_limit' => $gLimit, 'extra_guest_price' => $gPrice, 'extra_guest_per' => $gPer]);
            $addon->save();
        }
        $new = $this->forHouse($h);
        $this->booking->log()->write('price', (int)$h->get('id'), 'update', $old, $new);
        $this->modx->cacheManager->refresh(['resource' => []]);
        return $new;
    }

    /** Адрес сайта для печати: ефремовскиебани.рф, а не xn--… */
    protected function host(): string
    {
        $host = (string)parse_url((string)$this->modx->getOption('site_url'), PHP_URL_HOST);
        if (strpos($host, 'xn--') !== false && function_exists('idn_to_utf8')) {
            $host = idn_to_utf8($host, 0, INTL_IDNA_VARIANT_UTS46) ?: $host;
        }
        return $host;
    }

    protected function forHouse(HouseRow $h): array
    {
        $rules = $this->booking->pricing()->rules($h->get('id'));
        $out = ['house' => $h->get('code'), 'name' => $h->get('name'), 'kind' => $h->get('kind'), 'active' => (bool)$h->get('active'), 'rules' => []];
        foreach (Pricing::DAY_TYPES as $t) {
            $out['rules'][$t] = isset($rules[$t]) ? (int)$rules[$t]->get('price') : null;
        }
        $out['addon'] = [];
        foreach (Pricing::DAY_TYPES as $t) {
            $out['addon'][$t] = isset($rules[$t]) && $rules[$t]->get('addon_price') ? (int)$rules[$t]->get('addon_price') : null;
        }
        $addon = $this->modx->getObject(\Booking\Model\Addon::class, ['house_id' => $h->get('id'), 'active' => 1]);
        $out['addon_on'] = $addon && $h->get('addon_bookable_separately');
        $out['addon_base'] = $addon ? (int)$addon->get('price_per_hour') : 0;
        $out['addon_name'] = $addon ? $addon->get('name') : 'Баня';
        $out['addon_min_hours'] = $addon ? (int)$addon->get('min_hours') : 2;
        $out['addon_buffer'] = $addon ? (int)$addon->get('buffer_minutes') : 0;
        $out['addon_rates'] = $addon ? Pricing::rates($addon->get('rates')) : [];
        $out['addon_guest_limit'] = $addon ? (int)$addon->get('guest_limit') : 0;
        $out['addon_extra_guest_price'] = $addon ? (int)$addon->get('extra_guest_price') : 0;
        $out['addon_extra_guest_per'] = $addon ? $addon->get('extra_guest_per') : 'hour';
        $out['addon_schedule'] = $addon ? $this->booking->pricing()->schedule($h, $addon) : [];
        $base = $rules['weekday'] ?? reset($rules) ?: null;
        $out['extra_guest_price'] = $base ? (int)$base->get('extra_guest_price') : 500;
        $out['extra_guest_limit'] = $base ? (int)$base->get('extra_guest_limit') : 10;
        $out['pet_price'] = $base ? (int)$base->get('pet_price') : 1000;
        return $out;
    }
}
