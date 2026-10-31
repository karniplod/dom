<?php
namespace Booking\Processors;

use Booking\Model\Booking as BookingRow;
use Booking\Model\House;
use Booking\Service\BookingError;

/**
 * booking_stats — статистика для владельца и директора за период from…to (по дате заезда; ночи — те, что попали в период).
 * Брони — подтверждённые и завершённые; сумма — расчётная по брони (предоплата и оплата в системе не ведутся).
 *  kpi / prev    — сводка за период и за такой же период перед ним (для сравнения);
 *  houses        — по каждому Дому: брони, ночи, загрузка, сумма, средний чек, гости, баня по часам;
 *  months        — сезонность: ночи и сумма по месяцам и Домам, заявки;
 *  weekdays      — загрузка ночей по дням недели и дни заезда;
 *  lead, stay, group, sources — за сколько дней бронируют, на сколько ночей, сколько гостей, откуда заявки;
 *  ahead         — уже забронировано вперёд (от сегодня, без учёта периода).
 */
class Stats extends Base
{
    public const MAX_DAYS = 1100;
    protected const OK = ['confirmed', 'completed'];
    public const LEAD = [[0, 2, '0–2 дня'], [3, 7, '3–7 дней'], [8, 14, '8–14 дней'], [15, 30, '15–30 дней'], [31, 60, '1–2 месяца'], [61, 100000, 'больше 2 месяцев']];
    public const STAY = [[1, 1, '1 ночь'], [2, 2, '2 ночи'], [3, 3, '3 ночи'], [4, 100000, '4 и больше']];
    public const GROUP = [[1, 5, 'до 5'], [6, 10, '6–10'], [11, 15, '11–15'], [16, 100000, '16 и больше']];

    protected array $houses = [];
    protected \DateTimeZone $tz;

    protected function handle(): array
    {
        $b = $this->booking;
        $tz = $this->tz = $b->tz();
        $today = new \DateTimeImmutable('today', $tz);
        $from = $this->str('from', 10) !== '' ? $b->date($this->str('from', 10), 'from') : $today->modify('first day of this month')->modify('-11 months')->format('Y-m-d');
        $to = $this->str('to', 10) !== '' ? $b->date($this->str('to', 10), 'to') : $today->modify('last day of this month')->format('Y-m-d');
        $f = new \DateTimeImmutable($from, $tz);
        $t = new \DateTimeImmutable($to, $tz);
        if ($t < $f) {
            throw BookingError::field('to', 'Конец периода раньше начала');
        }
        $days = (int)$f->diff($t)->days + 1;
        if ($days > self::MAX_DAYS) {
            throw BookingError::field('to', 'Период — не больше 3 лет');
        }
        foreach ($this->modx->getIterator(House::class, $this->modx->newQuery(House::class)->sortby('sort')->sortby('id')) as $h) {
            // отдельная баня или сауна ночей не продаёт — в загрузку Домов не входит (её сеансы — в «Баня по часам»)
            $this->houses[(int)$h->get('id')] = ['code' => $h->get('code'), 'name' => $h->get('name'), 'active' => $h->get('active') && $h->get('kind') !== 'bath'];
        }
        $repeat = $this->repeatPhones();
        $cur = $this->calc($this->rows($from, $to), $f, $t, $repeat);
        $pt = $f->modify('-1 day');
        $pf = $pt->modify('-' . ($days - 1) . ' days');
        $prev = $this->calc($this->rows($pf->format('Y-m-d'), $pt->format('Y-m-d')), $pf, $pt, $repeat);

        return [
            'from' => $from, 'to' => $to, 'days' => $days,
            'prev_from' => $pf->format('Y-m-d'), 'prev_to' => $pt->format('Y-m-d'),
            'houses' => array_values(array_map(fn($h) => ['code' => $h['code'], 'name' => $h['name']], $this->shown($cur))),
            'kpi' => $cur['kpi'], 'prev' => $prev['kpi'],
            'by_house' => array_values(array_intersect_key($cur['house'], $this->shown($cur))),
            // ночи и сумма по Домам — объект {код: число} и при пустом месяце
            'months' => array_values(array_map(fn($m) => ['nights' => (object)$m['nights'], 'revenue' => (object)$m['revenue']] + $m, $cur['months'])),
            'weekdays' => $cur['weekdays'],
            'lead' => $this->buckets(self::LEAD, $cur['lead']),
            'stay' => $this->buckets(self::STAY, $cur['stay']),
            'group' => $this->buckets(self::GROUP, $cur['group']),
            'sources' => $cur['sources'],
            'ahead' => $this->ahead($today),
        ];
    }

    /** Дома в отчёте: активные и те, у кого были брони в периоде */
    protected function shown(array $cur): array
    {
        return array_filter($this->houses, fn($h, $id) => $h['active'] || $cur['house'][$id]['bookings'] || $cur['house'][$id]['bath_sessions'], ARRAY_FILTER_USE_BOTH);
    }

    /** Брони с заездом в периоде или с ночами в нём */
    protected function rows(string $from, string $to): array
    {
        $q = $this->modx->newQuery(BookingRow::class);
        $q->select(['id', 'house_id', 'addon_id', 'date_from', 'date_to', 'time_from', 'time_to', 'guests_count', 'guest_phone', 'quote_total', 'quote_discount', 'status', 'source', 'created_at']);
        $q->where(['date_from:<=' => $to, 'date_to:>=' => $from]);
        $q->prepare();
        $q->stmt->execute();
        return $q->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Телефоны гостей, у которых две и больше состоявшихся брони за всё время (последние 10 цифр) */
    protected function repeatPhones(): array
    {
        $q = $this->modx->newQuery(BookingRow::class);
        $q->select(['guest_phone']);
        $q->where(['status:IN' => self::OK]);
        $q->prepare();
        $q->stmt->execute();
        $n = [];
        foreach ($q->stmt->fetchAll(\PDO::FETCH_COLUMN) as $p) {
            $k = substr(preg_replace('~\D+~', '', (string)$p), -10);
            if ($k !== '') {
                $n[$k] = ($n[$k] ?? 0) + 1;
            }
        }
        return array_filter($n, fn($c) => $c > 1);
    }

    protected function calc(array $rows, \DateTimeImmutable $f, \DateTimeImmutable $t, array $repeat): array
    {
        $from = $f->format('Y-m-d');
        $to = $t->format('Y-m-d');
        $active = array_filter($this->houses, fn($h) => $h['active']);
        $nh = max(1, count($active));
        $days = (int)$f->diff($t)->days + 1;

        $house = [];
        foreach ($this->houses as $id => $h) {
            $house[$id] = ['code' => $h['code'], 'name' => $h['name'], 'bookings' => 0, 'nights' => 0, 'available' => $h['active'] ? $days : 0,
                'occupancy' => 0, 'revenue' => 0, 'avg_check' => 0, 'guests' => 0, 'avg_guests' => 0, 'requests' => 0, 'cancelled' => 0,
                'bath_sessions' => 0, 'bath_hours' => 0, 'bath_revenue' => 0];
        }
        $months = [];
        $wd = array_fill(0, 7, ['nights' => 0, 'available' => 0, 'checkins' => 0]);
        for ($d = $f; $d <= $t; $d = $d->modify('+1 day')) {
            $m = $d->format('Y-m');
            $months[$m] ??= ['month' => $m, 'nights' => [], 'revenue' => [], 'available' => 0, 'requests' => 0, 'bookings' => 0];
            $months[$m]['available'] += $nh;
            $wd[(int)$d->format('N') - 1]['available'] += $nh;
        }
        $lead = $stay = $group = [];
        $sources = [];
        $k = ['revenue' => 0, 'bookings' => 0, 'nights' => 0, 'guests' => 0, 'discount' => 0, 'stay_sum' => 0, 'requests' => 0, 'ok' => 0,
            'cancelled' => 0, 'pending' => 0, 'bath_sessions' => 0, 'bath_hours' => 0, 'bath_revenue' => 0];
        $phones = $repeaters = [];
        $leads = [];

        foreach ($rows as $r) {
            $hid = (int)$r['house_id'];
            if (!isset($house[$hid])) {
                continue;
            }
            $code = $house[$hid]['code'];
            $ok = in_array($r['status'], self::OK, true);
            $starts = $r['date_from'] >= $from && $r['date_from'] <= $to;
            $bath = !empty($r['addon_id']);
            $m = substr($r['date_from'], 0, 7);
            if ($starts) {
                $k['requests']++;
                $house[$hid]['requests']++;
                $months[$m]['requests']++;
                if ($ok) {
                    $k['ok']++;
                } elseif ($r['status'] === 'cancelled') {
                    $k['cancelled']++;
                    $house[$hid]['cancelled']++;
                } else {
                    $k['pending']++;
                }
                $src = $r['source'] ?: 'site';
                $sources[$src] ??= ['source' => $src, 'requests' => 0, 'bookings' => 0, 'revenue' => 0];
                $sources[$src]['requests']++;
            }
            if (!$ok) {
                continue;
            }
            $total = (int)$r['quote_total'];
            if ($starts) {
                $sources[$src]['bookings']++;
                $sources[$src]['revenue'] += $total;
                $months[$m]['revenue'][$code] = ($months[$m]['revenue'][$code] ?? 0) + $total;
                $k['revenue'] += $total;
                $k['discount'] += (int)$r['quote_discount'];
                $pk = substr(preg_replace('~\D+~', '', (string)$r['guest_phone']), -10);
                if ($pk !== '') {
                    $phones[$pk] = true;
                    if (isset($repeat[$pk])) {
                        $repeaters[$pk] = true;
                    }
                }
            }
            if ($bath) {
                if ($starts) {
                    $hours = $r['time_from'] && $r['time_to'] ? max(0, (strtotime('1970-01-01 ' . $r['time_to']) - strtotime('1970-01-01 ' . $r['time_from'])) / 3600) : 0;
                    $k['bath_sessions']++;
                    $k['bath_hours'] += $hours;
                    $k['bath_revenue'] += $total;
                    $house[$hid]['bath_sessions']++;
                    $house[$hid]['bath_hours'] += $hours;
                    $house[$hid]['bath_revenue'] += $total;
                }
                continue;
            }
            // ночи Дома, попавшие в период: для загрузки, сезонности и дней недели
            $df = new \DateTimeImmutable($r['date_from'], $this->tz);
            $dt = new \DateTimeImmutable($r['date_to'], $this->tz);
            for ($d = max($df, $f); $d < $dt && $d <= $t; $d = $d->modify('+1 day')) {
                $mm = $d->format('Y-m');
                $months[$mm]['nights'][$code] = ($months[$mm]['nights'][$code] ?? 0) + 1;
                $wd[(int)$d->format('N') - 1]['nights']++;
                $house[$hid]['nights']++;
                $k['nights']++;
            }
            if (!$starts) {
                continue;
            }
            $len = max(1, (int)$df->diff($dt)->days);
            $guests = (int)$r['guests_count'];
            $k['bookings']++;
            $k['guests'] += $guests;
            $k['stay_sum'] += $len;
            $months[$m]['bookings']++;
            $house[$hid]['bookings']++;
            $house[$hid]['revenue'] += $total;
            $house[$hid]['guests'] += $guests;
            $wd[(int)$df->format('N') - 1]['checkins']++;
            $stay[] = $len;
            $group[] = $guests;
            if ($r['created_at'] && $r['source'] !== 'migrated') {
                $ld = (int)floor((strtotime($r['date_from']) - strtotime(substr($r['created_at'], 0, 10))) / 86400);
                if ($ld >= 0) {
                    $lead[] = $ld;
                    $leads[] = $ld;
                }
            }
        }

        foreach ($house as &$h) {
            $h['occupancy'] = $h['available'] ? round($h['nights'] * 100 / $h['available'], 1) : 0;
            $h['avg_check'] = $h['bookings'] ? (int)round($h['revenue'] / $h['bookings']) : 0;
            $h['avg_guests'] = $h['bookings'] ? round($h['guests'] / $h['bookings'], 1) : 0;
            $h['bath_hours'] = round($h['bath_hours'], 1);
        }
        unset($h);
        foreach ($months as &$mo) {
            $mo['occupancy'] = $mo['available'] ? round(array_sum($mo['nights']) * 100 / $mo['available'], 1) : 0;
        }
        unset($mo);
        foreach ($wd as &$w) {
            $w['occupancy'] = $w['available'] ? round($w['nights'] * 100 / $w['available'], 1) : 0;
        }
        unset($w);
        sort($leads);
        $houseRevenue = $k['revenue'] - $k['bath_revenue'];
        $closed = $k['ok'] + $k['cancelled'];
        $kpi = [
            'revenue' => $k['revenue'],
            'bookings' => $k['bookings'],
            'nights' => $k['nights'],
            'occupancy' => round($k['nights'] * 100 / ($days * $nh), 1),
            'avg_check' => $k['bookings'] ? (int)round($houseRevenue / $k['bookings']) : 0,
            'avg_night' => $k['stay_sum'] ? (int)round($houseRevenue / max(1, $k['stay_sum'])) : 0,
            'avg_stay' => $k['bookings'] ? round($k['stay_sum'] / $k['bookings'], 1) : 0,
            'avg_guests' => $k['bookings'] ? round($k['guests'] / $k['bookings'], 1) : 0,
            'guests' => $k['guests'],
            'requests' => $k['requests'],
            'pending' => $k['pending'],
            'cancelled' => $k['cancelled'],
            // из решённых заявок (подтверждены или отменены) — новые, ещё не решённые, не считаем
            'conversion' => $closed ? round($k['ok'] * 100 / $closed, 1) : 0,
            'cancel_rate' => $closed ? round($k['cancelled'] * 100 / $closed, 1) : 0,
            'lead_median' => $leads ? $leads[intdiv(count($leads), 2)] : null,
            'discount' => $k['discount'],
            'bath_sessions' => $k['bath_sessions'],
            'bath_hours' => round($k['bath_hours'], 1),
            'bath_revenue' => $k['bath_revenue'],
            'unique_guests' => count($phones),
            'repeat_guests' => count($repeaters),
        ];
        $order = array_flip(['site', 'pwa', 'max', 'admin', 'migrated']);
        uksort($sources, fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
        return ['kpi' => $kpi, 'house' => $house, 'months' => $months, 'weekdays' => $wd, 'lead' => $lead, 'stay' => $stay, 'group' => $group, 'sources' => array_values($sources)];
    }

    protected function buckets(array $def, array $values): array
    {
        $out = array_map(fn($d) => ['label' => $d[2], 'count' => 0], $def);
        foreach ($values as $v) {
            foreach ($def as $i => $d) {
                if ($v >= $d[0] && $v <= $d[1]) {
                    $out[$i]['count']++;
                    break;
                }
            }
        }
        return $out;
    }

    /** Уже забронировано вперёд: подтверждённые с заездом от сегодня; загрузка ближайших 30 ночей */
    protected function ahead(\DateTimeImmutable $today): array
    {
        $q = $this->modx->newQuery(BookingRow::class);
        $q->select(['addon_id', 'date_from', 'date_to', 'quote_total']);
        $q->where(['status' => 'confirmed', 'date_to:>' => $today->format('Y-m-d')]);
        $q->prepare();
        $q->stmt->execute();
        $end = $today->modify('+30 days');
        $out = ['bookings' => 0, 'revenue' => 0, 'nights30' => 0];
        foreach ($q->stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            if ($r['date_from'] >= $today->format('Y-m-d')) {
                $out['bookings']++;
                $out['revenue'] += (int)$r['quote_total'];
            }
            if ($r['addon_id']) {
                continue;
            }
            for ($d = max(new \DateTimeImmutable($r['date_from'], $this->tz), $today); $d < new \DateTimeImmutable($r['date_to'], $this->tz) && $d < $end; $d = $d->modify('+1 day')) {
                $out['nights30']++;
            }
        }
        $nh = max(1, count(array_filter($this->houses, fn($h) => $h['active'])));
        $out['occupancy30'] = round($out['nights30'] * 100 / (30 * $nh), 1);
        return $out;
    }
}
