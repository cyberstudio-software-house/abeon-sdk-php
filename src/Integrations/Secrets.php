<?php

declare(strict_types=1);

namespace Abeon\SDK\Integrations;

use Abeon\SDK\Client\ServiceCallException;
use Abeon\SDK\Client\ServiceClient;

/**
 * A client's key to an outside service, read from the vault in AbeonUnified (ADR-0038 §2). The
 * organisation and the service are the token's, never a parameter. What was read is kept in this
 * process only, for a few minutes: never in Redis or on disk, where it would outlive a rotation
 * and be readable by anything sharing the store.
 */
final class Secrets
{
    public const TTL = 300;

    /** @var array<string, array{0: array<string, string>|null, 1: int}> */
    private static array $cache = [];

    public function __construct(private readonly ServiceClient $client) {}

    /**
     * @return array<string, string>|null null when no key is stored for this provider
     */
    public function get(string $provider): ?array
    {
        $now = time();
        if (isset(self::$cache[$provider]) && self::$cache[$provider][1] > $now) {
            return self::$cache[$provider][0];
        }

        try {
            /** @var array<string, string> $secrets */
            $secrets = $this->client->service('unified')->get("/api/v1/internal/secrets/{$provider}")->json('data');
        } catch (ServiceCallException $e) {
            if ($e->problem->status !== 404) {
                throw $e;
            }
            $secrets = null;
        }

        self::$cache[$provider] = [$secrets, $now + self::TTL];

        return $secrets;
    }

    /**
     * Tell the vault whether the key worked, for the administrators' screen.
     */
    public function reportTest(string $provider, bool $ok): void
    {
        $this->client->service('unified')->post("/api/v1/internal/secrets/{$provider}/tested", ['ok' => $ok]);
    }

    public static function forget(): void
    {
        self::$cache = [];
    }
}
