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
        parent::__construct(
            $problem->detail ?? $problem->title,
            $problem->status,
            $previous,
        );
    }
}
