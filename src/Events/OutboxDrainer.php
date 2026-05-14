<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Config\AbeonConfig;
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
     */
    public function drainOnce(): int
    {
        $rows = $this->fetchBatch();
        if ($rows === []) {
            return 0;
        }

        $channel  = $this->rabbit->channel();
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
     * @return list<object>
     */
    private function fetchBatch(): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->table()
            ->whereNull('processed_at')
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            })
            ->where('attempts', '<', $this->config->outboxMaxAttempts())
            ->orderBy('id')
            ->limit($this->config->outboxBatchSize())
            ->get()
            ->all();
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
        return $this->db
            ->connection($this->config->outboxConnection())
            ->table(OutboxPublisher::TABLE);
    }
}
