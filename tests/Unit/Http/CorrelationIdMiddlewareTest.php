<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Http;

use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;

final class CorrelationIdMiddlewareTest extends TestCase
{
    private function middleware(): array
    {
        $context = new CorrelationContext();
        return [$context, new CorrelationIdMiddleware($context)];
    }

    public function test_uses_inbound_uuid_when_valid(): void
    {
        $valid = Uuid::v4();
        $request = Request::create('/x');
        $request->headers->set(CorrelationIdMiddleware::HEADER, $valid);

        [$context, $middleware] = $this->middleware();

        $response = $middleware->handle($request, fn () => new Response());

        $this->assertSame($valid, $context->current());
        $this->assertSame($valid, $response->headers->get(CorrelationIdMiddleware::HEADER));
    }

    public function test_generates_fresh_uuid_when_no_header(): void
    {
        $request = Request::create('/x');
        [$context, $middleware] = $this->middleware();

        $response = $middleware->handle($request, fn () => new Response());

        $this->assertMatchesRegularExpression(Uuid::REGEX, (string) $context->current());
        $this->assertSame($context->current(), $response->headers->get(CorrelationIdMiddleware::HEADER));
    }

    public function test_rejects_inbound_crlf_injection_attempt(): void
    {
        $request = Request::create('/x');
        // Symfony Headers will strip CRLF — but if attacker uses URL-encoding or
        // adjacent system slips it through, validator rejects non-UUID values.
        $request->headers->set(
            CorrelationIdMiddleware::HEADER,
            "550e8400-e29b-41d4-a716-446655440000 Set-Cookie: admin=1",
            replace: true,
        );

        [$context, $middleware] = $this->middleware();
        $response = $middleware->handle($request, fn () => new Response());

        // Non-UUID inbound → fresh UUID generated, header echoed sanitized.
        $emitted = $response->headers->get(CorrelationIdMiddleware::HEADER);
        $this->assertMatchesRegularExpression(Uuid::REGEX, (string) $emitted);
        $this->assertStringNotContainsString('Set-Cookie', (string) $emitted);
    }

    public function test_rejects_inbound_arbitrary_string(): void
    {
        $request = Request::create('/x');
        $request->headers->set(CorrelationIdMiddleware::HEADER, 'arbitrary-trace-id');

        [$context, $middleware] = $this->middleware();
        $middleware->handle($request, fn () => new Response());

        $this->assertNotSame('arbitrary-trace-id', $context->current());
        $this->assertMatchesRegularExpression(Uuid::REGEX, (string) $context->current());
    }

    public function test_rejects_inbound_empty_string(): void
    {
        $request = Request::create('/x');
        $request->headers->set(CorrelationIdMiddleware::HEADER, '');

        [$context, $middleware] = $this->middleware();
        $middleware->handle($request, fn () => new Response());

        $this->assertMatchesRegularExpression(Uuid::REGEX, (string) $context->current());
    }

    /**
     * A v7 — which this platform already mints — and an upstream gateway's id were both
     * replaced, so the chain broke at the first Abeon hop and the id a user quotes appeared
     * in no log.
     */
    public function test_a_uuid_of_another_version_is_kept(): void
    {
        $inbound = '018f0c6e-1c1a-7a4b-9d2e-0242ac120002';

        $request = Request::create('/');
        $request->headers->set('X-Correlation-ID', $inbound);

        $response = (new CorrelationIdMiddleware(new CorrelationContext()))
            ->handle($request, fn () => new Response());

        $this->assertSame($inbound, $response->headers->get('X-Correlation-ID'));
    }

    public function test_a_value_that_is_not_a_uuid_is_still_replaced(): void
    {
        $request = Request::create('/');
        $request->headers->set('X-Correlation-ID', "not-a-uuid\r\nInjected: yes");

        $response = (new CorrelationIdMiddleware(new CorrelationContext()))
            ->handle($request, fn () => new Response());

        $this->assertNotSame("not-a-uuid\r\nInjected: yes", $response->headers->get('X-Correlation-ID'));
    }
}
