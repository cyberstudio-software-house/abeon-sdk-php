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
            $oldest = $this->db
                ->connection($this->config->outboxConnection())
                ->table(OutboxPublisher::TABLE)
                ->whereNull('processed_at')
                ->min('created_at');

            $latency = (int) round((microtime(true) - $start) * 1000);

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
            return CheckResult::down($e->getMessage(), (int) round((microtime(true) - $start) * 1000));
        }
    }
}
