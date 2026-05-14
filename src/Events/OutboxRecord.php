<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

final readonly class OutboxRecord
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        public int $id,
        public string $eventId,
        public string $routingKey,
        public array $envelope,
        public int $attempts,
    ) {
    }

    /**
     * @param  array<string, mixed>|object  $row
     */
    public static function fromRow(array|object $row): self
    {
        $row = (array) $row;
        $envelope = $row['envelope'] ?? '[]';
        if (is_string($envelope)) {
            $decoded = json_decode($envelope, true);
            $envelope = is_array($decoded) ? $decoded : [];
        }

        return new self(
            id:         (int) $row['id'],
            eventId:    (string) $row['event_id'],
            routingKey: (string) $row['routing_key'],
            envelope:   $envelope,
            attempts:   (int) ($row['attempts'] ?? 0),
        );
    }
}
