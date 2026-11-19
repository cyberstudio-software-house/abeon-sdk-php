<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-friendly auth middleware: validates the `abeon_token` cookie, populates AuthContext
 * and redirects a browser to login on failure; see docs/notes/web-auth.md.
 */
final class AbeonWebAuth
{
    public function __construct(
        private readonly JwtValidator $validator,
        private readonly AuthContext $context,
        private readonly AbeonConfig $config,
        private readonly TokenRefresher $refresher,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie($this->config->authAccessCookieName());
        $refreshed = null;

        // The usual expiry case is no token at all; see docs/notes/web-auth.md.
        if (! is_string($token) || $token === '' || $this->decode($token) === null) {
            $refreshed = $this->refresher->attempt(
                $request->cookie($this->config->authRefreshCookieName()),
            );

            if ($refreshed === null) {
                return $this->redirectToLogin($request);
            }

            $token = $refreshed->accessToken;
        }

        $user = $this->decode($token);

        if ($user === null) {
            // A token Auth has just minted and we cannot read means the two disagree
            // about keys — refreshing again would loop, so this ends at login.
            return $this->redirectToLogin($request);
        }

        $this->context->set($user);

        // Forward the JWT to downstream `abeon.auth`-protected routes;
        // see docs/notes/web-auth.md.
        if ($refreshed !== null || ! $request->headers->has('Authorization')) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        $response = $next($request);

        if ($refreshed !== null) {
            // Auth's own `Set-Cookie` headers, forwarded verbatim — it owns the
            // lifetimes and attributes of the platform session cookies.
            foreach ($refreshed->setCookies as $cookie) {
                $response->headers->set('Set-Cookie', $cookie, false);
            }
        }

        return $response;
    }

    /**
     * The user in a token, or null when the token is unusable and login is the answer.
     * A token for another organisation is not unusable (ADR-0031 §4); see docs/notes/web-auth.md.
     */
    private function decode(string $token): ?User
    {
        try {
            return $this->validator->decodeUser($token);
        } catch (AuthException $e) {
            if ($e->status() === 403 && str_ends_with($e->problem->type, '/wrong-organisation')) {
                throw new WrongOrganisation($this->config->instanceOrgId(), $e->problem->detail ?? 'This instance serves another organisation.');
            }

            if ($e->status() === 403) {
                abort(403, $e->problem->detail ?? 'This instance serves another organisation.');
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Send the browser to `abeon-auth-ui`'s login screen (ADR-0026, ADR-0027), saying where
     * it was going; see docs/notes/web-auth.md.
     */
    private function redirectToLogin(Request $request): Response
    {
        $loginUrl = rtrim((string) config('abeon.auth.ui_url'), '/').'/login';

        $url = $loginUrl.'?redirect='.urlencode($request->fullUrl());

        return $request->header('X-Inertia') ? response('', 409, ['X-Inertia-Location' => $url]) : redirect()->away($url);
    }
}
