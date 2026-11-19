<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A valid session for an organisation this instance does not serve (ADR-0031 §4).
 *
 * Still a 403, but rendered as its own page: the user has done nothing wrong and may simply
 * have the other organisation active, so the page offers the switch (ADR-0017) instead of
 * telling them to ask for a permission.
 */
final class WrongOrganisation extends HttpException
{
    public function __construct(public readonly ?int $instanceOrgId, string $message)
    {
        parent::__construct(403, $message);
    }
}
