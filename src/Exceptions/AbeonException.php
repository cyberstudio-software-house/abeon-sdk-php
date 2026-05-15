<?php

declare(strict_types=1);

namespace Abeon\SDK\Exceptions;

use Abeon\SDK\DTO\ProblemDetails;
use RuntimeException;
use Throwable;

class AbeonException extends RuntimeException
{
    public function __construct(
        public readonly ProblemDetails $problem,
        ?Throwable $previous = null,
    ) {
        // LO-2 from code review: exception code is always 0 (not the HTTP
        // status). Mixing HTTP status into the exception-code slot makes
        // catch-by-code unreliable. HTTP status is in $problem->status.
        parent::__construct(
            message: $problem->detail ?? $problem->title,
            code: 0,
            previous: $previous,
        );
    }

    public function status(): int
    {
        return $this->problem->status;
    }
}
