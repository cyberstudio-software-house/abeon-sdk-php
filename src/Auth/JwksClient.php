<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\Exceptions\AuthException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

class JwksClient
{
    public const CACHE_KEY = 'abeon.jwks';

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
        return $this->cache->remember(self::CACHE_KEY, $this->ttlSeconds, function () {
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
     * @return array<string, mixed>|null
     */
    public function findKey(string $kid): ?array
    {
        return $this->keys()[$kid] ?? null;
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
