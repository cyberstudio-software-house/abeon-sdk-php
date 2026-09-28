<?php

declare(strict_types=1);

namespace Abeon\SDK\Health;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\OutboxPublisher;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Reports `degraded` when the oldest unprocessed outbox row is older than the configured
 * lag threshold. A live OutboxDrainer keeps lag near zero; sustained lag signals a stuck or
 * down drainer.
 *
 * Rows that exhausted `max_attempts` are counted separately rather than as lag: the drainer
 * stops fetching them but leaves `processed_at` null, so measuring lag from the oldest
 * unprocessed row made one permanently failed event a permanent `degraded` — and a probe
 * that never goes back to `ok` cannot report anything later.
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

            // Degrade rather than fail when migrations have not run yet
            // (init container race or fresh deploy). Returning `degraded`
            // instead of `down` lets readiness pass-with-warning rather than
            // bouncing the pod.
            if (! $connection->getSchemaBuilder()->hasTable(OutboxPublisher::TABLE)) {
                return CheckResult::degraded(
                    'Outbox table not yet present — migration pending?',
                    $this->elapsedMs($start),
                );
            }

            $maxAttempts = $this->config->outboxMaxAttempts();

            // **Rows that gave up are not lag.** `OutboxDrainer` stops fetching a row once
            // `attempts` reaches the ceiling, and leaves `processed_at` null — which is what
            // keeps the evidence. Counting those as lag meant the first permanently failed
            // event pinned this check at `degraded` for ever, and a probe that is always
            // degraded reports nothing at all: the stuck drainer it exists to catch arrives
            // to a light that was already on.
            $oldest = $connection
                ->table(OutboxPublisher::TABLE)
                ->whereNull('processed_at')
                ->where('attempts', '<', $maxAttempts)
                ->min('created_at');

            $abandoned = $connection
                ->table(OutboxPublisher::TABLE)
                ->whereNull('processed_at')
                ->where('attempts', '>=', $maxAttempts)
                ->count();

            $latency = $this->elapsedMs($start);
            $threshold = $this->config->outboxLagThreshold();
            $lagSeconds = $oldest === null ? 0 : time() - strtotime((string) $oldest);

            if ($lagSeconds > $threshold) {
                return CheckResult::degraded(
                    "Oldest unprocessed outbox event is {$lagSeconds}s old (threshold {$threshold}s).",
                    $latency,
                );
            }

            // Still `degraded`, and said in its own words: an event nobody will publish now
            // is a write whose consequences never left this service, and it needs a person.
            if ($abandoned > 0) {
                return CheckResult::degraded(
                    "{$abandoned} outbox event(s) gave up after {$maxAttempts} attempts.",
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
