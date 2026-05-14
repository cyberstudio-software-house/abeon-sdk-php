<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

interface EventHandler
{
    /**
     * Routing key patterns this handler responds to (e.g. 'crm.contact.created'
     * or 'crm.*' for topic-wildcard subscription).
     *
     * @return list<string>
     */
    public function subscribesTo(): array;

    /**
     * Handle a decoded event envelope. Implementations should be idempotent;
     * the SDK additionally guards via the abeon_processed_events table.
     */
    public function handle(Event $event): void;
}
