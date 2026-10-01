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
    /**
     * JWK member naming the party a key belongs to.
     * Safe as a non-standard member (RFC 7517 §4) — see docs/notes/jwt-validation.md.
     */
    public const JWK_OWNER = 'abeon_owner';

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
            // ADR-0001 rule 5: firebase/php-jwt's static leeway defaults to 0, and it is
            // set here so it holds for every validator. See docs/notes/jwt-validation.md.
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
            $this->assertLifetime($payload);
            $this->assertKeyOwnsIdentity($jwk, $kid, $payload);

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

        // ADR-0016: `org_id` is required and non-null on user tokens, and the refusal
        // belongs at the trust boundary. See docs/notes/jwt-validation.md.
        if (! isset($claims['org_id']) || ! is_int($claims['org_id'])) {
            throw AuthException::unauthenticated('User JWT is missing the required org_id claim');
        }

        // ADR-0031 §4: an instance serves one organisation, and the audience is shared by
        // every service (ADR-0001). See docs/notes/jwt-validation.md.
        $instanceOrgId = $this->config->instanceOrgId();
        if ($instanceOrgId !== null && $claims['org_id'] !== $instanceOrgId) {
            throw AuthException::wrongOrganisation($claims['org_id'], $instanceOrgId);
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

    /**
     * A token must say when it stops being valid, and must not claim to be valid for
     * an implausible span (ADR-0005). See docs/notes/jwt-validation.md.
     *
     * @param  array<string, mixed>  $claims
     */
    private function assertLifetime(array $claims): void
    {
        if (! isset($claims['exp']) || ! is_numeric($claims['exp'])) {
            throw AuthException::unauthenticated('Token has no expiry');
        }

        $ceiling = $this->config->authMaxTokenLifetime();
        $issued  = isset($claims['iat']) && is_numeric($claims['iat']) ? (int) $claims['iat'] : time();

        if ((int) $claims['exp'] - $issued > $ceiling) {
            throw AuthException::unauthenticated("Token lifetime exceeds the {$ceiling}s ceiling");
        }
    }

    /**
     * The key that signed the token must belong to the party the token claims to be.
     * Fail-closed: a key that does not record its owner is refused (FR-27).
     * See docs/notes/jwt-validation.md.
     *
     * @param  array<string, mixed>  $jwk
     * @param  array<string, mixed>  $claims
     */
    private function assertKeyOwnsIdentity(array $jwk, string $kid, array $claims): void
    {
        $owner = $jwk[self::JWK_OWNER] ?? null;

        if (! is_string($owner) || $owner === '') {
            throw AuthException::unauthenticated(
                "Signing key {$kid} does not record which party owns it, so no identity can be trusted to it",
            );
        }

        if (($claims['type'] ?? null) === 'service') {
            // A service token is self-signed, so its claimed name has to match the key
            // it was signed with. `iss` is still checked against `service_name` to keep
            // the two fields of the token consistent with each other.
            $serviceName = $claims['service_name'] ?? null;

            // Checked here rather than in `ServiceAuthMiddleware`, because `decode()` is
            // public and usable without it. See docs/notes/jwt-validation.md.
            if (! is_string($serviceName) || $serviceName === '') {
                throw AuthException::unauthenticated('Service JWT is missing service_name');
            }

            $this->assertClaim($claims, 'iss', $serviceName);

            if ($owner !== $serviceName) {
                throw AuthException::unauthenticated(
                    "Signing key {$kid} belongs to '{$owner}', but the token claims to come from '{$serviceName}'",
                );
            }

            return;
        }

        // User tokens are minted by Auth alone. No other service's key may sign one,
        // whatever `iss` says.
        $this->assertClaim($claims, 'iss', $this->config->authIssuer());

        if ($owner !== $this->config->authIssuer()) {
            throw AuthException::unauthenticated(
                "Signing key {$kid} belongs to '{$owner}' and may not sign user tokens",
            );
        }
    }
}
