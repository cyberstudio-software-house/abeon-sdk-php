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
        public ?int $orgId,
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
            orgId:     self::orgIdFrom($envelope['org_id'] ?? null),
            actor:     Actor::fromArray((array) ($envelope['actor'] ?? ['type' => 'system'])),
            data:      (array) ($envelope['data'] ?? []),
            metadata:  (array) ($envelope['metadata'] ?? []),
        );
    }

    /**
     * The envelope's organisation, or null.
     *
     * `(int)` on whatever arrived read `true` as organisation 1 and a string or an array as
     * organisation 0 — so a malformed envelope attributed a handler's writes to some other
     * tenant instead of being refused. Anything that is not a positive integer is null here,
     * and `EventConsumer` refuses the message before this is reached (ADR-0002, ADR-0018).
     */
    private static function orgIdFrom(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
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
            'org_id'     => $this->orgId,
            'actor'      => $this->actor->toArray(),
            'data'       => $this->data,
            'metadata'   => $this->metadata,
        ];
    }
}
