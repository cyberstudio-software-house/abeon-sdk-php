<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * `OutboxDrainer` has supported `FOR UPDATE SKIP LOCKED` since 0.1, behind
 * `abeon.events.outbox.skip_locked` — a key the published config file never defined, so
 * no service could turn it on without writing its own config. Now that every service
 * runs on MariaDB, the option finally has an engine to act on.
 */
final class OutboxSkipLockedConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('ABEON_OUTBOX_SKIP_LOCKED');

        parent::tearDown();
    }

    public function test_it_is_off_unless_asked_for(): void
    {
        putenv('ABEON_OUTBOX_SKIP_LOCKED');

        $this->assertFalse($this->configFile()['events']['outbox']['skip_locked']);
    }

    public function test_the_environment_turns_it_on(): void
    {
        putenv('ABEON_OUTBOX_SKIP_LOCKED=true');

        $this->assertTrue($this->configFile()['events']['outbox']['skip_locked']);
    }

    /** @return array<string, mixed> */
    private function configFile(): array
    {
        return require dirname(__DIR__, 3).'/config/abeon.php';
    }
}
