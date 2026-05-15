<?php

declare(strict_types=1);

namespace Abeon\SDK\Client;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Support\Uuid;
use Firebase\JWT\JWT;

/**
 * Issues short-lived (5 min default) RS256 service-to-service JWTs.
 *
 * Each call is cached in-memory until the refresh margin is reached.
 * Public key for verification is published via JWKS aggregated by Auth
 * (M7 — K8s label discovery).
 *
 * Lifetime: bound as `$app->singleton()` — process-wide cache.
 *
 * Octane / Swoole compatibility (LO-5):
 *   - Cache is intentionally process-wide so worker reuse avoids re-signing
 *     on every request. Worker boundary = token rotation boundary.
 *   - On 401 from any downstream, `ServiceClient` calls `flush()` so the
 *     stale token is dropped (MD-5 from code review).
 *   - Process restart (deploy / OOM / scale event) naturally rotates the
 *     cached token within at most TTL seconds.
 */
class ServiceTokenProvider
{
    /** @var array{token: string, expires_at: int}|null */
    private ?array $cached = null;

    public function __construct(
        private readonly AbeonConfig $config,
        private readonly int $ttlSeconds = 300,
        private readonly int $refreshMarginSeconds = 30,
    ) {
    }

    public function token(): string
    {
        $now = time();
        if ($this->cached !== null && $now < $this->cached['expires_at'] - $this->refreshMarginSeconds) {
            return $this->cached['token'];
        }

        $serviceName = $this->config->serviceName();
        $expiresAt   = $now + $this->ttlSeconds;

        $payload = [
            'iss'          => $serviceName,
            'sub'          => $serviceName,
            'aud'          => $this->config->authAudience(),
            'type'         => 'service',
            'service_name' => $serviceName,
            'iat'          => $now,
            'exp'          => $expiresAt,
            'jti'          => Uuid::v4(),
        ];

        $token = JWT::encode(
            $payload,
            $this->config->serviceJwtPrivateKey(),
            'RS256',
            $this->config->serviceJwtKid(),
        );

        $this->cached = ['token' => $token, 'expires_at' => $expiresAt];

        return $token;
    }

    public function flush(): void
    {
        $this->cached = null;
    }

}
