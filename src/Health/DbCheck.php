<?php

declare(strict_types=1);

namespace Abeon\SDK\Health;

use Illuminate\Database\ConnectionInterface;
use Throwable;

class DbCheck implements Check
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function name(): string
    {
        return 'db';
    }

    public function run(): CheckResult
    {
        $start = microtime(true);
        try {
            $this->connection->select('SELECT 1');

            return CheckResult::ok($this->elapsedMs($start));
        } catch (Throwable $e) {
            return CheckResult::down($e->getMessage(), $this->elapsedMs($start));
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
