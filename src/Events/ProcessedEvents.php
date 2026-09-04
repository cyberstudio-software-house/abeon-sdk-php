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
     *
     * Only integrity-constraint violations (SQLSTATE 23000) count as a duplicate; every
     * other QueryException (connection refused, deadlock, syntax error, permission
     * denied) rethrows, so the EventConsumer can nack the message instead of silently
     * acking a "phantom processed" row.
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
        } catch (QueryException $e) {
            if ($this->isDuplicateKeyViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    private function isDuplicateKeyViolation(QueryException $e): bool
    {
        if ($e->getCode() === '23000') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'unique violation');
    }

    private function table(): Builder
    {
        return $this->db->connection($this->config->outboxConnection())->table(self::TABLE);
    }
}
