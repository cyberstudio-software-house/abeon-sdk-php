<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\DTO\Actor;

/**
 * Test double — captures events in memory; never touches DB or RabbitMQ.
 * Bind in tests via:
 *     $this->app->instance(EventPublisher::class, new InMemoryEventPublisher($builder));
 */
class InMemoryEventPublisher implements EventPublisher
{
    /** @var list<array<string, mixed>> */
    public array $published = [];

    public function __construct(private readonly EnvelopeBuilder $builder)
    {
    }

    public function publish(
        string $routingKey,
        array $data,
        ?Actor $actor = null,
        ?string $causationId = null,
    ): string {
        $envelope = $this->builder->build($routingKey, $data, $actor, $causationId);
        $this->published[] = $envelope;

        return $envelope['event_id'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publishedForRoutingKey(string $routingKey): array
    {
        return array_values(array_filter(
            $this->published,
            fn (array $envelope) => ($envelope['event_type'] ?? null) === $routingKey,
        ));
    }

    public function clear(): void
    {
        $this->published = [];
    }
}
