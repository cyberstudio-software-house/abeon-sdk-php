<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Same-origin proxy for `/api/v1/auth/*` calls from the SPA; see docs/notes/service-proxy.md.
 */
class AuthProxyController
{
    use ProxiesToService;

    private const REFRESH_PATH = '/api/v1/auth/refresh';

    public function __invoke(Request $request): Response
    {
        // Refresh has exactly one entry point, and it is not this relay (ADR-0023);
        // see docs/notes/service-proxy.md.
        if ($request->getPathInfo() === self::REFRESH_PATH) {
            return $this->refresh($request);
        }

        // Auth owns the session: it is the only upstream allowed to see the refresh
        // cookie or to set cookies on this origin.
        return $this->relay($request, (string) config('abeon.auth.url'), '/api/v1/auth', carriesSession: true);
    }

    /**
     * Serve a refresh through the same single-flight the rest of the application uses,
     * and answer with Auth's own body so callers cannot tell the difference.
     */
    private function refresh(Request $request): Response
    {
        $refreshed = app(TokenRefresher::class)->attempt(
            $request->cookie((string) config('abeon.auth.cookies.refresh', 'abeon_refresh')),
        );

        if ($refreshed === null) {
            // ADR-0004 shape, matching what Auth itself would have answered.
            return response((string) json_encode([
                'type'   => 'https://api.abeon.pl/errors/unauthenticated',
                'title'  => 'Unauthenticated',
                'status' => 401,
            ]), 401, ['Content-Type' => 'application/problem+json']);
        }

        $response = response($refreshed->body, 200, ['Content-Type' => 'application/json']);

        foreach ($refreshed->setCookies as $cookie) {
            $response->headers->set('Set-Cookie', $cookie, false);
        }

        return $response;
    }
}
