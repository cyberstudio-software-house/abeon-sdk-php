<?php

declare(strict_types=1);

namespace Abeon\SDK\Exceptions;

use Abeon\SDK\DTO\ProblemDetails;
use Abeon\SDK\Http\ProblemDetailsRenderer;

/**
 * A list query the endpoint does not support: an unknown filter or sort field, or a bad
 * page. Rendered like a validation error, with field errors under `errors` (ADR-0004).
 */
final class InvalidQueryException extends AbeonException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function withErrors(array $errors): self
    {
        $first = null;
        foreach ($errors as $messages) {
            $first = $messages[0] ?? null;

            break;
        }

        return new self(new ProblemDetails(
            type:       ProblemDetailsRenderer::TYPE_PREFIX.'validation',
            title:      'Validation Error',
            status:     422,
            detail:     $first ?? 'The query is invalid.',
            extensions: ['errors' => $errors],
        ));
    }
}
