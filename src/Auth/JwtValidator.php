<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

class JwtValidator
{
    public function __construct(
        private readonly JwksClient $jwks,
        private readonly AbeonConfig $config,
    ) {
    }

    /**
     * Decode and validate a JWT. Returns claims as array.
     *
     * @return array<string, mixed>
     *
     * @throws AuthException
     */
    public function decode(string $token): array
    {
        try {
            $kid = $this->kid($token);
            $jwk = $this->jwks->findKey($kid);
            if ($jwk === null) {
                // Possible key rotation — flush cache and try once more.
                $this->jwks->flush();
                $jwk = $this->jwks->findKey($kid);
            }
            if ($jwk === null) {
                throw AuthException::unauthenticated("Unknown JWT signing key: {$kid}");
            }

            $key     = JWK::parseKey($jwk);
            $payload = (array) JWT::decode($token, $key);

            $this->assertClaim($payload, 'iss', $this->config->authIssuer());
            $this->assertClaim($payload, 'aud', $this->config->authAudience());

            return $payload;
        } catch (AuthException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw AuthException::unauthenticated($e->getMessage());
        }
    }

    /**
     * Decode a JWT into a User DTO. Asserts `type` is `user`.
     */
    public function decodeUser(string $token): User
    {
        $claims = $this->decode($token);

        if (($claims['type'] ?? null) !== 'user') {
            throw AuthException::unauthenticated('Expected user-type JWT');
        }

        return User::fromArray([
            'id'          => (string) ($claims['sub'] ?? ''),
            'email'       => (string) ($claims['email'] ?? ''),
            'name'        => $claims['name'] ?? null,
            'roles'       => $claims['roles'] ?? [],
            'permissions' => $claims['permissions'] ?? [],
            'org_id'      => $claims['org_id'] ?? null,
        ]);
    }

    private function kid(string $token): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw AuthException::unauthenticated('Malformed JWT');
        }

        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($decoded === false) {
            throw AuthException::unauthenticated('Malformed JWT header encoding');
        }

        $header = json_decode($decoded, true);
        if (! is_array($header) || ! isset($header['kid']) || ! is_string($header['kid']) || $header['kid'] === '') {
            throw AuthException::unauthenticated('Missing kid in JWT header');
        }

        return $header['kid'];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertClaim(array $claims, string $name, string $expected): void
    {
        if (($claims[$name] ?? null) !== $expected) {
            throw AuthException::unauthenticated("Invalid claim {$name}");
        }
    }
}
