<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

/**
 * Lazily-resolved catalog of event schemas discovered across installed
 * Composer packages. Aggregated once per process via SchemaDiscovery.
 */
class EventCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(private readonly SchemaDiscovery $discovery)
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = $this->discovery->discover();
        }

        return $this->cache;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function for(string $routingKey): ?array
    {
        return $this->all()[$routingKey] ?? null;
    }

    /**
     * @return list<string>
     */
    public function routingKeys(): array
    {
        return array_keys($this->all());
    }

    public function refresh(): void
    {
        $this->cache = null;
    }
}
