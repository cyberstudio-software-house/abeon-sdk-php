<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Exceptions\ContractViolationException;

class RoutingKey
{
    /** {service}.{entity}.{action} */
    public const PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    public static function isValid(string $key): bool
    {
        return (bool) preg_match(self::PATTERN, $key);
    }

    public static function assertValid(string $key): void
    {
        if (! self::isValid($key)) {
            throw ContractViolationException::invalidRoutingKey($key);
        }
    }
}
