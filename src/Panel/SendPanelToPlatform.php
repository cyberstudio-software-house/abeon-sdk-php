<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a browser that opened the panel on the instance's own address to the same page on the
 * platform (`ABEON_PLATFORM_URL`). The platform session lives on the platform's host, so a panel
 * reached directly could never be signed in, and its app switcher would point back at itself.
 * Requests that came through the platform's ingress carry `X-Forwarded-*` and pass untouched.
 */
final class SendPanelToPlatform
{
    public function handle(Request $request, Closure $next): Response
    {
        $platform = rtrim((string) config('abeon.platform_url', ''), '/');

        if ($platform === ''
            || ! $request->isMethod('GET')
            || $request->expectsJson()
            || $request->headers->has('X-Forwarded-Host')
            || $request->headers->has('X-Forwarded-Prefix')
            || $request->is('health/*', 'up')) {
            return $next($request);
        }

        return redirect()->away($platform.$request->getRequestUri());
    }
}
