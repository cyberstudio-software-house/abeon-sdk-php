<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Broadcasting\BroadcastingAuthController;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * What a business application's panel shares with every other panel: the `web` group's
 * guards, the session and proxy routes, and the `abeon` props of the first render.
 */
final class Panel
{
    public const WEB_MIDDLEWARE = [
        SecurityHeaders::class,
        ScopeCookiesToBasePath::class,
        EnsurePanelSurface::class,
        SendPanelToPlatform::class,
    ];

    private const PROXY_METHODS = ['get', 'post', 'patch', 'put', 'delete'];

    /**
     * Call from `routes/web.php`, inside the `web` group.
     */
    public static function routes(): void
    {
        Route::get('/auth/callback', HandoffCallbackController::class)->middleware('web');
        Route::post('/broadcasting/auth', BroadcastingAuthController::class)->middleware('web');

        Route::match(self::PROXY_METHODS, '/api/v1/auth/{any}', AuthProxyController::class)
            ->middleware([CorrelationIdMiddleware::class, RequireSameOrigin::class])
            ->where('any', '.*');

        Route::match(self::PROXY_METHODS, '/api/v1/notifications/{any?}', NotificationProxyController::class)
            ->middleware([CorrelationIdMiddleware::class, RequireSameOrigin::class])
            ->where('any', '.*');
    }

    /**
     * The `abeon` Inertia prop; see docs/notes/inertia-shared-props.md.
     *
     * @return array<string, mixed>
     */
    public static function props(Request $request): array
    {
        $user = app(AuthContext::class)->user();

        return [
            'appId'       => (string) config('abeon.service.name'),
            'basePath'    => $request->getBaseUrl(),
            'user'        => $user?->toArray(),
            'loginUrl'    => rtrim((string) config('abeon.auth.ui_url'), '/').'/login',
            'platformUrl' => config('abeon.platform_url'),
            'reverb'      => config('broadcasting.connections.reverb.key') === null ? null : [
                'key'   => (string) config('broadcasting.connections.reverb.key'),
                'wsUrl' => sprintf(
                    '%s://%s:%d',
                    config('broadcasting.connections.reverb.options.scheme') === 'https' ? 'wss' : 'ws',
                    (string) config('broadcasting.connections.reverb.options.host'),
                    (int) config('broadcasting.connections.reverb.options.port'),
                ),
            ],
            'preferences' => $request->header('X-Inertia') === null && $user !== null
                ? app(PreferencesReader::class)->read($request->bearerToken())
                : null,
        ];
    }
}
