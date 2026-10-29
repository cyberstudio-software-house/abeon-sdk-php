<?php

declare(strict_types=1);

namespace Abeon\SDK\Http\Outbound;

/**
 * What came back from a client's address: never the full body, only what a delivery log may show
 * (ADR-0041 §1.5). `error` names why there is no usable answer — refused address, timeout, redirect.
 */
final readonly class OutboundResponse
{
    public const REFUSED = 'refused_address';

    public const UNREACHABLE = 'unreachable';

    public const REDIRECT = 'redirect';

    public function __construct(
        public ?int $status,
        public int $durationMs,
        public string $excerpt = '',
        public ?string $error = null,
    ) {
    }

    public function successful(): bool
    {
        return $this->error === null && $this->status !== null && $this->status >= 200 && $this->status < 300;
    }
}
