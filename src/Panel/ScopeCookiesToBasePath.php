<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps this application's session and `XSRF-TOKEN` cookies under its own path prefix
 * (ADR-0031 §2); see docs/notes/path-prefix.md.
 */
final class ScopeCookiesToBasePath
{
    public function handle(Request $request, Closure $next): Response
    {
        $base = $request->getBaseUrl();

        if ($base !== '') {
            config(['session.path' => $base]);
        }

        return $next($request);
    }
}
