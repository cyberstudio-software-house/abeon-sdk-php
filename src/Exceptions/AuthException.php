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

    /**
     * No organisation in the current context where one is required (ADR-0016/ADR-0018).
     *
     * Deliberately an error rather than a fallback: a tenant-scoped operation with no
     * tenant must fail loudly, never silently widen to every organisation.
     */
    public static function noOrganisation(string $reason = 'No organisation in the current context'): self
    {
        return new self(new ProblemDetails(
            type:   'https://api.abeon.pl/errors/no-organisation',
            title:  'No organisation context',
            status: 403,
            detail: $reason,
        ));
    }
}
