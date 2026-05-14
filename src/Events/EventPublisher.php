<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\DTO\Actor;

interface EventPublisher
{
    /**
     * Publish an event. Returns the generated event_id.
     *
     * The default implementation (OutboxPublisher) writes to the outbox
     * table within the caller's DB transaction — exactly-at-least-once
     * delivery requires wrapping the business write + publish in
     * DB::transaction(...).
     *
     * @param  array<string, mixed>  $data
     */
    public function publish(
        string $routingKey,
        array $data,
        ?Actor $actor = null,
        ?string $causationId = null,
    ): string;
}
