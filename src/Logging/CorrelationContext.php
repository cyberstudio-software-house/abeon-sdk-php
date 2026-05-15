<?php

declare(strict_types=1);

namespace Abeon\SDK\Logging;

use Abeon\SDK\Support\Uuid;

class CorrelationContext
{
    private ?string $correlationId = null;

    public function set(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function current(): ?string
    {
        return $this->correlationId;
    }

    public function clear(): void
    {
        $this->correlationId = null;
    }

    /**
     * Return the current correlation ID, generating a fresh one if none is set.
     */
    public function ensure(): string
    {
        if ($this->correlationId === null) {
            $this->correlationId = $this->generate();
        }

        return $this->correlationId;
    }

    /**
     * Generate a UUIDv4.
     */
    public function generate(): string
    {
        return Uuid::v4();
    }
}
