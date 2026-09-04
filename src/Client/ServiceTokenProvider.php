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
 * Octane / Swoole compatibility:
 *   - Cache is intentionally process-wide so worker reuse avoids re-signing
 *     on every request. Worker boundary = token rotation boundary.
 *   - On 401 from any downstream, `ServiceClient` calls `flush()` so the
 *     stale token is dropped.
 *   - Process restart (deploy / OOM / scale event) naturally rotates the
 *     cached token within at most TTL seconds.
 *
 * Multi-tenancy (ADR-0005 as amended by ADR-0016): a token may carry `org_id`
 * when the service acts on behalf of an organisation. **The cache is therefore
 * keyed by organisation.** A single process-wide slot would hand one
 * organisation's token to another organisation's request — a same-service,
 * wrong-tenant call that the callee's `AuthMiddleware` would happily accept.
 */
class ServiceTokenProvider
{
    /** @var array<string, array{token: string, expires_at: int}> keyed `org:{id}`, or `org:none` */
    private array $cached = [];

    public function __construct(
        private readonly AbeonConfig $config,
        private readonly int $ttlSeconds = 300,
        private readonly int $refreshMarginSeconds = 30,
    ) {
    }

    /**
     * @param  int|null  $orgId  Organisation this call acts on behalf of (ADR-0005).
     *                           Null = genuinely organisation-less work: registry
     *                           self-registration, health probes, scheduled
     *                           maintenance. Null means "no organisation", never
     *                           "all organisations".
     */
    public function token(?int $orgId = null): string
    {
        $now = time();
        // Prefixed so the key stays a string — PHP silently casts numeric-string
        // array keys to int, which would make the type of this cache inconsistent.
        $key = $orgId === null ? 'org:none' : 'org:'.$orgId;

        if (isset($this->cached[$key]) && $now < $this->cached[$key]['expires_at'] - $this->refreshMarginSeconds) {
            return $this->cached[$key]['token'];
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

        if ($orgId !== null) {
            $payload['org_id'] = $orgId;
        }

        $token = JWT::encode(
            $payload,
            $this->config->serviceJwtPrivateKey(),
            'RS256',
            $this->config->serviceJwtKid(),
        );

        $this->cached[$key] = ['token' => $token, 'expires_at' => $expiresAt];

        return $token;
    }

    /**
     * Drop every cached token, for all organisations.
     *
     * Called by `ServiceClient` on a 401 from any downstream. Deliberately global:
     * a 401 usually means key rotation, which invalidates every organisation's
     * token, and dropping too much only costs a re-sign.
     */
    public function flush(): void
    {
        $this->cached = [];
    }

}
