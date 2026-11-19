<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Abeon\SDK\Auth\AuthContext;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks deep-links into this app from users not entitled to it (spec FR-1, ADR-0010);
 * apply AFTER `AbeonWebAuth`. See docs/notes/app-entitlement.md.
 */
final class EnsureAppEntitled
{
    public function __construct(private readonly AuthContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $app = (string) (config('abeon.app_descriptor.name') ?: config('abeon.service.name'));

        if ($app === '') {
            throw new LogicException(
                'This application has no identity configured, so it cannot decide who is entitled to it. '
                .'Set ABEON_SERVICE_NAME (or abeon.app_descriptor.name).',
            );
        }

        $user = $this->context->user();
        $granted = $user->permissions ?? [];

        foreach ($granted as $permission) {
            if (str_starts_with($permission, $app.'.')) {
                return $next($request);
            }
        }

        abort(403, 'You are not entitled to this application.');
    }
}
