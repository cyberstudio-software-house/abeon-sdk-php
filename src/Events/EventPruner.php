<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Config\AbeonConfig;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Deletes the event bookkeeping that has done its job (ADR-0002).
 *
 * Separate from the command so the rule — which rows may go, and which may not — is testable
 * without a console. `abeon:events:prune` is the wrapper.
 */
class EventPruner
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AbeonConfig $config,
    ) {
    }

    /**
     * @return array{outbox: int, processed: int} rows deleted, or countable when $dryRun
     */
    public function prune(?int $days = null, bool $dryRun = false): array
    {
        $days = max(1, $days ?? $this->config->outboxRetentionDays());
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $connection = $this->db->connection($this->config->outboxConnection());

        // **Only processed rows.** An unprocessed outbox row is an event that has not been
        // published, whatever its age, and deleting one loses the write's consequences
        // silently — the opposite of what the outbox is for.
        $outbox = $connection->table(OutboxPublisher::TABLE)
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', $cutoff);

        // The consumer side is where the window matters: a `processed_events` row deleted
        // while its message can still be redelivered turns that redelivery into a second
        // handling, which is the one thing the table exists to prevent.
        $processed = $connection->table(ProcessedEvents::TABLE)
            ->where('processed_at', '<', $cutoff);

        if ($dryRun) {
            return ['outbox' => $outbox->count(), 'processed' => $processed->count()];
        }

        return ['outbox' => $this->deleteInChunks($outbox), 'processed' => $this->deleteInChunks($processed)];
    }

    /**
     * Chunked: a single `DELETE` over a month of rows is one long transaction holding locks
     * in the index the drainer and the consumer are both reading.
     */
    private function deleteInChunks(Builder $query): int
    {
        $deleted = 0;

        do {
            $gone = (clone $query)->limit(self::CHUNK)->delete();
            $deleted += $gone;
        } while ($gone === self::CHUNK);

        return $deleted;
    }
}
