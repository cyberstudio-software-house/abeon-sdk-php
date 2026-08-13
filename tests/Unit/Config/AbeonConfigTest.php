<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Config;

use Abeon\SDK\Config\AbeonConfig;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

/**
 * Covers the two timing knobs that key rotation and token validation depend on.
 *
 * Both were previously unreachable: the leeway did not exist (so `JWT::$leeway`
 * stayed at 0 platform-wide) and the JWKS cache TTL was a constructor default that
 * operations could not change. See ADR-0001 rule 5 and ADR-0005 as amended by
 * ADR-0025.
 */
final class AbeonConfigTest extends TestCase
{
    public function test_leeway_defaults_to_sixty_seconds(): void
    {
        $this->assertSame(60, $this->config([])->authLeewaySeconds());
    }

    public function test_leeway_is_configurable_including_zero(): void
    {
        $this->assertSame(5, $this->config(['leeway' => 5])->authLeewaySeconds());
        $this->assertSame(0, $this->config(['leeway' => 0])->authLeewaySeconds());
    }

    public function test_jwks_cache_ttl_defaults_to_one_hour(): void
    {
        $this->assertSame(3600, $this->config([])->authJwksCacheTtl());
    }

    public function test_jwks_cache_ttl_is_configurable(): void
    {
        // Shortening the TTL is how an operator narrows the window in which a retired
        // `kid` stays usable, without a code change.
        $this->assertSame(120, $this->config(['jwks_cache_ttl' => 120])->authJwksCacheTtl());
    }

    /**
     * @param  array<string, mixed>  $auth
     */
    private function config(array $auth): AbeonConfig
    {
        return new AbeonConfig(new Repository(['abeon' => ['auth' => $auth]]));
    }
}
