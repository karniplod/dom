<?php
namespace Booking\Service;

use Booking\Model\LogEntry;

/**
 * Журнал действий: кто, что, когда, через какой интерфейс (ст. 3.5).
 */
class Log
{
    protected Booking $booking;

    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }

    public function write(string $entity, int $entityId, string $action, $old = null, $new = null): void
    {
        $actor = $this->booking->actor();
        $e = $this->booking->modx->newObject(LogEntry::class);
        $e->fromArray([
            'created_at' => $this->booking->now(),
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'old_value' => $old,
            'new_value' => $new,
            'actor' => mb_substr((string)$actor['name'], 0, 100),
            'interface' => $actor['interface'],
        ]);
        $e->save();
    }

    public function forEntity(string $entity, int $id): array
    {
        $q = $this->booking->modx->newQuery(LogEntry::class, ['entity' => $entity, 'entity_id' => $id]);
        $q->sortby('id', 'ASC');
        $out = [];
        foreach ($this->booking->modx->getIterator(LogEntry::class, $q) as $e) {
            $out[] = $e->get(['created_at', 'action', 'old_value', 'new_value', 'actor', 'interface']);
        }
        return $out;
    }
}
