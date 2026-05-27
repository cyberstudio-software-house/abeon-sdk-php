<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth;

use Abeon\SDK\Auth\JwksClient;
use Abeon\SDK\Exceptions\AuthException;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;

final class JwksClientTest extends TestCase
{
    private const URL = 'http://auth.test/.well-known/jwks.json';

    private HttpFactory $http;

    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpFactory();
        $this->cache = new CacheRepository(new ArrayStore());
    }

    public function test_finds_a_key_by_kid_and_returns_null_for_unknown(): void
    {
        $this->http->fake(['*' => $this->http->response($this->jwks(), 200)]);
        $client = $this->client();

        $this->assertSame('k1', $client->findKey('k1')['kid'] ?? null);
        $this->assertNull($client->findKey('does-not-exist'));
    }

    public function test_caches_the_key_set_after_the_first_fetch(): void
    {
        $this->http->fake(['*' => $this->http->response($this->jwks(), 200)]);
        $client = $this->client();

        $client->findKey('k1');
        $client->findKey('k2');

        $this->http->assertSentCount(1);
    }

    public function test_flush_forces_a_refetch(): void
    {
        $this->http->fake(['*' => $this->http->response($this->jwks(), 200)]);
        $client = $this->client();

        $client->findKey('k1');
        $client->flush();
        $client->findKey('k1');

        $this->http->assertSentCount(2);
    }

    public function test_throws_on_http_error(): void
    {
        $this->http->fake(['*' => $this->http->response('boom', 500)]);

        $this->expectException(AuthException::class);
        $this->client()->findKey('k1');
    }

    public function test_throws_on_malformed_body(): void
    {
        $this->http->fake(['*' => $this->http->response(['not_keys' => []], 200)]);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Malformed JWKS response');
        $this->client()->findKey('k1');
    }

    private function client(): JwksClient
    {
        return new JwksClient($this->http, $this->cache, self::URL, 3600);
    }

    /**
     * @return array<string, mixed>
     */
    private function jwks(): array
    {
        return [
            'keys' => [
                ['kid' => 'k1', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig'],
                ['kid' => 'k2', 'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig'],
            ],
        ];
    }
}
