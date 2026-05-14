<?php

declare(strict_types=1);

namespace Abeon\SDK\Health;

interface Check
{
    /**
     * Unique short name (used as key in the /health/ready response).
     */
    public function name(): string;

    /**
     * Execute the check. Implementations must not throw — failures
     * are reported via CheckResult::down(...).
     */
    public function run(): CheckResult;
}
