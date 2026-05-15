<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Abeon\SDK\Config\AbeonConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Config-driven CORS middleware for Abeon services.
 *
 * Required because the chrome (Inertia/Next.js apps) often runs on a different
 * origin than the service it calls (e.g. `app.abeon.pl` calling
 * `crm.api.internal`). Without a shared allow-list, each service would
 * hand-roll CORS — drift inevitable.
 *
 * Reads `abeon.cors.allowed_origins` (CSV env: `ABEON_CORS_ALLOWED_ORIGINS`).
 * Use `*` to allow any origin (development only; pairs poorly with credentials).
 *
 * Echoes the inbound `Origin` only if it matches the allow-list (never `*`
 * when credentials are involved, per the CORS spec).
 */
class Cors
{
    public function __construct(private readonly AbeonConfig $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $origin   = (string) $request->headers->get('Origin', '');
        $allowed  = $this->config->corsAllowedOrigins();
        $matched  = $this->matchOrigin($origin, $allowed);

        if ($request->getMethod() === 'OPTIONS' && $request->headers->has('Access-Control-Request-Method')) {
            $response = new Response('', 204);
        } else {
            $response = $next($request);
        }

        if ($matched !== null) {
            $response->headers->set('Access-Control-Allow-Origin', $matched);
            $response->headers->set('Vary', 'Origin', false);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set(
                'Access-Control-Allow-Headers',
                'Authorization, Content-Type, X-Correlation-ID, X-XSRF-TOKEN, X-Requested-With, Idempotency-Key',
            );
            $response->headers->set(
                'Access-Control-Allow-Methods',
                'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            );
            $response->headers->set('Access-Control-Max-Age', '600');
            $response->headers->set(
                'Access-Control-Expose-Headers',
                'X-Correlation-ID, X-API-Version, Deprecation, Sunset',
            );
        }

        return $response;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function matchOrigin(string $origin, array $allowed): ?string
    {
        if ($origin === '' || $allowed === []) {
            return null;
        }

        foreach ($allowed as $candidate) {
            if ($candidate === '*') {
                // Wildcard cannot be returned with credentials — echo the actual origin
                // so credentialed requests still work in development.
                return $origin;
            }
            if (strcasecmp($candidate, $origin) === 0) {
                return $origin;
            }
        }

        return null;
    }
}
