<?php

declare(strict_types=1);

namespace Abeon\SDK;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\AuthMiddleware;
use Abeon\SDK\Auth\JwksClient;
use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\Auth\PermissionsDeclarator;
use Abeon\SDK\Auth\PermissionsServiceProvider as PermissionsBridge;
use Abeon\SDK\Client\ServiceClient;
use Abeon\SDK\Client\ServiceTokenProvider;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Health\DbCheck;
use Abeon\SDK\Health\HealthController;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Http\ProblemDetailsRenderer;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Logging\JsonFormatter;
use Abeon\SDK\Services\Commands\RegisterCommand;
use Abeon\SDK\Services\ServiceRegistry;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AbeonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/abeon.php', 'abeon');

        $this->registerCore();
        $this->registerAuth();
        $this->registerClient();
        $this->registerServices();
        $this->registerHealth();
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
        $router->aliasMiddleware('abeon.auth', AuthMiddleware::class);

        $this->attachPermissionsBridge();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RegisterCommand::class,
            ]);
        }
    }

    private function registerCore(): void
    {
        $this->app->scoped(CorrelationContext::class);
        $this->app->scoped(AuthContext::class);

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
    }

    private function registerAuth(): void
    {
        $this->app->singleton(JwksClient::class, function ($app) {
            return new JwksClient(
                http:    $app->make(HttpFactory::class),
                cache:   $app->make(CacheRepository::class),
                jwksUrl: $app->make(AbeonConfig::class)->authJwksUrl(),
            );
        });

        $this->app->singleton(JwtValidator::class);
        $this->app->singleton(PermissionsBridge::class);
        $this->app->singleton(PermissionsDeclarator::class);
    }

    private function registerClient(): void
    {
        $this->app->singleton(ServiceTokenProvider::class);

        $this->app->singleton(ServiceClient::class, function ($app) {
            return new ServiceClient(
                http:        $app->make(HttpFactory::class),
                tokens:      $app->make(ServiceTokenProvider::class),
                correlation: $app->make(CorrelationContext::class),
                config:      $app->make(AbeonConfig::class),
            );
        });
    }

    private function registerServices(): void
    {
        $this->app->singleton(ServiceRegistry::class);
    }

    private function registerHealth(): void
    {
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

        $this->app->bind('abeon.health.check.db', function ($app) {
            return new DbCheck($app->make(ConnectionInterface::class));
        });
    }

    private function attachPermissionsBridge(): void
    {
        $this->app->resolving(GateContract::class, function (GateContract $gate, $app): void {
            $app->make(PermissionsBridge::class)->attach($gate);
        });
    }

    private function configPath(string $file): string
    {
        if (function_exists('config_path')) {
            return config_path($file);
        }

        return $this->app->basePath('config/'.$file);
    }
}
