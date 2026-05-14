<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\DTO\Actor;

/**
 * Decoded event envelope. Matches schemas/events/_envelope.json.
 */
final readonly class Event
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $timestamp,
        public string $source,
        public string $version,
        public Actor $actor,
        public array $data,
        public array $metadata = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public static function fromEnvelope(array $envelope): self
    {
        return new self(
            eventId:   (string) ($envelope['event_id'] ?? ''),
            eventType: (string) ($envelope['event_type'] ?? ''),
            timestamp: (string) ($envelope['timestamp'] ?? ''),
            source:    (string) ($envelope['source'] ?? ''),
            version:   (string) ($envelope['version'] ?? '1.0'),
            actor:     Actor::fromArray((array) ($envelope['actor'] ?? ['type' => 'system'])),
            data:      (array) ($envelope['data'] ?? []),
            metadata:  (array) ($envelope['metadata'] ?? []),
        );
    }

    public function correlationId(): ?string
    {
        $cid = $this->metadata['correlation_id'] ?? null;

        return is_string($cid) ? $cid : null;
    }

    public function causationId(): ?string
    {
        $cid = $this->metadata['causation_id'] ?? null;

        return is_string($cid) ? $cid : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toEnvelope(): array
    {
        return [
            'event_id'   => $this->eventId,
            'event_type' => $this->eventType,
            'timestamp'  => $this->timestamp,
            'source'     => $this->source,
            'version'    => $this->version,
            'actor'      => $this->actor->toArray(),
            'data'       => $this->data,
            'metadata'   => $this->metadata,
        ];
    }
}
