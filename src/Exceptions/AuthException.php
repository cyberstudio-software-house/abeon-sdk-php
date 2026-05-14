<?php

declare(strict_types=1);

namespace Abeon\SDK\Exceptions;

use Abeon\SDK\DTO\ProblemDetails;

class AuthException extends AbeonException
{
    public static function unauthenticated(string $reason = 'Missing or invalid credentials'): self
    {
        return new self(new ProblemDetails(
            type:   'https://api.abeon.pl/errors/unauthenticated',
            title:  'Unauthenticated',
            status: 401,
            detail: $reason,
        ));
    }

    public static function forbidden(string $reason = 'Insufficient permissions'): self
    {
        return new self(new ProblemDetails(
            type:   'https://api.abeon.pl/errors/forbidden',
            title:  'Forbidden',
            status: 403,
            detail: $reason,
        ));
    }
}
