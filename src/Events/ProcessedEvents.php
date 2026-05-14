<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Config\AbeonConfig;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\Query\Builder;

/**
 * Consumer-side idempotency: tracks event_ids this service has already
 * processed so repeated deliveries are detected and acked without re-running
 * the handler.
 */
class ProcessedEvents
{
    public const TABLE = 'abeon_processed_events';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AbeonConfig $config,
    ) {
    }

    public function isProcessed(string $eventId): bool
    {
        return $this->table()->where('event_id', $eventId)->exists();
    }

    /**
     * Record that an event was processed. Returns true if newly inserted,
     * false if already present (duplicate delivery).
     */
    public function markProcessed(string $eventId, string $routingKey): bool
    {
        try {
            $this->table()->insert([
                'event_id'     => $eventId,
                'routing_key'  => $routingKey,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (QueryException) {
            // Unique-constraint violation on event_id → already processed.
            return false;
        }
    }

    private function table(): Builder
    {
        return $this->db->connection($this->config->outboxConnection())->table(self::TABLE);
    }
}
