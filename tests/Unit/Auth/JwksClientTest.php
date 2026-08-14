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

    public function test_the_cache_ttl_is_spread_so_replicas_do_not_expire_together(): void
    {
        // There is one source of keys, and on this platform it is the service that also
        // sits on the login path — and which is itself waiting on Unified for the app
        // catalogue while it answers. Every process expiring on the same second sends
        // sixteen services' worth of replicas at it simultaneously.
        $this->http->fake(['*' => $this->http->response($this->jwks(), 200)]);

        $cache = new class(new ArrayStore()) extends CacheRepository {
            /** @var list<int> */
            public array $ttls = [];

            public function remember($key, $ttl, \Closure $callback): mixed
            {
                $this->ttls[] = (int) $ttl;

                return parent::remember($key, $ttl, $callback);
            }
        };

        for ($i = 0; $i < 40; $i++) {
            $cache->forget(JwksClient::CACHE_KEY);
            (new JwksClient($this->http, $cache, self::URL, 3600))->keys();
        }

        $this->assertGreaterThan(1, count(array_unique($cache->ttls)), 'Every process would expire together.');

        foreach ($cache->ttls as $ttl) {
            // Spread, not randomised into uselessness — a key set living for an
            // arbitrary length of time would make a rotation window unpredictable.
            $this->assertGreaterThanOrEqual(3240, $ttl);
            $this->assertLessThanOrEqual(3960, $ttl);
        }
    }

    public function test_a_short_ttl_is_left_alone(): void
    {
        // Below ten seconds the spread would be rounding noise, and it would make any
        // test that pins a TTL flap for no benefit.
        $this->http->fake(['*' => $this->http->response($this->jwks(), 200)]);

        $cache = new class(new ArrayStore()) extends CacheRepository {
            /** @var list<int> */
            public array $ttls = [];

            public function remember($key, $ttl, \Closure $callback): mixed
            {
                $this->ttls[] = (int) $ttl;

                return parent::remember($key, $ttl, $callback);
            }
        };

        (new JwksClient($this->http, $cache, self::URL, 5))->keys();

        $this->assertSame([5], $cache->ttls);
    }
}
