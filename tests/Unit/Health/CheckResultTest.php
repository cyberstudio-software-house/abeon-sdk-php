<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Health;

use Abeon\SDK\Health\CheckResult;
use PHPUnit\Framework\TestCase;

final class CheckResultTest extends TestCase
{
    public function test_ok_factory(): void
    {
        $result = CheckResult::ok(42);
        $this->assertSame(CheckResult::STATUS_OK, $result->status);
        $this->assertSame(42, $result->latencyMs);
        $this->assertNull($result->message);
    }

    public function test_degraded_factory(): void
    {
        $result = CheckResult::degraded('slow', 100);
        $this->assertSame(CheckResult::STATUS_DEGRADED, $result->status);
        $this->assertSame('slow', $result->message);
    }

    public function test_down_factory(): void
    {
        $result = CheckResult::down('connection refused');
        $this->assertSame(CheckResult::STATUS_DOWN, $result->status);
        $this->assertSame('connection refused', $result->message);
        $this->assertNull($result->latencyMs);
    }

    public function test_to_array_filters_nulls(): void
    {
        $this->assertSame(['status' => 'ok'], CheckResult::ok()->toArray());
        $this->assertSame(
            ['status' => 'ok', 'latency_ms' => 5],
            CheckResult::ok(5)->toArray(),
        );
        $this->assertSame(
            ['status' => 'down', 'message' => 'boom'],
            CheckResult::down('boom')->toArray(),
        );
    }
}
