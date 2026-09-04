<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Config\AbeonConfig;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Drains `abeon_event_outbox` to RabbitMQ.
 *
 * Polls in batches, publishes each row to the configured exchange with
 * the event's routing key, marks `processed_at` on success. On failure,
 * increments `attempts`, sets `last_error`, and schedules `next_attempt_at`
 * with exponential backoff (2^n seconds). Rows exceeding `max_attempts`
 * stay in the table with their last error for manual triage (no automatic
 * DLQ at the publisher side — DLX is consumer-side only).
 */
class OutboxDrainer
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RabbitMq $rabbit,
        private readonly AbeonConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Run one drain pass. Returns the number of rows processed (success + failure).
     *
     * **Three steps, and the publish is not inside a transaction.** It used to be: the
     * batch was fetched `FOR UPDATE` and published to RabbitMQ while those rows stayed
     * locked until commit, so an unreachable broker held `abeon_event_outbox` locked for
     * the whole of `read_write_timeout`, times the batch size.
     *
     * That is the exact thing this platform's own code warns against elsewhere —
     * `OrganisationMirror` and `abeon:registry:import` both go out of their way to keep an
     * HTTP call off a row lock, at some length, and the SDK did the opposite in its core
     * loop.
     *
     * So: claim the batch under a short lease, publish with nothing locked, then mark each
     * row. A crash between publish and mark republishes on the next pass once the lease
     * expires — which is what `ProcessedEvents` is for on the consumer side, and it is
     * already the guarantee ADR-0002 promises (at-least-once, never exactly-once).
     */
    public function drainOnce(): int
    {
        // **The channel first, then the claim.** Connecting is the step most likely to
        // fail, and failing after the claim would lease a batch for a failure that
        // happened before a single publish was attempted — rows held back for the lease
        // with no `attempts` increment and no `last_error` to explain it. Nothing is
        // claimed unless there is somewhere to publish to.
        $channel  = $this->rabbit->channel();
        $rows     = $this->claimBatch();

        if ($rows === []) {
            return 0;
        }

        $exchange = $this->config->rabbitMqExchange();
        $count    = 0;

        foreach ($rows as $row) {
            $record = OutboxRecord::fromRow($row);

            try {
                $message = new AMQPMessage(
                    body: json_encode($record->envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    properties: [
                        'content_type'  => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'message_id'    => $record->eventId,
                        'timestamp'     => time(),
                    ],
                );

                $channel->basic_publish($message, $exchange, $record->routingKey);

                $this->markProcessed($record->id);
            } catch (Throwable $e) {
                $this->markFailure($record, $e);
                $this->logger->error('outbox.publish.failed', [
                    'event_id'    => $record->eventId,
                    'routing_key' => $record->routingKey,
                    'attempts'    => $record->attempts + 1,
                    'error'       => $e->getMessage(),
                ]);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Long-running loop. Returns when stop signal received (SIGTERM/SIGINT)
     * or when $maxIterations reached (use 0 for unbounded).
     */
    public function run(int $maxIterations = 0): void
    {
        $iter = 0;
        $stop = false;

        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stop): void { $stop = true; });
            pcntl_signal(SIGINT,  function () use (&$stop): void { $stop = true; });
        }

        while (! $stop) {
            $processed = $this->drainOnce();
            $iter++;
            if ($maxIterations > 0 && $iter >= $maxIterations) {
                break;
            }
            if ($processed === 0) {
                sleep($this->config->outboxPollInterval());
            }
        }

        $this->rabbit->close();
    }

    /**
     * Take a batch and lease it, so no other drainer picks the same rows while they are
     * being published.
     *
     * The lease is `next_attempt_at`, the column the fetch already filters on — no new
     * column and no new state. A drainer that dies mid-publish leaves rows leased rather
     * than locked, and they become eligible again when it expires; a lock would have died
     * with the connection, which sounds better until you notice it also means a hung
     * broker holds them for as long as it hangs.
     *
     * `attempts` is deliberately not incremented here. A claim is not an attempt, and
     * burning one on a process that was killed would push events toward `max_attempts`
     * for a reason that has nothing to do with them.
     *
     * @return list<object>
     */
    private function claimBatch(): array
    {
        return (array) $this->connection()->transaction(function (): array {
            $now = date('Y-m-d H:i:s');

            $query = $this->table()
                ->whereNull('processed_at')
                ->where(function (Builder $q) use ($now): void {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
                })
                ->where('attempts', '<', $this->config->outboxMaxAttempts())
                ->orderBy('id')
                ->limit($this->config->outboxBatchSize());

            $this->applyLock($query);

            $rows = $query->get()->all();

            if ($rows === []) {
                return [];
            }

            $this->table()
                ->whereIn('id', array_map(static fn (object $row): int => (int) $row->id, $rows))
                ->update(['next_attempt_at' => date('Y-m-d H:i:s', time() + $this->leaseSeconds())]);

            return $rows;
        });
    }

    /**
     * How long a claimed row stays off-limits to other drainers.
     *
     * Derived from the AMQP timeout rather than configured: the lease only has to outlast
     * a publish, and a publish cannot outlast `read_write_timeout`. Erring short is the
     * safe direction — a lease that expires early costs a duplicate delivery, which
     * `ProcessedEvents` already absorbs, while one that is too long delays recovery after
     * a worker is killed.
     */
    private function leaseSeconds(): int
    {
        return max(30, (int) ceil($this->config->rabbitMqReadWriteTimeout()) * 2);
    }

    /**
     * Keep a concurrent drainer replica off the rows being claimed. Held only for the
     * length of the claim transaction, which does no I/O beyond the database — the
     * publish happens after it commits.
     *
     * `SKIP LOCKED` (opt-in) lets peers move past locked rows on MariaDB 10.6+/MySQL 8/
     * PostgreSQL. SQLite and SQL Server have no row-level locking; there the lease written
     * by the claim is what keeps replicas apart, and a brief overlap costs a duplicate
     * delivery rather than a lost event.
     */
    private function applyLock(Builder $query): void
    {
        $driver = $this->connection()->getDriverName();
        if ($driver === 'sqlite' || $driver === 'sqlsrv') {
            return;
        }

        if ($this->config->outboxSkipLocked()) {
            $query->lock('for update skip locked');
        } else {
            $query->lockForUpdate();
        }
    }

    private function markProcessed(int $id): void
    {
        $this->table()->where('id', $id)->update([
            'processed_at'    => date('Y-m-d H:i:s'),
            'last_error'      => null,
            'next_attempt_at' => null,
        ]);
    }

    private function markFailure(OutboxRecord $record, Throwable $e): void
    {
        $nextAttempts = $record->attempts + 1;
        $backoff      = min(300, 2 ** $nextAttempts);

        $this->table()->where('id', $record->id)->update([
            'attempts'        => $nextAttempts,
            'last_error'      => substr($e->getMessage(), 0, 1000),
            'next_attempt_at' => date('Y-m-d H:i:s', time() + $backoff),
        ]);
    }

    private function table(): Builder
    {
        return $this->connection()->table(OutboxPublisher::TABLE);
    }

    private function connection(): Connection
    {
        return $this->db->connection($this->config->outboxConnection());
    }
}
