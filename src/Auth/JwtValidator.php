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
     *
     * RFC 7517 §4 allows additional members and requires implementations to ignore
     * ones they do not recognise, so this travels safely to standard JWT libraries
     * that have no idea what it means. For this platform it is the difference between
     * "a valid signature" and "a valid signature *from the party that claims it*".
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

    /**
     * A token must say when it stops being valid, and must not claim to be valid for
     * an implausible span.
     *
     * `firebase/php-jwt` checks `exp` only when it is present, so a token minted
     * without one never expires. ADR-0005 bounds a key compromise by the token's
     * lifetime — a guarantee that only holds if the lifetime exists and is short.
     * The ceiling is generous on purpose: it is a backstop against an eternal token,
     * not a second TTL policy.
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
     *
     * This is the check whose absence made JWKS aggregation dangerous. `iss` and
     * `service_name` are both fields of the token, written by whoever signed it, so
     * comparing them to each other proves internal consistency and **nothing about
     * identity**. Once `/.well-known/jwks.json` publishes every service's key (FR-27),
     * a flat "is this signature valid against any published key" check means any
     * service's private key can mint a token for any other service — and, worse, a
     * *user* token for any user in any organisation, because user tokens were only
     * ever checked for `iss: abeon-auth`, which the signer also controls.
     *
     * So identity comes from the key, and the key's owner comes from a place the
     * signer does not control: for Auth's own keys, the row in `signing_keys`; for
     * everyone else, the ConfigMap key name that operations chose when mounting it.
     *
     * A key that does not say who owns it is refused rather than trusted. That is
     * fail-closed, and it means a JWKS from before this check cannot be used to
     * authenticate anything.
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

            // Checked here rather than left to `ServiceAuthMiddleware`, which is where it
            // used to live. `decode()` is public and is the only part of this contract a
            // service can use *without* that middleware — in a consumer, a console
            // command, or its own middleware, which is exactly the case the middleware
            // exists to make unnecessary. Somebody doing that was entitled to assume a
            // decoded service token names its sender.
            //
            // The cast this replaces was its own small problem: `(string) $claims[...]`
            // on a value from outside our control turns an array into `"Array"` and an
            // integer into its digits, and then compares that.
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
