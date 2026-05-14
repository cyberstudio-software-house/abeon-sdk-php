<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Abeon\SDK\DTO\ProblemDetails;
use Abeon\SDK\Exceptions\AbeonException;
use Illuminate\Http\JsonResponse;
use Throwable;

class ProblemDetailsRenderer
{
    public function __construct(private readonly bool $debug = false)
    {
    }

    public function render(Throwable $exception, ?string $instance = null): JsonResponse
    {
        $problem = $exception instanceof AbeonException
            ? $exception->problem
            : new ProblemDetails(
                type:   'about:blank',
                title:  'Internal Server Error',
                status: 500,
                detail: $this->debug ? $exception->getMessage() : null,
            );

        $body = $problem->toArray();
        if ($instance !== null && ! isset($body['instance'])) {
            $body['instance'] = $instance;
        }

        return response()->json($body, $problem->status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
