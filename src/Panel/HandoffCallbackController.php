<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Abeon\SDK\Config\AbeonConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;
use Throwable;

/**
 * `GET /auth/callback?code=…&next=…` — where a client's host picks up a session
 * (ADR-0031 §3); see docs/notes/host-handoff.md.
 */
final class HandoffCallbackController
{
    public function __construct(private readonly AbeonConfig $config)
    {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $code = $request->query('code');
        $code = is_string($code) ? $code : '';
        $next = $request->query('next');
        $next = $this->safeNext(is_string($next) ? $next : '/');

        if ($code === '') {
            return $this->toLogin($request);
        }

        try {
            $response = Http::acceptJson()->timeout(5)->post(
                rtrim($this->config->authUrl(), '/').'/api/v1/auth/handoff/exchange',
                ['code' => $code, 'host' => $request->getHost()],
            );
        } catch (Throwable) {
            return $this->toLogin($request);
        }

        if (! $response->successful()) {
            return $this->toLogin($request);
        }

        $access  = (string) $response->json('data.access_token');
        $refresh = (string) $response->json('data.refresh_token');

        if ($access === '' || $refresh === '') {
            return $this->toLogin($request);
        }

        return redirect()->to($next)
            ->withCookie($this->cookie(
                $this->config->authAccessCookieName(),
                $access,
                (int) $response->json('data.expires_in', 900),
                $request->isSecure(),
            ))
            ->withCookie($this->cookie(
                $this->config->authRefreshCookieName(),
                $refresh,
                (int) $response->json('data.refresh_expires_in', 604800),
                $request->isSecure(),
            ));
    }

    /**
     * Host-only, `path=/` and `httpOnly`; see docs/notes/host-handoff.md.
     */
    private function cookie(string $name, string $value, int $ttlSeconds, bool $secure): SymfonyCookie
    {
        return Cookie::make(
            name: $name,
            value: $value,
            minutes: (int) ceil($ttlSeconds / 60),
            path: '/',
            domain: null,
            secure: $secure,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    /**
     * A path inside this application, never somewhere else; see docs/notes/host-handoff.md.
     */
    private function safeNext(string $next): string
    {
        if ($next === '' || ! str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '\\')) {
            return '/';
        }

        return $next;
    }

    private function toLogin(Request $request): RedirectResponse
    {
        $loginUrl = rtrim((string) config('abeon.auth.ui_url'), '/').'/login';

        return redirect()->away($loginUrl.'?redirect='.urlencode($request->getSchemeAndHttpHost().'/'));
    }
}
