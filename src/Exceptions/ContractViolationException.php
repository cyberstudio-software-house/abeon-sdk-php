<?php

declare(strict_types=1);

namespace Abeon\SDK\Exceptions;

use Abeon\SDK\DTO\ProblemDetails;

class ContractViolationException extends AbeonException
{
    public static function unknownService(string $name): self
    {
        return new self(new ProblemDetails(
            type:   'https://api.abeon.pl/errors/contract-violation',
            title:  'Unknown service',
            status: 500,
            detail: sprintf("Service '%s' is not declared in abeon.services config.", $name),
        ));
    }

    public static function invalidRoutingKey(string $key): self
    {
        return new self(new ProblemDetails(
            type:   'https://api.abeon.pl/errors/contract-violation',
            title:  'Invalid event routing key',
            status: 500,
            detail: sprintf("Routing key '%s' does not match the {service}.{entity}.{action} grammar.", $key),
        ));
    }

    public static function publishOutsideTransaction(string $routingKey): self
    {
        return new self(new ProblemDetails(
            type:   'https://api.abeon.pl/errors/contract-violation',
            title:  'Event published outside DB transaction',
            status: 500,
            detail: sprintf(
                "Event '%s' published outside a database transaction. The outbox pattern requires "
                ."the business write AND the event publish to commit atomically — wrap both in DB::transaction(). "
                ."Use InMemoryEventPublisher in tests if you need to publish without a real DB.",
                $routingKey,
            ),
        ));
    }
}
