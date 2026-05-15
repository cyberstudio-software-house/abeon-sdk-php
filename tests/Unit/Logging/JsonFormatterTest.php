<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Logging;

use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Logging\JsonFormatter;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    private function record(array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable('2026-05-15T10:00:00Z'),
            channel: 'app',
            level: Level::Info,
            message: 'something happened',
            context: $context,
            extra: $extra,
        );
    }

    public function test_injects_correlation_id_and_service(): void
    {
        $ctx = new CorrelationContext();
        $ctx->set('cid-123');
        $formatter = new JsonFormatter($ctx, 'crm');

        $output  = $formatter->format($this->record());
        $decoded = json_decode(trim($output), true);

        $this->assertSame('cid-123', $decoded['extra']['correlation_id']);
        $this->assertSame('crm', $decoded['extra']['service']);
        $this->assertSame('something happened', $decoded['message']);
    }

    public function test_omits_correlation_id_when_unset(): void
    {
        $formatter = new JsonFormatter(new CorrelationContext(), 'crm');

        $output  = $formatter->format($this->record());
        $decoded = json_decode(trim($output), true);

        $this->assertArrayNotHasKey('correlation_id', $decoded['extra'] ?? []);
        $this->assertSame('crm', $decoded['extra']['service']);
    }

    public function test_preserves_pre_existing_extra_keys(): void
    {
        $ctx = new CorrelationContext();
        $ctx->set('cid-123');
        $formatter = new JsonFormatter($ctx, 'crm');

        $output  = $formatter->format($this->record(extra: ['custom_field' => 'value']));
        $decoded = json_decode(trim($output), true);

        $this->assertSame('value', $decoded['extra']['custom_field']);
        $this->assertSame('cid-123', $decoded['extra']['correlation_id']);
    }

    public function test_custom_correlation_field_name(): void
    {
        $ctx = new CorrelationContext();
        $ctx->set('cid-xyz');
        $formatter = new JsonFormatter($ctx, 'crm', correlationField: 'trace_id');

        $output  = $formatter->format($this->record());
        $decoded = json_decode(trim($output), true);

        $this->assertSame('cid-xyz', $decoded['extra']['trace_id']);
        $this->assertArrayNotHasKey('correlation_id', $decoded['extra'] ?? []);
    }

    public function test_does_not_overwrite_explicit_extra_correlation(): void
    {
        $ctx = new CorrelationContext();
        $ctx->set('cid-from-ctx');
        $formatter = new JsonFormatter($ctx, 'crm');

        $output  = $formatter->format($this->record(extra: ['correlation_id' => 'explicit']));
        $decoded = json_decode(trim($output), true);

        $this->assertSame('explicit', $decoded['extra']['correlation_id']);
    }

    public function test_output_is_newline_terminated_json(): void
    {
        $formatter = new JsonFormatter(new CorrelationContext(), 'crm');

        $output = $formatter->format($this->record());

        $this->assertStringEndsWith("\n", $output);
        $this->assertNotNull(json_decode(trim($output), true));
    }
}
