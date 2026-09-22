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

    /**
     * A valid token for an organisation this instance does not serve (ADR-0031 §4).
     *
     * 403 rather than 401: the credentials are fine, and treating them as missing would send
     * a browser back to login, which would hand it the same token and loop.
     */
    public static function wrongOrganisation(int $tokenOrgId, int $instanceOrgId): self
    {
        return new self(new ProblemDetails(
            type:   'https://api.abeon.pl/errors/wrong-organisation',
            title:  'Wrong organisation',
            status: 403,
            detail: "This instance serves organisation {$instanceOrgId}; the token is for organisation {$tokenOrgId}",
        ));
    }
}
