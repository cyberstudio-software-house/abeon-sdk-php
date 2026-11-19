<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Panel;

use Abeon\SDK\AbeonServiceProvider;
use Abeon\SDK\Auth\PlatformCookies;
use Abeon\SDK\Panel\AbeonWebAuth;
use Abeon\SDK\Panel\EnsureAppEntitled;
use Abeon\SDK\Panel\Panel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

abstract class PanelTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AbeonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('abeon.service.name', 'panel');
        $app['config']->set('abeon.app_descriptor.name', 'panel');
        $app['config']->set('abeon.auth.url', 'http://localhost:8138');
        $app['config']->set('abeon.auth.ui_url', 'http://localhost:8140');
        $app['config']->set('abeon.unified.url', 'http://localhost:8141');
        $app['config']->set('abeon.health.checks', []);

        EncryptCookies::except(PlatformCookies::names());

        foreach (array_reverse(Panel::WEB_MIDDLEWARE) as $middleware) {
            $app->make(Kernel::class)->prependMiddlewareToGroup('web', $middleware);
        }
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function (): void {
            Route::middleware([AbeonWebAuth::class, EnsureAppEntitled::class])->group(function (): void {
                Route::get('/', fn (Request $request) => response()->json(Panel::props($request)));
                Route::get('/settings', fn () => 'settings');
            });

            Panel::routes();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
