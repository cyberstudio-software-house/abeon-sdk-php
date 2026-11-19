<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that changes something through a proxy carrying the platform session must come from
 * a page of this panel: its `Origin`, or failing that its `Referer`, names this host.
 */
final class RequireSameOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $source = (string) ($request->headers->get('Origin') ?: $request->headers->get('Referer'));
            $host = mb_strtolower((string) parse_url($source, PHP_URL_HOST));
            $allowed = array_map('mb_strtolower', [(string) parse_url((string) config('app.url'), PHP_URL_HOST), $request->getHost()]);

            abort_unless($host !== '' && in_array($host, $allowed, true), 403, 'Cross-origin request refused.');
        }

        return $next($request);
    }
}
