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
            // ADR-0001 rule 5: `exp`/`nbf` are checked by firebase/php-jwt against its
            // static leeway, which defaults to 0. Set it here rather than at boot so it
            // holds for every validator, including one constructed directly in a test —
            // a validator whose clock is a second ahead of the issuer would otherwise
            // reject tokens that were just minted.
            JWT::$leeway = $this->config->authLeewaySeconds();

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

            $this->assertClaim($payload, 'aud', $this->config->authAudience());

            // `iss` means different things for the two token kinds, and asserting the
            // platform issuer for both made every real service token invalid.
            //
            // A user token is minted by Auth and carries `iss: "abeon-auth"` (ADR-0001).
            // A service token is **self-signed by the calling service** and carries its
            // own name — `schemas/auth/jwt-service.json` types `iss` as "Issuing service
            // name", `ServiceTokenProvider` sets it from the service name, and
            // ADR-0005's validation list deliberately checks `aud`, `type` and `exp`
            // but not `iss`. Requiring `abeon-auth` here meant the SDK could mint
            // service tokens that the SDK could never accept.
            if (($payload['type'] ?? null) === 'service') {
                // Not unchecked, though: `iss` must agree with `service_name`, so a
                // token cannot claim to come from one service while identifying as
                // another. Which services may call a route stays per-route policy.
                $this->assertClaim($payload, 'iss', (string) ($payload['service_name'] ?? ''));
            } else {
                $this->assertClaim($payload, 'iss', $this->config->authIssuer());
            }

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

        // ADR-0016: org_id is a required, non-null claim on user tokens — it is the
        // authorization and data-scoping dimension, not a display field. Rejecting
        // here keeps the failure at the trust boundary; letting it through as null
        // would push a missing tenant deep into query scoping, where "no tenant" is
        // one mistake away from "every tenant" (ADR-0018).
        if (! isset($claims['org_id']) || ! is_int($claims['org_id'])) {
            throw AuthException::unauthenticated('User JWT is missing the required org_id claim');
        }

        return User::fromArray([
            'id'          => (string) ($claims['sub'] ?? ''),
            'email'       => (string) ($claims['email'] ?? ''),
            'name'        => $claims['name'] ?? null,
            'roles'       => $claims['roles'] ?? [],
            'permissions' => $claims['permissions'] ?? [],
            'org_id'      => $claims['org_id'],
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
