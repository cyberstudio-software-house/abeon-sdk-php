<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tags responses with API version metadata.
 *
 * Default: `X-API-Version: v1`.
 * Optional sunset date promotes the response to deprecated:
 *   `Deprecation: true` + `Sunset: <date>` (per RFC 8594).
 *
 * Usage:
 *     Route::middleware(['abeon.version'])->prefix('api/v1')->group(...)
 *     Route::middleware(['abeon.version:v2'])->prefix('api/v2')->group(...)
 *     Route::middleware(['abeon.version:v1,2026-12-31'])->prefix('api/v1')->group(...)
 */
class VersionHeadersMiddleware
{
    public const HEADER_VERSION     = 'X-API-Version';
    public const HEADER_DEPRECATION = 'Deprecation';
    public const HEADER_SUNSET      = 'Sunset';

    public function handle(
        Request $request,
        Closure $next,
        string $version = 'v1',
        ?string $sunset = null,
    ): Response {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set(self::HEADER_VERSION, $version);

        if ($sunset !== null && $sunset !== '') {
            $response->headers->set(self::HEADER_DEPRECATION, 'true');
            $response->headers->set(self::HEADER_SUNSET, $sunset);
        }

        return $response;
    }
}
