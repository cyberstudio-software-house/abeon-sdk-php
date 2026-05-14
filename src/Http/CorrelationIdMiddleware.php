<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Abeon\SDK\Logging\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorrelationIdMiddleware
{
    public const HEADER = 'X-Correlation-ID';

    public function __construct(private readonly CorrelationContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->headers->get(self::HEADER) ?: $this->context->generate();
        $this->context->set($correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
