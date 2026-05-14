<?php

declare(strict_types=1);

namespace Abeon\SDK\Health;

use Abeon\SDK\Events\RabbitMq;
use Throwable;

class RabbitMqCheck implements Check
{
    public function __construct(private readonly RabbitMq $rabbit)
    {
    }

    public function name(): string
    {
        return 'rabbitmq';
    }

    public function run(): CheckResult
    {
        $start = microtime(true);
        try {
            $ok = $this->rabbit->ping();
            $latency = (int) round((microtime(true) - $start) * 1000);

            return $ok
                ? CheckResult::ok($latency)
                : CheckResult::down('RabbitMQ ping failed', $latency);
        } catch (Throwable $e) {
            return CheckResult::down($e->getMessage(), (int) round((microtime(true) - $start) * 1000));
        }
    }
}
