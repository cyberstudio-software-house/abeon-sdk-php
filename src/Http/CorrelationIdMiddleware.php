<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Support\Uuid;
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
        $correlationId = $this->resolveCorrelationId($request);
        $this->context->set($correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    /**
     * Accept inbound `X-Correlation-ID` only if it's a valid UUIDv4 string.
     * Reject CRLF / oversized / malformed values silently — generate a
     * fresh ID instead. (MD-1 from code review.)
     */
    private function resolveCorrelationId(Request $request): string
    {
        $inbound = $request->headers->get(self::HEADER);
        if (is_string($inbound) && Uuid::isValid($inbound)) {
            return $inbound;
        }

        return $this->context->generate();
    }
}
