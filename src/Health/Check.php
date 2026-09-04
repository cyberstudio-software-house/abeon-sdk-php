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
     * Execute the check. Implementations:
     *
     *   1. MUST NOT throw — failures are reported via CheckResult::down(...).
     *   2. MUST respect their own timeout budget. The aggregating HealthController
     *      does NOT wrap individual checks in a global timeout — PHP's only
     *      portable timeout primitives are per-resource (socket timeout, PDO
     *      timeout, etc.). A hanging check WILL hang the entire /health/ready
     *      response, which in turn fails K8s readiness probes (default 10s).
     *      Practical budget: <2s per check.
     *   3. SHOULD return latency_ms in the CheckResult to make degradation
     *      visible before it becomes total failure.
     *
     * Built-in checks (DbCheck, RabbitMqCheck, OutboxLagCheck) follow these
     * rules. See ADR notes / docs/usage.md for adding custom checks.
     *
     */
    public function run(): CheckResult;
}
