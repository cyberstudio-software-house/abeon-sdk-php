<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Support;

use Abeon\SDK\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function test_v4_returns_uuid_v4_format(): void
    {
        $uuid = Uuid::v4();
        $this->assertMatchesRegularExpression(Uuid::REGEX, $uuid);
    }

    public function test_v4_produces_distinct_values(): void
    {
        $values = [];
        for ($i = 0; $i < 100; $i++) {
            $values[] = Uuid::v4();
        }
        $this->assertCount(100, array_unique($values));
    }

    public function test_is_valid_accepts_well_formed(): void
    {
        $this->assertTrue(Uuid::isWellFormed('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertTrue(Uuid::isWellFormed('AABBCCDD-EEFF-4001-8123-456789ABCDEF'));
    }

    public function test_is_valid_rejects_malformed(): void
    {
        $this->assertFalse(Uuid::isWellFormed('not-a-uuid'));
        $this->assertFalse(Uuid::isWellFormed(''));
        $this->assertFalse(Uuid::isWellFormed('550e8400-e29b-41d4-a716'));
        // A v3 or v7 is well formed — `isWellFormed()` is the shape check the correlation
        // header needs, and the strict v4 test `isValid()` performed had no production caller.
        $this->assertTrue(Uuid::isWellFormed('550e8400-e29b-31d4-a716-446655440000'));
        $this->assertFalse(Uuid::isWellFormed("550e8400-e29b-41d4-a716-446655440000\r\nSet-Cookie: x=1"));
    }
}
