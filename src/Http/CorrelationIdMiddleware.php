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
     * Accept an inbound `X-Correlation-ID` that is UUID-shaped, whatever version it carries.
     * Reject CRLF / oversized / malformed values silently — generate a fresh ID instead.
     *
     * Strictly UUIDv4 until now, which quietly replaced a v7 — a shape this platform already
     * mints — and every upstream gateway's id. The chain then broke at the first Abeon hop and
     * the id a user quotes appeared in no log at all, which is the one thing ADR-0003 is for.
     */
    private function resolveCorrelationId(Request $request): string
    {
        $inbound = $request->headers->get(self::HEADER);
        if (is_string($inbound) && Uuid::isWellFormed($inbound)) {
            return $inbound;
        }

        return $this->context->generate();
    }
}
