<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Renews an expired access token from the refresh cookie, server-side, single-flighted
 * through a shared cache store (ADR-0023); see docs/notes/token-refresh.md.
 */
final class TokenRefresher
{
    /**
     * How long a completed refresh stays reusable; see docs/notes/token-refresh.md.
     */
    private const RESULT_TTL_SECONDS = 10;

    /**
     * The three timings have to be ordered: call timeout < lock TTL <= wait;
     * see docs/notes/token-refresh.md.
     */
    private const CALL_TIMEOUT_SECONDS = 5;

    private const LOCK_TTL_SECONDS = 15;

    private const LOCK_WAIT_SECONDS = 15;

    /** Stores that cannot be shared between processes, so cannot carry a single-flight. */
    private const PER_PROCESS_STORES = ['array', 'file'];

    public function attempt(mixed $refreshToken): ?RefreshedSession
    {
        if (! is_string($refreshToken) || $refreshToken === '') {
            return null;
        }

        // Keyed by a hash, never by the token: a cache key is a value that turns up in
        // logs, `cache:list` output and debug pages, and this one would be a working
        // credential.
        $key = 'abeon:refresh:'.hash('sha256', $refreshToken);
        $cache = $this->store();

        $existing = $this->cached($cache, $key);
        if ($existing !== null) {
            return $existing;
        }

        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            // A store that cannot lock cannot single-flight. Refusing here would log
            // every user out instead of some of them, so this proceeds and says loudly
            // why concurrent refreshes may start tripping reuse detection.
            Log::error(
                'Token refresh cannot be single-flighted: the configured cache store provides no locks. '
                .'Concurrent refreshes will trip ADR-0023 reuse detection.',
                ['store' => $store::class],
            );

            $refreshed = $this->callAuth($refreshToken);

            if ($refreshed !== null) {
                $cache->put($key, $refreshed->withoutCookies()->toArray(), self::RESULT_TTL_SECONDS);
            }

            return $refreshed;
        }

        $lock = $store->lock($key.':lock', self::LOCK_TTL_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            // Serve whatever the holder produced if it landed; see docs/notes/token-refresh.md.
            usleep(200_000);

            return $this->cached($cache, $key);
        }

        try {
            // Re-check inside the lock: the request we queued behind has almost
            // certainly just written the result we are about to ask Auth for.
            $existing = $this->cached($cache, $key);
            if ($existing !== null) {
                return $existing;
            }

            $refreshed = $this->callAuth($refreshToken);

            if ($refreshed !== null) {
                // `withoutCookies()`, not `toArray()`: the cache must not hold the rotated
                // refresh token (ADR-0023 §2); see docs/notes/token-refresh.md.
                $cache->put($key, $refreshed->withoutCookies()->toArray(), self::RESULT_TTL_SECONDS);
            }

            return $refreshed;
        } finally {
            $lock->release();
        }
    }

    private function store(): CacheRepository
    {
        $name = config('abeon.refresh.lock_store');

        $store = is_string($name) && $name !== ''
            ? Cache::store($name)
            : Cache::store();

        $this->assertStoreIsShareable(is_string($name) && $name !== '' ? $name : (string) config('cache.default'));

        return $store;
    }

    /**
     * A per-process store silently turns the single-flight into no flight control at all;
     * see docs/notes/token-refresh.md.
     */
    private function assertStoreIsShareable(string $name): void
    {
        $driver = (string) config("cache.stores.{$name}.driver", $name);

        if (! in_array($driver, self::PER_PROCESS_STORES, true)) {
            return;
        }

        // Not in tests: the suite runs on `array` deliberately and would drown in this.
        if (app()->runningUnitTests()) {
            return;
        }

        Log::warning(
            'Token refresh is single-flighted through a cache store that cannot be shared between '
            .'processes, so concurrent refreshes across replicas will trip ADR-0023 reuse detection and '
            .'log users out. Set abeon.refresh.lock_store to a shared store (redis).',
            ['store' => $name, 'driver' => $driver],
        );
    }

    private function cached(CacheRepository $cache, string $key): ?RefreshedSession
    {
        $payload = $cache->get($key);

        return is_array($payload) ? RefreshedSession::fromArray($payload) : null;
    }

    private function callAuth(string $refreshToken): ?RefreshedSession
    {
        $url = rtrim((string) config('abeon.auth.url'), '/').'/api/v1/auth/refresh';
        $cookieName = (string) config('abeon.auth.cookies.refresh', 'abeon_refresh');

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Cookie' => $cookieName.'='.$refreshToken,
            ])->timeout(self::CALL_TIMEOUT_SECONDS)->post($url);
        } catch (\Throwable $e) {
            // Auth being unreachable is not the same as the session being over. Say so
            // in the log, because the user-visible outcome — a bounce to login — looks
            // identical to an expired session and would otherwise be misdiagnosed.
            Log::warning('Token refresh could not reach Auth', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $accessToken = $response->json('data.access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        /** @var list<string> $setCookies */
        $setCookies = array_values($response->headers()['Set-Cookie'] ?? []);

        return new RefreshedSession($accessToken, $setCookies, $response->body());
    }
}
