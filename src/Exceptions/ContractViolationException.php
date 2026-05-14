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
}
