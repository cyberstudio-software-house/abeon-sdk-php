<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the panel off the instance's public host, with a 404 rather than a 403 (ADR-0033);
 * see docs/notes/public-surface.md.
 */
final class EnsurePanelSurface
{
    public function handle(Request $request, Closure $next): Response
    {
        $publicHost = (string) config('abeon.public_host', '');

        if ($publicHost !== '' && mb_strtolower($request->getHost()) === mb_strtolower($publicHost)) {
            abort(404);
        }

        return $next($request);
    }
}
