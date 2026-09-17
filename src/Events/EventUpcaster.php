<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

/**
 * Rewrites an envelope from one version into another before handlers see it, so a
 * consumer can keep handlers written for 1.0 while producers move to 2.0 (ADR-0002).
 * Tag implementations `abeon.event_upcaster`.
 */
interface EventUpcaster
{
    public function supports(Event $event): bool;

    public function upcast(Event $event): Event;
}
