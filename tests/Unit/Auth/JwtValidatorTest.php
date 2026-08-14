<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth;

use Abeon\SDK\Auth\JwksClient;
use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Exceptions\AuthException;
use Firebase\JWT\JWT;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class JwtValidatorTest extends TestCase
{
    private const KID = 'test-kid';

    private string $privateKey;

    /** @var array<string, mixed> */
    private array $jwk;

    private AbeonConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($res === false) {
            $this->markTestSkipped('OpenSSL RSA key generation unavailable');
        }
        openssl_pkey_export($res, $privatePem);
        $this->privateKey = $privatePem;

        $details = openssl_pkey_get_details($res);
        $this->jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => self::KID,
            'n'   => $this->b64u($details['rsa']['n']),
            'e'   => $this->b64u($details['rsa']['e']),
        ];

        $this->config = new AbeonConfig(new Repository([
            'abeon' => ['auth' => ['issuer' => 'abeon-auth', 'audience' => 'abeon']],
        ]));
    }

    protected function tearDown(): void
    {
        // `JWT::$leeway` is a library-global static that `decode()` writes on every
        // call. Restore the library default so a leeway set here cannot leak into
        // another test file's expectations.
        JWT::$leeway = 0;

        parent::tearDown();
    }

    public function test_decodes_a_valid_user_token_into_a_user_dto(): void
    {
        $user = $this->validator()->decodeUser($this->token());

        $this->assertSame('42', $user->id);
        $this->assertSame('alice@example.com', $user->email);
        $this->assertSame('Alice', $user->name);
        $this->assertSame(['admin'], $user->roles);
        $this->assertSame(['crm.contacts.read'], $user->permissions);
        $this->assertSame(7, $user->orgId);
    }

    public function test_decodes_a_service_token_but_rejects_it_as_a_user(): void
    {
        $token = $this->token(['type' => 'service', 'sub' => 'crm', 'service_name' => 'crm', 'iss' => 'crm']);

        $claims = $this->validator()->decode($token);
        $this->assertSame('service', $claims['type']);

        $this->expectException(AuthException::class);
        $this->validator()->decodeUser($token);
    }

    public function test_rejects_an_expired_token(): void
    {
        $token = $this->token(['iat' => time() - 1200, 'exp' => time() - 600]);

        $this->expectException(AuthException::class);
        $this->validator()->decode($token);
    }

    public function test_rejects_a_wrong_issuer(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Invalid claim iss');
        $this->validator()->decode($this->token(['iss' => 'evil-issuer']));
    }

    public function test_rejects_a_wrong_audience(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Invalid claim aud');
        $this->validator()->decode($this->token(['aud' => 'some-other-app']));
    }

    public function test_decode_user_rejects_a_non_user_type(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Expected user-type JWT');
        $this->validator()->decodeUser($this->token([
            'type' => 'service', 'service_name' => 'abeon-auth',
        ]));
    }

    public function test_a_service_token_carries_its_own_issuer(): void
    {
        // ADR-0005: a service token is self-signed by the calling service and its `iss`
        // is that service's name, not the platform issuer. Asserting `abeon-auth` for
        // both kinds made every token `ServiceTokenProvider` mints unacceptable to the
        // SDK that minted it — visible only once a real client called a real service.
        $claims = $this->validator()->decode($this->token([
            'iss' => 'crm', 'type' => 'service', 'sub' => 'crm', 'service_name' => 'crm',
        ]));

        $this->assertSame('crm', $claims['iss']);
    }

    public function test_a_service_token_whose_issuer_disagrees_with_its_name_is_rejected(): void
    {
        // Otherwise a service could sign a token claiming to come from another one.
        $this->expectException(AuthException::class);

        $this->validator()->decode($this->token([
            'iss' => 'crm', 'type' => 'service', 'sub' => 'crm', 'service_name' => 'finance',
        ]));
    }

    public function test_decode_user_rejects_a_token_without_org_id(): void
    {
        // ADR-0016: org_id is the authorization and data-scoping dimension, so a
        // token without it is rejected at the trust boundary. Letting it through as
        // null would push a missing tenant into query scoping, where "no tenant" is
        // one mistake away from "every tenant".
        $claims = $this->claims();
        unset($claims['org_id']);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('missing the required org_id claim');
        $this->validator()->decodeUser($this->tokenFromClaims($claims));
    }

    public function test_decode_user_rejects_a_null_org_id(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('missing the required org_id claim');
        $this->validator()->decodeUser($this->token(['org_id' => null]));
    }

    public function test_rejects_an_algorithm_confusion_token(): void
    {
        // HS256 token carrying the same kid. The validator resolves an RS256 JWK,
        // so a symmetric-algorithm token must never verify (no alg downgrade).
        $hsToken = JWT::encode($this->claims(), 'attacker-chosen-secret', 'HS256', self::KID);

        $this->expectException(AuthException::class);
        $this->validator()->decode($hsToken);
    }

    public function test_rejects_a_garbage_token(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Malformed JWT');
        $this->validator()->decode('this-is-not-a-jwt');
    }

    public function test_rejects_a_token_without_a_kid_header(): void
    {
        $noKid = JWT::encode($this->claims(), $this->privateKey, 'RS256'); // no key id

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Missing kid');
        $this->validator()->decode($noKid);
    }

    public function test_rejects_an_unknown_kid_after_flushing_once(): void
    {
        $jwks = new FakeJwksClient([]); // no keys known
        $validator = new JwtValidator($jwks, $this->config);

        try {
            $validator->decode($this->token());
            $this->fail('Expected AuthException for unknown kid');
        } catch (AuthException $e) {
            $this->assertStringContainsString('Unknown JWT signing key', (string) $e->problem->detail);
        }

        // The validator must flush the JWKS cache exactly once and retry (key-rotation recovery).
        $this->assertSame(1, $jwks->flushCount);
    }

    public function test_recovers_after_a_key_rotation_via_cache_flush(): void
    {
        // Key is only visible after the cache is flushed — models a freshly rotated key.
        $jwks = new FakeJwksClient([self::KID => $this->jwk], availableAfterFlushOnly: true);
        $validator = new JwtValidator($jwks, $this->config);

        $claims = $validator->decode($this->token());

        $this->assertSame('42', $claims['sub']);
        $this->assertSame(1, $jwks->flushCount);
    }

    public function test_tolerates_clock_skew_within_the_configured_leeway(): void
    {
        // Expired 30s ago: an issuer whose clock ran slightly behind this validator.
        // ADR-0001 rule 5 (as amended by ADR-0025) requires 60s of tolerance, without
        // which sixteen independently drifting nodes reject freshly minted tokens.
        $claims = $this->validator()->decode($this->token(['exp' => time() - 30]));

        $this->assertSame('42', $claims['sub']);
    }

    public function test_rejects_a_token_expired_beyond_the_leeway(): void
    {
        $this->expectException(AuthException::class);

        $this->validator()->decode($this->token(['exp' => time() - 120]));
    }

    public function test_leeway_is_configurable_and_zero_disables_tolerance(): void
    {
        $strict = new JwtValidator(
            new FakeJwksClient([self::KID => $this->jwk]),
            new AbeonConfig(new Repository([
                'abeon' => ['auth' => ['issuer' => 'abeon-auth', 'audience' => 'abeon', 'leeway' => 0]],
            ])),
        );

        $this->expectException(AuthException::class);

        $strict->decode($this->token(['exp' => time() - 30]));
    }

    private function validator(): JwtValidator
    {
        return new JwtValidator(new FakeJwksClient([self::KID => $this->jwk]), $this->config);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function token(array $overrides = []): string
    {
        return JWT::encode($this->claims($overrides), $this->privateKey, 'RS256', self::KID);
    }

    /**
     * Sign an exact claim set — for cases that need a claim *absent* rather than
     * overridden, which `claims()`' array_merge cannot express.
     *
     * @param  array<string, mixed>  $claims
     */
    private function tokenFromClaims(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256', self::KID);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = []): array
    {
        return array_merge([
            'iss'         => 'abeon-auth',
            'aud'         => 'abeon',
            'sub'         => '42',
            'type'        => 'user',
            'email'       => 'alice@example.com',
            'name'        => 'Alice',
            'roles'       => ['admin'],
            'permissions' => ['crm.contacts.read'],
            'org_id'      => 7,
            'iat'         => time() - 60,
            'exp'         => time() + 600,
            'jti'         => 'jti-1',
        ], $overrides);
    }

    private function b64u(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}

/**
 * Test double following the package's FakeTokenProvider idiom: skips the real
 * HTTP/cache constructor and serves a fixed key set, counting flushes.
 */
final class FakeJwksClient extends JwksClient
{
    public int $flushCount = 0;

    /**
     * @param  array<string, array<string, mixed>>  $keys  keyed by kid
     */
    public function __construct(
        private array $keys,
        private readonly bool $availableAfterFlushOnly = false,
    ) {
        // Intentionally skip parent constructor — no HTTP/cache in unit tests.
    }

    public function findKey(string $kid): ?array
    {
        if ($this->availableAfterFlushOnly && $this->flushCount === 0) {
            return null;
        }

        return $this->keys[$kid] ?? null;
    }

    public function flush(): void
    {
        $this->flushCount++;
    }
}
