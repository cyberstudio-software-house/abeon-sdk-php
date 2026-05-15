<?php

declare(strict_types=1);

namespace Abeon\SDK\Health;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\OutboxPublisher;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Reports `degraded` when the oldest unprocessed outbox row is older than
 * the configured lag threshold. A live OutboxDrainer keeps lag near zero;
 * sustained lag signals a stuck or down drainer.
 */
class OutboxLagCheck implements Check
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly AbeonConfig $config,
    ) {
    }

    public function name(): string
    {
        return 'outbox_lag';
    }

    public function run(): CheckResult
    {
        $start = microtime(true);
        try {
            $connection = $this->db->connection($this->config->outboxConnection());

            // MD-7 fix: graceful degradation when migrations haven't run yet
            // (init container race or fresh deploy). Returning `degraded`
            // instead of `down` lets readiness pass-with-warning rather than
            // bouncing the pod.
            if (! $connection->getSchemaBuilder()->hasTable(OutboxPublisher::TABLE)) {
                return CheckResult::degraded(
                    'Outbox table not yet present — migration pending?',
                    $this->elapsedMs($start),
                );
            }

            $oldest = $connection
                ->table(OutboxPublisher::TABLE)
                ->whereNull('processed_at')
                ->min('created_at');

            $latency = $this->elapsedMs($start);

            if ($oldest === null) {
                return CheckResult::ok($latency);
            }

            $lagSeconds = time() - strtotime((string) $oldest);
            $threshold  = $this->config->outboxLagThreshold();

            if ($lagSeconds > $threshold) {
                return CheckResult::degraded(
                    "Oldest unprocessed outbox event is {$lagSeconds}s old (threshold {$threshold}s).",
                    $latency,
                );
            }

            return CheckResult::ok($latency);
        } catch (Throwable $e) {
            return CheckResult::down($e->getMessage(), $this->elapsedMs($start));
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
