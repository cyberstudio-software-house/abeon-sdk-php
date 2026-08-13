<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\Exceptions\AuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for endpoints that only another service may call (ADR-0005).
 *
 * The SDK shipped the *client* half of service-to-service auth — `ServiceTokenProvider`
 * mints the tokens and `ServiceClient` attaches them — but nothing on the receiving
 * side: `AuthMiddleware` calls `decodeUser()`, which asserts `type: "user"` and so
 * rejects every service token. Any service with an internal endpoint had to write this
 * itself, which is how sixteen slightly different validations happen.
 *
 * What it enforces is ADR-0005's validation list: signature via cached JWKS, `aud`,
 * `exp`, and `type: "service"`. **Deciding *which* services may call a given route is
 * deliberately not here** — that is per-route policy, and ADR-0005 says so. This
 * answers "is the caller a service, and which one", and puts the name where a route
 * can act on it.
 */
class ServiceAuthMiddleware
{
    /** Request attribute carrying the verified caller, for per-route policy. */
    public const ATTRIBUTE = 'abeon_service_name';

    public function __construct(private readonly JwtValidator $validator)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            throw AuthException::unauthenticated();
        }

        $claims = $this->validator->decode($token);

        // A user token must never open a service endpoint. Without this check the
        // internal surface would be reachable by anybody holding a normal session,
        // which is the opposite of what "internal" means.
        if (($claims['type'] ?? null) !== 'service') {
            throw AuthException::unauthenticated('Expected service-type JWT');
        }

        $serviceName = $claims['service_name'] ?? null;

        if (! is_string($serviceName) || $serviceName === '') {
            throw AuthException::unauthenticated('Service JWT is missing service_name');
        }

        $request->attributes->set(self::ATTRIBUTE, $serviceName);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (is_string($header) && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7) ?: null;
        }

        return null;
    }
}
