<?php
namespace Booking\Processors;

use Booking\Model\Booking as BookingRow;
use Booking\Model\House as HouseRow;
use Booking\Service\BookingError;
use MODX\Revolution\modMenu;

/**
 * booking_migrate_leads — перенос прежних заявок сайта (bania_leads) в брони. Только из админки MODX.
 * op=preview — что будет перенесено; op=run — перенос (повтор ничего не дублирует: связь по legacy_lead_id);
 * op=retire — вывод прежних «Заявок» из работы: пункт меню снимается, таблицы переименовываются
 * в *_archived (не удаляются) — конституция ст. 2.4.
 * Статусы: new→new, contacted→new (+заметка), confirmed→confirmed (с проверкой пересечений),
 * done→completed, declined→cancelled.
 */
class Migrate extends Base
{
    public static bool $cmpOnly = true;

    public const STATUS_MAP = [
        'new' => 'new',
        'contacted' => 'new',
        'confirmed' => 'confirmed',
        'done' => 'completed',
        'declined' => 'cancelled',
    ];

    protected function handle(): array
    {
        $op = $this->str('op', 20) ?: 'preview';
        if ($op === 'retire') {
            return $this->retire();
        }
        $table = $this->legacyTable();
        if (!$table) {
            return ['items' => [], 'total' => 0, 'message' => 'Прежних заявок нет (таблица bania_leads не найдена)'];
        }
        $leads = $this->modx->query("SELECT * FROM {$table} ORDER BY id ASC");
        $items = [];
        $done = ['moved' => 0, 'skipped' => 0, 'conflicts' => 0, 'errors' => 0];
        foreach ($leads ? $leads->fetchAll(\PDO::FETCH_ASSOC) : [] as $lead) {
            $item = $this->plan($lead);
            if ($op === 'run' && $item['action'] === 'move') {
                try {
                    $item += $this->move($lead, $item);
                    $done[$item['result'] === 'conflict' ? 'conflicts' : 'moved']++;
                } catch (\Throwable $e) {
                    $item['result'] = 'error';
                    $item['error'] = $e->getMessage();
                    $done['errors']++;
                }
            } elseif ($item['action'] !== 'move') {
                $done['skipped']++;
            }
            $items[] = $item;
        }
        if ($op === 'run') {
            $this->booking->log()->write('migrate', 0, 'run', null, $done);
        }
        return ['items' => $items, 'total' => count($items), 'report' => $done];
    }

    protected function legacyTable(): ?string
    {
        $name = $this->modx->getOption('table_prefix') . 'bania_leads';
        $st = $this->modx->query('SHOW TABLES LIKE ' . $this->modx->quote($name));
        return $st && $st->fetchColumn() ? '`' . $name . '`' : null;
    }

    protected function house(array $lead): ?HouseRow
    {
        $h = $this->modx->getObject(HouseRow::class, ['resource_id' => (int)$lead['house_id']]);
        return $h ?: $this->modx->getObject(HouseRow::class, ['name' => trim((string)$lead['house_name'])]);
    }

    protected function plan(array $lead): array
    {
        $item = ['lead_id' => (int)$lead['id'], 'name' => $lead['name'], 'house_name' => $lead['house_name'],
            'dates' => $lead['checkin'] . ' – ' . $lead['checkout'], 'old_status' => $lead['status']];
        $existing = $this->modx->getObject(BookingRow::class, ['legacy_lead_id' => (int)$lead['id']]);
        if ($existing) {
            return $item + ['action' => 'exists', 'booking_id' => (int)$existing->get('id'), 'new_status' => $existing->get('status')];
        }
        if (!$this->house($lead)) {
            return $item + ['action' => 'no_house', 'new_status' => null];
        }
        if (!$lead['checkin'] || !$lead['checkout'] || $lead['checkout'] <= $lead['checkin']) {
            return $item + ['action' => 'bad_dates', 'new_status' => null];
        }
        return $item + ['action' => 'move', 'new_status' => self::STATUS_MAP[$lead['status']] ?? 'new'];
    }

    /** Перенести одну заявку; подтверждённая — через ту же проверку пересечений */
    protected function move(array $lead, array $item): array
    {
        $b = $this->booking;
        $bk = $b->bookings();
        $house = $this->house($lead);
        $status = $item['new_status'];
        $notes = [];
        if ($lead['status'] === 'contacted') {
            $notes[] = 'В прежних заявках: «связались»';
        }
        if (trim((string)$lead['note']) !== '') {
            $notes[] = trim((string)$lead['note']);
        }
        $in = [
            'house' => $house, 'addon' => null,
            'date_from' => $lead['checkin'], 'date_to' => $lead['checkout'], 'time_from' => null, 'time_to' => null,
            'guests' => max(1, (int)$lead['guests']), 'pets' => 0,
            'name' => mb_substr((string)$lead['name'], 0, 100) ?: 'Гость',
            'comment' => trim(($lead['occasion'] ? 'Повод: ' . $lead['occasion'] . "\n" : '') . ($lead['contact'] ? 'Связь: ' . $lead['contact'] . "\n" : '') . (string)$lead['comment']),
        ];
        try {
            $in['phone'] = $b->phone($lead['phone']);
        } catch (BookingError $e) {
            $in['phone'] = mb_substr((string)$lead['phone'], 0, 20);
        }
        $total = (int)$lead['estimate'];
        $quote = ['total' => $total, 'discount' => 0,
            'lines' => [['label' => 'Сумма из прежней заявки №' . $lead['id'] . ($lead['discount'] ? ' (со скидкой ' . (int)$lead['discount'] . '%)' : ''), 'qty' => 1, 'amount' => $total]]];
        $extra = ['legacy_lead_id' => (int)$lead['id'], 'admin_note' => implode("\n", $notes),
            'created_at' => $lead['created_at'] ?: $b->now()];
        if ($status === 'cancelled') {
            $extra['cancellation_requested_by'] = 'admin';
            $extra['cancellation_reason'] = 'Отклонена в прежних заявках';
        }

        return $this->transaction(function () use ($b, $bk, $in, $quote, $status, $extra, $house) {
            $result = 'moved';
            if ($status === 'confirmed') {
                $b->availability()->lockHouse((int)$house->get('id'));
                try {
                    $b->availability()->assertHouseFree($house, $in['date_from'], $in['date_to']);
                } catch (BookingError $e) {
                    // пересечение — переносим новой заявкой, администратор решит сам
                    $status = 'new';
                    $extra['admin_note'] = trim($extra['admin_note'] . "\nНе подтверждена при переносе: " . $e->getMessage());
                    $result = 'conflict';
                }
            }
            [$row] = $bk->create($in, $quote, $status, 'migrated', $extra);
            if ($status === 'confirmed') {
                $b->availability()->occupy($row);
            }
            $b->log()->write('booking', (int)$row->get('id'), 'migrate', ['lead_id' => $extra['legacy_lead_id']], ['status' => $status]);
            return ['result' => $result, 'booking_id' => (int)$row->get('id'), 'new_status' => $status];
        });
    }

    protected function retire(): array
    {
        $table = $this->legacyTable();
        if ($table) {
            $st = $this->modx->query("SELECT l.id FROM {$table} l LEFT JOIN " . $this->modx->getTableName(BookingRow::class)
                . ' b ON b.legacy_lead_id = l.id WHERE b.id IS NULL');
            $left = $st ? $st->fetchAll(\PDO::FETCH_COLUMN) : [];
            if ($left) {
                throw new BookingError('Не перенесены заявки №' . implode(', ', $left) . ' — сначала перенос', 400);
            }
        }
        $done = [];
        $menu = $this->modx->getObject(modMenu::class, ['namespace' => 'bania', 'action' => 'index']);
        if ($menu) {
            $done[] = 'пункт меню «' . $menu->get('text') . '» снят';
            $menu->remove();
        }
        $prefix = $this->modx->getOption('table_prefix');
        foreach (['bania_leads', 'bania_nights'] as $t) {
            $st = $this->modx->query('SHOW TABLES LIKE ' . $this->modx->quote($prefix . $t));
            if ($st && $st->fetchColumn()) {
                $this->modx->exec("RENAME TABLE `{$prefix}{$t}` TO `{$prefix}{$t}_archived`");
                $done[] = "таблица {$t} → {$t}_archived";
            }
        }
        $this->booking->log()->write('migrate', 0, 'retire', null, $done);
        $this->modx->cacheManager->refresh();
        return ['done' => $done];
    }
}
