<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

/**
 * Optional on an EventHandler. A handler that does not implement it accepts major
 * version 1 only, so a 2.0 envelope never reaches code written for 1.0 (ADR-0002).
 */
interface AcceptsEventVersions
{
    /**
     * @return list<int>
     */
    public function acceptedMajorVersions(): array;
}
