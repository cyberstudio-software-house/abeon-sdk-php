<?php

declare(strict_types=1);

namespace Abeon\SDK\Notifications;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Actor;
use Abeon\SDK\Events\EventPublisher;

/**
 * The default way a service notifies a user: an event through the outbox, consumed by
 * AbeonUnified (ADR-0028). Call it inside the transaction that caused the
 * notification, like any other publish. The synchronous `POST /api/v1/notifications`
 * stays for the rare caller that needs the notification id back.
 */
final class Notifier
{
    public function __construct(
        private readonly EventPublisher $publisher,
        private readonly AbeonConfig $config,
    ) {
    }

    /**
     * @return string the event id
     */
    public function notify(NotificationRequest $request, ?Actor $actor = null): string
    {
        $service = $this->config->serviceName();

        return $this->publisher->publish(
            self::routingKeyFor($service),
            $request->toPayload($service),
            $actor,
        );
    }

    public static function routingKeyFor(string $serviceName): string
    {
        $segment = strtolower((string) preg_replace('/[^A-Za-z0-9_]+/', '_', $serviceName));

        return $segment.'.notification.requested';
    }
}
