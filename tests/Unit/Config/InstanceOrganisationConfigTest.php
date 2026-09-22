<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Config;

use Abeon\SDK\Config\AbeonConfig;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `ABEON_ORG_ID` binds an instance of a business application to one organisation
 * (ADR-0031 §4, §6).
 */
final class InstanceOrganisationConfigTest extends TestCase
{
    public function test_unset_means_the_service_serves_every_organisation(): void
    {
        $this->assertNull($this->config(null)->instanceOrgId());
        $this->assertNull($this->config('')->instanceOrgId());
    }

    public function test_the_environment_string_is_read_as_an_integer(): void
    {
        $this->assertSame(12, $this->config('12')->instanceOrgId());
    }

    /**
     * A typo read as "unbound" would open the instance to every organisation with a
     * green healthcheck. It has to fail where someone will see it.
     */
    public function test_a_malformed_value_is_refused_rather_than_ignored(): void
    {
        foreach (['acme', '0', '-3', '1.5'] as $value) {
            try {
                $this->config($value)->instanceOrgId();
                $this->fail("'{$value}' was accepted");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_bound_instance_gets_its_own_queue_prefix(): void
    {
        $this->assertSame('cms', $this->config(null)->consumerQueuePrefix());
        $this->assertSame('cms-org12', $this->config('12')->consumerQueuePrefix());
    }

    public function test_an_explicit_queue_prefix_still_wins(): void
    {
        $config = new AbeonConfig(new Repository(['abeon' => [
            'service' => ['name' => 'cms', 'org_id' => '12'],
            'events'  => ['consumer' => ['queue_prefix' => 'cms-acme']],
        ]]));

        $this->assertSame('cms-acme', $config->consumerQueuePrefix());
    }

    private function config(?string $orgId): AbeonConfig
    {
        return new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => 'cms', 'org_id' => $orgId]]]));
    }
}
