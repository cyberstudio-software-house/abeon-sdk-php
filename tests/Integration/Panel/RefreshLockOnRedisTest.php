<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Panel;

use Abeon\SDK\Panel\TokenRefresher;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;

/**
 * The refresh single-flight against a real Redis (ADR-0032).
 *
 * The rest of the suite runs on the `array` store, which is per-process and therefore
 * cannot show the thing that matters here: two workers refreshing the same token trip
 * ADR-0023's reuse detection and log the user out. That is a property of the *store*, so
 * it needs the real one.
 *
 * Skipped when Redis is not reachable, like every other test that needs infrastructure —
 * `./db-up.sh` starts it on 6380.
 */
#[Group('redis')]
final class RefreshLockOnRedisTest extends PanelTestCase
{
    private const TOKEN = 'refresh-token-under-test';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.stores.redis.connection', 'default');
        Config::set('cache.prefix', 'abeon-test-'.getmypid().':');
        Config::set('database.redis.client', 'phpredis');
        // Straight from the environment: `config()` would be the framework's own redis
        // block, which is exactly what this test is overriding.
        Config::set('database.redis.default.host', getenv('REDIS_HOST') ?: '127.0.0.1');
        Config::set('database.redis.default.port', (int) (getenv('REDIS_PORT') ?: 6380));
        Config::set('abeon.refresh.lock_store', 'redis');

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }

        try {
            Cache::store('redis')->put('ping', 1, 5);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not reachable: '.$e->getMessage());
        }

        Cache::store('redis')->clear();
    }

    public function test_the_store_can_lock_so_a_refresh_can_be_single_flighted(): void
    {
        // The branch this guards: without a `LockProvider` the refresher logs an error and
        // refreshes anyway, which is the state the `file` and `array` stores leave it in.
        $this->assertInstanceOf(LockProvider::class, Cache::store('redis')->getStore());
    }

    /**
     * Two processes, one token. The second must wait and read what the first produced —
     * a second call to Auth is the replay ADR-0023 revokes the whole family for.
     */
    public function test_a_second_caller_reuses_the_first_refresh_instead_of_asking_auth_again(): void
    {
        Http::fake(['*/api/v1/auth/refresh' => Http::response([
            'data' => ['access_token' => 'fresh.jwt', 'expires_in' => 900],
        ], 200, ['Set-Cookie' => 'abeon_token=fresh.jwt; Path=/; HttpOnly'])]);

        $first = app(TokenRefresher::class)->attempt(self::TOKEN);
        $second = app(TokenRefresher::class)->attempt(self::TOKEN);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame('fresh.jwt', $second->accessToken);
        Http::assertSentCount(1);
    }

    /**
     * The lock is held in Redis, not in the process, so a second holder is refused while
     * the first still has it. This is what "shared" buys and what `array` cannot do.
     */
    public function test_the_lock_is_visible_to_another_connection(): void
    {
        $key = 'abeon:refresh:'.hash('sha256', self::TOKEN).':lock';

        $store = Cache::store('redis')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);

        $this->assertTrue($store->lock($key, 15)->get());

        // A different owner, as another worker would be.
        $this->assertFalse($store->lock($key, 15, 'another-worker')->get());
    }
}
