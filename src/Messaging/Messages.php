<?php

declare(strict_types=1);

namespace Abeon\SDK\Messaging;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Actor;
use Abeon\SDK\Events\EventPublisher;

/**
 * How a service sends a transactional e-mail: an event through the outbox, delivered by
 * AbeonUnified, which is the only component holding mail credentials (ADR-0019, ADR-0030).
 * Call it inside the transaction that caused the message.
 */
final class Messages
{
    public function __construct(
        private readonly EventPublisher $publisher,
        private readonly AbeonConfig $config,
    ) {
    }

    /**
     * @return string the event id
     */
    public function send(MessageRequest $request, ?Actor $actor = null): string
    {
        return $this->publisher->publish(
            self::routingKeyFor($this->config->serviceName()),
            $request->toPayload(),
            $actor,
        );
    }

    public static function routingKeyFor(string $serviceName): string
    {
        $segment = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $serviceName));

        return $segment.'.message.requested';
    }
}
