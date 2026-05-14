<?php

declare(strict_types=1);

namespace Abeon\SDK\Health;

final readonly class CheckResult
{
    public const STATUS_OK       = 'ok';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_DOWN     = 'down';

    public function __construct(
        public string $status,
        public ?int $latencyMs = null,
        public ?string $message = null,
    ) {
    }

    public static function ok(?int $latencyMs = null): self
    {
        return new self(status: self::STATUS_OK, latencyMs: $latencyMs);
    }

    public static function degraded(string $message, ?int $latencyMs = null): self
    {
        return new self(status: self::STATUS_DEGRADED, latencyMs: $latencyMs, message: $message);
    }

    public static function down(string $message, ?int $latencyMs = null): self
    {
        return new self(status: self::STATUS_DOWN, latencyMs: $latencyMs, message: $message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status'     => $this->status,
            'latency_ms' => $this->latencyMs,
            'message'    => $this->message,
        ], fn ($v) => $v !== null);
    }
}
