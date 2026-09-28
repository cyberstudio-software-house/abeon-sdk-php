<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\Exceptions\AuthException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

class JwksClient
{
    public const CACHE_KEY = 'abeon.jwks';

    /**
     * Marker that a refetch has just happened, and how long it suppresses the next one.
     *
     * `JwtValidator` flushes on an unknown `kid`, because the usual reason for one is a
     * rotation this process has not seen yet. A `kid` is part of the token, so anybody can
     * send an unknown one — and without a cooldown each such request threw away the cached
     * key set and fetched it again. A few hundred tokens a second with random `kid`s turn
     * into a few hundred requests a second against Auth's JWKS endpoint, from every service
     * at once, while every honest request in those processes waits on the same fetch.
     *
     * Inside the window the caller keeps the key set it already had, which is the set a
     * genuinely unknown `kid` is absent from anyway. A real rotation is picked up one
     * cooldown later instead of immediately.
     */
    public const COOLDOWN_KEY = 'abeon.jwks.refreshed';

    public const REFRESH_COOLDOWN_SECONDS = 10;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly string $jwksUrl,
        private readonly int $ttlSeconds = 3600,
    ) {
    }

    /**
     * @return array<string, array<string, mixed>> keyed by `kid`
     */
    public function keys(): array
    {
        return $this->cache->remember(self::CACHE_KEY, $this->jitteredTtl(), function () {
            $response = $this->http->get($this->jwksUrl);
            if (! $response->successful()) {
                throw AuthException::unauthenticated('Could not fetch JWKS from '.$this->jwksUrl);
            }

            $body = $response->json();
            if (! is_array($body) || ! isset($body['keys']) || ! is_array($body['keys'])) {
                throw AuthException::unauthenticated('Malformed JWKS response');
            }

            $keys = [];
            foreach ($body['keys'] as $key) {
                if (is_array($key) && isset($key['kid']) && is_string($key['kid'])) {
                    $keys[$key['kid']] = $key;
                }
            }

            return $keys;
        });
    }

    /**
     * The configured TTL, spread by up to ±10%.
     *
     * Without this every process that started around the same time expires its key set
     * at the same moment, and they all go to Auth at once. There is one source, and on
     * this platform it is the service that also sits on the login path — and which is
     * itself waiting on Unified for the app catalogue while it answers (ADR-0019 records
     * that cycle). Sixteen services times their replicas, synchronised on the hour, is a
     * thundering herd aimed at the one endpoint that must not be slow.
     *
     * The spread is per store, not per call: `remember()` only sets a TTL when it writes,
     * so each process picks its own expiry once and they drift apart from there.
     */
    private function jitteredTtl(): int
    {
        if ($this->ttlSeconds < 10) {
            // Too short to spread meaningfully, and in tests a jittered value would just
            // make assertions unstable for no benefit.
            return $this->ttlSeconds;
        }

        $spread = (int) round($this->ttlSeconds * 0.1);

        return $this->ttlSeconds + random_int(-$spread, $spread);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findKey(string $kid): ?array
    {
        return $this->keys()[$kid] ?? null;
    }

    public function flush(): void
    {
        // `add()` rather than a read followed by a write: it is atomic in every store that
        // matters here, so of the requests that arrive together exactly one refetches.
        if (! $this->cache->add(self::COOLDOWN_KEY, true, self::REFRESH_COOLDOWN_SECONDS)) {
            return;
        }

        $this->cache->forget(self::CACHE_KEY);
    }
}
