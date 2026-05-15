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
