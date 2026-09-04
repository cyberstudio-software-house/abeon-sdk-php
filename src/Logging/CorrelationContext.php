<?php

declare(strict_types=1);

namespace Abeon\SDK\Logging;

use Abeon\SDK\Support\Uuid;

/**
 * Request-scoped holder for the inbound `X-Correlation-ID`.
 *
 * Lifetime: bound via `$app->scoped()` in AbeonServiceProvider, which means
 * a fresh instance per HTTP request in php-fpm.
 *
 * Octane / Swoole / RoadRunner compatibility:
 *   - `$app->scoped()` is reset between requests by Octane's flush hook —
 *     verified safe under Octane 2.x.
 *   - For other long-lived workers (custom Swoole servers), the host MUST
 *     call `clear()` between requests; otherwise correlation IDs leak.
 *   - `EventConsumer` calls `clear()` after every message (try/finally in
 *     onMessage()) so RabbitMQ workers are safe regardless of runtime.
 */
class CorrelationContext
{
    private ?string $correlationId = null;

    public function set(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function current(): ?string
    {
        return $this->correlationId;
    }

    public function clear(): void
    {
        $this->correlationId = null;
    }

    /**
     * Return the current correlation ID, generating a fresh one if none is set.
     */
    public function ensure(): string
    {
        if ($this->correlationId === null) {
            $this->correlationId = $this->generate();
        }

        return $this->correlationId;
    }

    /**
     * Generate a UUIDv4.
     */
    public function generate(): string
    {
        return Uuid::v4();
    }
}
