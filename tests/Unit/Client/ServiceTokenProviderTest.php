<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Client;

use Abeon\SDK\Client\ServiceTokenProvider;
use Abeon\SDK\Config\AbeonConfig;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * The provider caches signed tokens process-wide. Under multi-tenancy that cache
 * MUST be keyed by organisation (ADR-0005 as amended by ADR-0016): a single slot
 * would hand one organisation's token to another organisation's request, and the
 * callee's AuthMiddleware would accept it — a same-service, wrong-tenant call.
 */
final class ServiceTokenProviderTest extends TestCase
{
    private string $privateKey;

    private string $publicKey;

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
        $this->privateKey = (string) $privatePem;

        $details = openssl_pkey_get_details($res);
        $this->publicKey = (string) ($details['key'] ?? '');
    }

    private function provider(): ServiceTokenProvider
    {
        return new ServiceTokenProvider(new AbeonConfig(new Repository([
            'abeon' => [
                'service' => ['name' => 'crm'],
                'auth'    => [
                    'audience'    => 'abeon',
                    'service_jwt' => [
                        'private_key' => $this->privateKey,
                        'kid'         => 'test-kid',
                    ],
                ],
            ],
        ])));
    }

    /** @return array<string, mixed> */
    private function decode(string $token): array
    {
        return (array) JWT::decode($token, new Key($this->publicKey, 'RS256'));
    }

    public function test_organisation_less_token_carries_no_org_id(): void
    {
        $claims = $this->decode($this->provider()->token());

        $this->assertSame('service', $claims['type']);
        $this->assertSame('crm', $claims['service_name']);
        $this->assertArrayNotHasKey(
            'org_id',
            $claims,
            'Absent means "no organisation" — never "all organisations".',
        );
    }

    public function test_token_carries_the_requested_organisation(): void
    {
        $claims = $this->decode($this->provider()->token(7));

        $this->assertSame(7, $claims['org_id']);
    }

    public function test_each_organisation_gets_its_own_token(): void
    {
        $provider = $this->provider();

        $this->assertSame(7, $this->decode($provider->token(7))['org_id']);
        $this->assertSame(9, $this->decode($provider->token(9))['org_id']);

        // ...and organisation 7 is still 7 after 9 was minted — i.e. the second
        // call did not overwrite a single shared slot.
        $this->assertSame(7, $this->decode($provider->token(7))['org_id']);
    }

    public function test_the_organisation_less_token_is_its_own_cache_entry(): void
    {
        $provider = $this->provider();

        $provider->token(7);

        $this->assertArrayNotHasKey('org_id', $this->decode($provider->token()));
    }

    public function test_repeated_calls_for_one_organisation_reuse_the_cached_token(): void
    {
        $provider = $this->provider();

        $this->assertSame($provider->token(7), $provider->token(7));
    }

    public function test_flush_clears_every_organisation(): void
    {
        $provider = $this->provider();

        $before = $provider->token(7);
        $provider->flush();
        $after = $provider->token(7);

        // A 401 usually means key rotation, which invalidates every organisation's
        // token — dropping too much only costs a re-sign.
        $this->assertNotSame($before, $after);
    }
}
