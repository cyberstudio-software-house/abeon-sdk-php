<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Logging;

use Abeon\SDK\Logging\CorrelationContext;
use PHPUnit\Framework\TestCase;

final class CorrelationContextTest extends TestCase
{
    public function test_starts_with_no_correlation(): void
    {
        $ctx = new CorrelationContext();
        $this->assertNull($ctx->current());
    }

    public function test_set_and_current(): void
    {
        $ctx = new CorrelationContext();
        $ctx->set('abc-123');
        $this->assertSame('abc-123', $ctx->current());
    }

    public function test_clear_resets(): void
    {
        $ctx = new CorrelationContext();
        $ctx->set('abc');
        $ctx->clear();
        $this->assertNull($ctx->current());
    }

    public function test_ensure_generates_when_missing(): void
    {
        $ctx = new CorrelationContext();
        $id  = $ctx->ensure();
        $this->assertSame($id, $ctx->current());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
    }

    public function test_ensure_returns_existing(): void
    {
        $ctx = new CorrelationContext();
        $ctx->set('existing-id');
        $this->assertSame('existing-id', $ctx->ensure());
    }

    public function test_generate_returns_uuid_v4_format(): void
    {
        $ctx = new CorrelationContext();
        $uuid = $ctx->generate();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid,
        );
    }

    public function test_generate_produces_distinct_values(): void
    {
        $ctx = new CorrelationContext();
        $a   = $ctx->generate();
        $b   = $ctx->generate();
        $this->assertNotSame($a, $b);
    }
}
