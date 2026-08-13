<?php

declare(strict_types=1);

namespace Abeon\SDK\Config;

use Abeon\SDK\Exceptions\ContractViolationException;
use Illuminate\Contracts\Config\Repository;

class AbeonConfig
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function serviceName(): string
    {
        $name = $this->config->get('abeon.service.name');
        if (! is_string($name) || $name === '') {
            throw new \RuntimeException('abeon.service.name is required (set ABEON_SERVICE_NAME)');
        }

        return $name;
    }

    public function authUrl(): string
    {
        return (string) $this->config->get('abeon.auth.url');
    }

    public function authJwksUrl(): string
    {
        $explicit = $this->config->get('abeon.auth.jwks_url');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return rtrim($this->authUrl(), '/').'/.well-known/jwks.json';
    }

    public function authIssuer(): string
    {
        return (string) $this->config->get('abeon.auth.issuer', 'abeon-auth');
    }

    public function authAudience(): string
    {
        return (string) $this->config->get('abeon.auth.audience', 'abeon');
    }

    /**
     * Clock-skew tolerance when validating `exp` / `nbf`, in seconds (ADR-0001 rule 5).
     *
     * Nodes drift independently, so a validator with zero tolerance rejects tokens
     * that were just minted by an issuer whose clock is a second ahead. Configurable
     * because the right value depends on how well the cluster's clocks are kept.
     */
    public function authLeewaySeconds(): int
    {
        return (int) $this->config->get('abeon.auth.leeway', 60);
    }

    /**
     * How long a consumer caches the JWKS document, in seconds.
     *
     * This is the quantity that binds key rotation: a consumer that never misses its
     * cache keeps a retired `kid` usable for a full TTL, so the grace period before
     * removing a key must cover this plus one access-token lifetime (ADR-0005 as
     * amended by ADR-0025).
     */
    public function authJwksCacheTtl(): int
    {
        return (int) $this->config->get('abeon.auth.jwks_cache_ttl', 3600);
    }

    public function authAccessCookieName(): string
    {
        return (string) $this->config->get('abeon.auth.cookies.access', 'abeon_token');
    }

    public function authRefreshCookieName(): string
    {
        return (string) $this->config->get('abeon.auth.cookies.refresh', 'abeon_refresh');
    }

    public function serviceJwtPrivateKey(): string
    {
        $key = (string) $this->config->get('abeon.auth.service_jwt.private_key', '');
        if ($key === '') {
            throw new \RuntimeException(
                'abeon.auth.service_jwt.private_key is required for outbound service calls (set ABEON_SERVICE_JWT_PRIVATE_KEY).',
            );
        }

        return $key;
    }

    public function serviceJwtKid(): string
    {
        $kid = (string) $this->config->get('abeon.auth.service_jwt.kid', '');
        if ($kid === '') {
            throw new \RuntimeException(
                'abeon.auth.service_jwt.kid is required (set ABEON_SERVICE_JWT_KID).',
            );
        }

        return $kid;
    }

    public function rabbitMqDsn(): ?string
    {
        $dsn = $this->config->get('abeon.events.dsn');

        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }

    public function rabbitMqExchange(): string
    {
        return (string) $this->config->get('abeon.events.exchange', 'abeon.events');
    }

    public function rabbitMqDeadLetterExchange(): string
    {
        return (string) $this->config->get('abeon.events.dlx_exchange', 'abeon.events.dlx');
    }

    public function rabbitMqConnectionTimeout(): float
    {
        return max(0.1, (float) $this->config->get('abeon.events.connection_timeout', 3.0));
    }

    public function rabbitMqReadWriteTimeout(): float
    {
        return max(0.1, (float) $this->config->get('abeon.events.read_write_timeout', 3.0));
    }

    public function rabbitMqHealthProbeTimeout(): float
    {
        return max(0.1, (float) $this->config->get('abeon.events.health_probe_timeout', 2.0));
    }

    public function clientTimeoutSeconds(): float
    {
        return max(0.1, (float) $this->config->get('abeon.client.timeout', 10.0));
    }

    public function clientConnectTimeoutSeconds(): float
    {
        return max(0.1, (float) $this->config->get('abeon.client.connect_timeout', 3.0));
    }

    public function clientMaxRetries(): int
    {
        return max(0, (int) $this->config->get('abeon.client.max_retries', 2));
    }

    public function clientRetryDelayMs(): int
    {
        return max(0, (int) $this->config->get('abeon.client.retry_delay_ms', 500));
    }

    public function outboxConnection(): ?string
    {
        $name = $this->config->get('abeon.events.outbox.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function outboxBatchSize(): int
    {
        return max(1, (int) $this->config->get('abeon.events.outbox.batch_size', 100));
    }

    public function outboxPollInterval(): int
    {
        return max(1, (int) $this->config->get('abeon.events.outbox.poll_interval', 1));
    }

    public function outboxMaxAttempts(): int
    {
        return max(1, (int) $this->config->get('abeon.events.outbox.max_attempts', 5));
    }

    public function outboxLagThreshold(): int
    {
        return max(1, (int) $this->config->get('abeon.events.outbox.lag_threshold', 60));
    }

    /**
     * When true, the drainer claims rows with `FOR UPDATE SKIP LOCKED` so a
     * second replica skips locked rows instead of blocking. Requires a driver
     * that supports it (MariaDB 10.6+/MySQL 8/PostgreSQL); ignored on SQLite.
     */
    public function outboxSkipLocked(): bool
    {
        return (bool) $this->config->get('abeon.events.outbox.skip_locked', false);
    }

    public function consumerQueuePrefix(): string
    {
        $prefix = $this->config->get('abeon.events.consumer.queue_prefix');

        return is_string($prefix) && $prefix !== '' ? $prefix : $this->serviceName();
    }

    /**
     * @return list<string>
     */
    public function consumerSubscriptions(): array
    {
        $subs = $this->config->get('abeon.events.consumer.subscriptions', []);
        if (! is_array($subs)) {
            return [];
        }

        return array_values(array_filter($subs, 'is_string'));
    }

    public function consumerPrefetchCount(): int
    {
        return max(1, (int) $this->config->get('abeon.events.consumer.prefetch_count', 10));
    }

    /**
     * @return list<string>
     */
    public function healthChecks(): array
    {
        $checks = $this->config->get('abeon.health.checks', []);
        if (is_string($checks)) {
            $checks = array_filter(array_map('trim', explode(',', $checks)));
        }
        if (! is_array($checks)) {
            return [];
        }

        return array_values(array_filter($checks, 'is_string'));
    }

    public function correlationField(): string
    {
        return (string) $this->config->get('abeon.logging.correlation_field', 'correlation_id');
    }

    /**
     * @return list<string>
     */
    public function corsAllowedOrigins(): array
    {
        $raw = $this->config->get('abeon.cors.allowed_origins', []);
        if (is_string($raw)) {
            $raw = array_map('trim', explode(',', $raw));
        }
        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_filter(
            $raw,
            fn ($v) => is_string($v) && $v !== '',
        ));
    }

    /**
     * @return array<string, array{url: string}>
     */
    public function services(): array
    {
        $services = $this->config->get('abeon.services', []);

        return is_array($services) ? $services : [];
    }

    public function serviceUrl(string $name): string
    {
        $services = $this->services();
        if (! isset($services[$name]['url']) || ! is_string($services[$name]['url'])) {
            throw ContractViolationException::unknownService($name);
        }

        return $services[$name]['url'];
    }

    /**
     * @return list<string>
     */
    public function declaredPermissions(): array
    {
        $perms = $this->config->get('abeon.permissions', []);
        if (! is_array($perms)) {
            return [];
        }

        return array_values(array_filter($perms, 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    public function appDescriptor(): array
    {
        $descriptor = $this->config->get('abeon.app_descriptor', []);

        return is_array($descriptor) ? $descriptor : [];
    }
}
