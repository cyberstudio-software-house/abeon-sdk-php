<?php

declare(strict_types=1);

namespace Abeon\SDK\Logging;

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
     * Generate a UUIDv4 without external dependencies.
     */
    public function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
