<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Illuminate\Http\Client\Response as PromiseResponse;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Logging\CorrelationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Same-origin relay for a platform service; see docs/notes/service-proxy.md.
 */
trait ProxiesToService
{
    /**
     * Paths allowed to carry the refresh cookie upstream; see docs/notes/service-proxy.md.
     */
    private const SESSION_PATHS = [
        '/api/v1/auth/refresh',
        '/api/v1/auth/logout',
        // ADR-0023 §2; see docs/notes/service-proxy.md.
        '/api/v1/auth/tenant',
    ];

    /**
     * The only cookies this application will let an upstream set on its own origin.
     * Anything else a service tries to plant is dropped.
     *
     * @return list<string>
     */
    private function platformCookieNames(): array
    {
        return [
            (string) config('abeon.auth.cookies.access', 'abeon_token'),
            (string) config('abeon.auth.cookies.refresh', 'abeon_refresh'),
        ];
    }

    /**
     * @param  bool  $carriesSession  True only for the service that owns the session —
     *        Auth. It gates two things a non-identity service must never touch: seeing
     *        the refresh cookie, and setting cookies on this origin.
     */
    protected function relay(
        Request $request,
        string $baseUrl,
        string $allowedPrefix,
        bool $carriesSession = false,
    ): Response {
        $path = $this->safePath($request, $allowedPrefix);

        if ($path === null) {
            abort(404);
        }

        $token = $request->cookie((string) config('abeon.auth.cookies.access', 'abeon_token'));

        // Query string carried for every method, not only `GET`; see docs/notes/service-proxy.md.
        $query = $request->getQueryString();
        $url = rtrim($baseUrl, '/').$path.($query === null || $query === '' ? '' : '?'.$query);

        $method = strtoupper($request->getMethod());
        $body   = $request->getContent();

        $upstream = $this->send($request, $method, $url, $body, $token, $carriesSession);

        if ($upstream === null) {
            return response('Method not allowed', 405);
        }

        $refreshedCookies = [];

        // Refresh-on-401 keys off the refresh cookie, not the access one; see docs/notes/service-proxy.md.
        $refreshCookie = $request->cookie((string) config('abeon.auth.cookies.refresh', 'abeon_refresh'));

        // Never refresh in response to a failed refresh (ADR-0023); see docs/notes/service-proxy.md.
        $isRefresh = str_ends_with($request->getPathInfo(), '/auth/refresh');

        // Replay after refresh is for idempotent methods only; see docs/notes/service-proxy.md.
        $isReplayable = in_array($method, ['GET', 'HEAD', 'PUT', 'DELETE'], true);

        if ($isReplayable && ! $isRefresh && $upstream->status() === 401 && is_string($refreshCookie) && $refreshCookie !== '') {
            $refreshed = app(TokenRefresher::class)->attempt($refreshCookie);

            if ($refreshed !== null) {
                $retry = $this->send($request, $method, $url, $body, $refreshed->accessToken, $carriesSession);

                if ($retry !== null) {
                    $upstream = $retry;
                    $refreshedCookies = $refreshed->setCookies;
                }
            }
        }

        $response = response($upstream->body(), $upstream->status(), $this->passthroughHeaders($upstream));

        foreach ($refreshedCookies as $cookie) {
            $response->headers->set('Set-Cookie', $cookie, false);
        }

        // Only the session-owning service may set platform cookies here (ADR-0017);
        // see docs/notes/service-proxy.md.
        if ($carriesSession) {
            $allowed = $this->platformCookieNames();

            foreach ($upstream->headers()['Set-Cookie'] ?? [] as $cookie) {
                if (in_array(strtok((string) $cookie, '='), $allowed, true)) {
                    $response->headers->set('Set-Cookie', $cookie, false);
                }
            }
        }

        return $response;
    }

    /**
     * The request path, if it is inside the prefix this proxy is allowed to reach;
     * traversal handling is explained in docs/notes/service-proxy.md.
     */
    private function safePath(Request $request, string $allowedPrefix): ?string
    {
        if (preg_match('/%2e|%2f|%5c/i', $request->getRequestUri()) === 1) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $request->getPathInfo()) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $normalised = '/'.implode('/', $segments);

        return str_starts_with($normalised, $allowedPrefix) ? $normalised : null;
    }

    /**
     * Response headers worth handing back to the browser; see docs/notes/service-proxy.md.
     *
     * @return array<string, string>
     */
    private function passthroughHeaders(PromiseResponse $upstream): array
    {
        $headers = ['Content-Type' => $upstream->header('Content-Type') ?: 'application/json'];

        foreach ([
            'WWW-Authenticate', 'Retry-After', 'Content-Disposition', 'ETag', 'Cache-Control', 'Vary',
            'X-API-Version', 'Deprecation', 'Sunset', 'X-Correlation-ID',
        ] as $name) {
            $value = $upstream->header($name);

            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * One relayed call. Separate from `relay()` so the retry after a refresh is the
     * same request rather than an approximation of it.
     */
    private function send(
        Request $request,
        string $method,
        string $url,
        string $body,
        mixed $token,
        bool $carriesSession,
    ): ?PromiseResponse {
        $client = Http::withHeaders([
            'Accept' => 'application/json',
            // ADR-0003.
            CorrelationIdMiddleware::HEADER => app(CorrelationContext::class)->ensure(),
        ]);

        if (is_string($token) && $token !== '') {
            $client = $client->withToken($token);
        }

        // The refresh cookie goes upstream only on session paths, and only to Auth;
        // see docs/notes/service-proxy.md.
        $needsRefreshCookie = $carriesSession
            && in_array($request->getPathInfo(), self::SESSION_PATHS, true);

        $refreshCookie = $needsRefreshCookie
            ? $request->cookie((string) config('abeon.auth.cookies.refresh', 'abeon_refresh'))
            : null;

        if (is_string($refreshCookie) && $refreshCookie !== '') {
            $client = $client->withHeaders([
                'Cookie' => (string) config('abeon.auth.cookies.refresh', 'abeon_refresh').'='.$refreshCookie,
            ]);
        }

        // The browser's own content type, not `application/json` imposed on everything;
        // see docs/notes/service-proxy.md.
        $contentType = $request->headers->get('Content-Type') ?: 'application/json';

        return match ($method) {
            'GET'    => $client->get($url),
            'POST'   => $client->withBody($body, $contentType)->post($url),
            'PATCH'  => $client->withBody($body, $contentType)->patch($url),
            'PUT'    => $client->withBody($body, $contentType)->put($url),
            'DELETE' => $body === ''
                ? $client->delete($url)
                : $client->withBody($body, $contentType)->delete($url),
            default  => null,
        };
    }
}
