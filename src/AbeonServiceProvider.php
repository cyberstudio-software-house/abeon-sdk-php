<?php

declare(strict_types=1);

namespace Abeon\SDK;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Health\DbCheck;
use Abeon\SDK\Health\HealthController;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Http\ProblemDetailsRenderer;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Logging\JsonFormatter;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AbeonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/abeon.php', 'abeon');

        // Core layer — request-scoped state holders.
        $this->app->scoped(CorrelationContext::class);
        $this->app->scoped(AuthContext::class);

        // Core layer — singletons.
        $this->app->singleton(AbeonConfig::class, function ($app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            return new AbeonConfig($config);
        });

        $this->app->singleton(ProblemDetailsRenderer::class, function ($app) {
            return new ProblemDetailsRenderer(debug: (bool) $app->make('config')->get('app.debug', false));
        });

        $this->app->singleton(JsonFormatter::class, function ($app) {
            $config = $app->make(AbeonConfig::class);

            return new JsonFormatter(
                context:          $app->make(CorrelationContext::class),
                serviceName:      $config->serviceName(),
                correlationField: $config->correlationField(),
            );
        });

        // Health — controller resolves checks declared in config.
        $this->app->bind(HealthController::class, function ($app) {
            $names  = $app->make(AbeonConfig::class)->healthChecks();
            $checks = [];

            foreach ($names as $name) {
                $abstract = "abeon.health.check.{$name}";
                if ($app->bound($abstract)) {
                    $checks[] = $app->make($abstract);
                }
            }

            return new HealthController($checks);
        });

        // Built-in checks.
        $this->app->bind('abeon.health.check.db', function ($app) {
            return new DbCheck($app->make(ConnectionInterface::class));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/abeon.php' => $this->configPath('abeon.php'),
        ], 'abeon-config');

        $this->loadRoutesFrom(__DIR__.'/../routes/health.php');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('abeon.correlation', CorrelationIdMiddleware::class);
    }

    private function configPath(string $file): string
    {
        if (function_exists('config_path')) {
            return config_path($file);
        }

        return $this->app->basePath('config/'.$file);
    }
}
