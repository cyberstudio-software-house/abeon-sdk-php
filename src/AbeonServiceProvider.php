<?php

declare(strict_types=1);

namespace Abeon\SDK;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\AuthMiddleware;
use Abeon\SDK\Auth\Endpoints\PreferencesController as AuthPreferencesController;
use Abeon\SDK\Auth\Endpoints\UserController as AuthUserController;
use Abeon\SDK\Auth\JwksClient;
use Abeon\SDK\Auth\JwtValidator;
use Abeon\SDK\Auth\PermissionsServiceProvider as PermissionsBridge;
use Abeon\SDK\Auth\ServiceAuthMiddleware;
use Abeon\SDK\Broadcasting\BroadcastingAuthController;
use Abeon\SDK\Client\ServiceClient;
use Abeon\SDK\Client\ServiceTokenProvider;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\Commands\ConsumeCommand;
use Abeon\SDK\Events\Commands\DeadLetterCommand;
use Abeon\SDK\Events\Commands\OutboxDrainCommand;
use Abeon\SDK\Events\Commands\PruneEventsCommand;
use Abeon\SDK\Events\EnvelopeBuilder;
use Abeon\SDK\Events\EventCatalog;
use Abeon\SDK\Events\EventConsumer;
use Abeon\SDK\Events\EventPublisher;
use Abeon\SDK\Events\OutboxDrainer;
use Abeon\SDK\Events\OutboxPublisher;
use Abeon\SDK\Events\ProcessedEvents;
use Abeon\SDK\Events\RabbitMq;
use Abeon\SDK\Events\SchemaDiscovery;
use Abeon\SDK\Health\DbCheck;
use Abeon\SDK\Health\HealthController;
use Abeon\SDK\Health\OutboxLagCheck;
use Abeon\SDK\Health\RabbitMqCheck;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Http\Cors;
use Abeon\SDK\Http\ProblemDetailsRenderer;
use Abeon\SDK\Http\TrustedProxies;
use Abeon\SDK\Http\VersionHeadersMiddleware;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Logging\JsonFormatter;
use Abeon\SDK\Storage\ObjectStore;
use Abeon\SDK\Support\PathPrefix;
use Abeon\SDK\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->registerEvents();
        $this->registerHealth();
    }

    public function boot(): void
    {
        // Every Abeon service sits behind a TLS-terminating ingress, so believing the
        // forwarded headers is a platform property rather than a per-service choice.
        // Here rather than in each `bootstrap/app.php`: the middleware is already in
        // Laravel's default global stack and reads these statics at request time, and
        // `withMiddleware()` runs before the config repository exists.
        TrustedProxies::apply();

        // **The readiness probe's rate limit, keyed on the address.**
        //
        // `throttle:60,1` — the unnamed form — builds its key from
        // `ThrottleRequests::resolveRequestSignature()`, which calls `$request->user()` and so
        // boots the configured guard. Two services on this platform declare a guard with no
        // driver on purpose, because identity comes from the token and reaching for
        // `Auth::user()` is a bug they want to hear about immediately. The probe — which no
        // caller ever authenticates — then threw on guard resolution and answered 500, which a
        // kubelet reads as a pod that is not ready.
        //
        // Named and keyed on the address, nothing resolves a user. The limit is unchanged and
        // sits well above any sane probe interval; it is there so that anybody who can reach
        // the pod cannot turn readiness into broker connection churn.
        RateLimiter::for('abeon-health', static fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->ip() ?? 'unknown'));

        $this->publishes([
            __DIR__.'/../config/abeon.php' => $this->configPath('abeon.php'),
        ], 'abeon-config');

        $this->publishes([
            __DIR__.'/../database/migrations/' => $this->databasePath('migrations'),
        ], 'abeon-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/health.php');

        /** @var Router $router */
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('abeon.correlation', CorrelationIdMiddleware::class);
        $router->aliasMiddleware('abeon.auth', AuthMiddleware::class);
        $router->aliasMiddleware('abeon.service', ServiceAuthMiddleware::class);
        $router->aliasMiddleware('abeon.version', VersionHeadersMiddleware::class);
        $router->aliasMiddleware('abeon.cors', Cors::class);

        $this->attachPermissionsBridge();

        if ($this->app->runningInConsole()) {
            $this->commands([
                OutboxDrainCommand::class,
                DeadLetterCommand::class,
                PruneEventsCommand::class,
                ConsumeCommand::class,
            ]);
        }
    }

    private function registerCore(): void
    {
        $this->app->scoped(CorrelationContext::class);
        $this->app->scoped(AuthContext::class);

        // Scoped, like AuthContext — a singleton would carry one request's
        // organisation into the next (ADR-0018).
        $this->app->scoped(TenantContext::class, function ($app) {
            return new TenantContext(
                $app->make(AuthContext::class),
                $app->make(AbeonConfig::class)->instanceOrgId(),
            );
        });

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
            $config = $app->make(AbeonConfig::class);

            return new JwksClient(
                http:       $app->make(HttpFactory::class),
                cache:      $app->make(CacheRepository::class),
                jwksUrl:    $config->authJwksUrl(),
                ttlSeconds: $config->authJwksCacheTtl(),
            );
        });

        $this->app->singleton(JwtValidator::class);
        $this->app->singleton(PermissionsBridge::class);

        // Endpoint base controllers — used directly by Auth service and as
        // reference implementations elsewhere.
        $this->app->singleton(AuthUserController::class);
        $this->app->singleton(AuthPreferencesController::class);

        // BroadcastingAuthController needs the active Broadcaster driver.
        // We resolve lazily through BroadcastManager so services that don't
        // configure broadcasting never pay the cost.
        $this->app->singleton(BroadcastingAuthController::class, function ($app) {
            $manager = $app->make(\Illuminate\Broadcasting\BroadcastManager::class);

            return new BroadcastingAuthController(
                jwt:         $app->make(JwtValidator::class),
                authContext: $app->make(AuthContext::class),
                config:      $app->make(AbeonConfig::class),
                broadcaster: $manager->connection(),
            );
        });
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
                // Closure, not an instance: this binding is a singleton and
                // TenantContext is scoped. Resolving per call keeps each request's
                // organisation its own (ADR-0016).
                tenantResolver: static fn (): TenantContext => $app->make(TenantContext::class),
            );
        });
    }

    private function registerServices(): void
    {
        $this->app->singleton(PathPrefix::class);
        $this->app->singleton(ObjectStore::class);
    }

    private function registerEvents(): void
    {
        // **Scoped, not singleton.** `EnvelopeBuilder` holds `AuthContext`,
        // `TenantContext` and `CorrelationContext`, all three of which are scoped — so a
        // singleton captures the first request's user, organisation and correlation id
        // and stamps them on every event the process publishes afterwards. Invisible
        // under php-fpm, wrong under Octane and in any long-lived worker.
        //
        // `OutboxPublisher` goes with it: a singleton holding a scoped collaborator pins
        // it just as effectively one level up.
        $this->app->scoped(EnvelopeBuilder::class);

        $this->app->scoped(OutboxPublisher::class, function ($app) {
            return new OutboxPublisher(
                builder: $app->make(EnvelopeBuilder::class),
                db:      $app->make(DatabaseManager::class),
                config:  $app->make(AbeonConfig::class),
            );
        });
        $this->app->alias(OutboxPublisher::class, EventPublisher::class);

        $this->app->singleton(ProcessedEvents::class);
        $this->app->singleton(RabbitMq::class);

        $this->app->singleton(OutboxDrainer::class, function ($app) {
            return new OutboxDrainer(
                db:     $app->make(DatabaseManager::class),
                rabbit: $app->make(RabbitMq::class),
                config: $app->make(AbeonConfig::class),
                logger: $app->bound(\Psr\Log\LoggerInterface::class)
                    ? $app->make(\Psr\Log\LoggerInterface::class)
                    : null,
            );
        });

        $this->app->singleton(EventConsumer::class, function ($app) {
            return new EventConsumer(
                rabbit:      $app->make(RabbitMq::class),
                processed:   $app->make(ProcessedEvents::class),
                correlation: $app->make(CorrelationContext::class),
                tenants:     $app->make(TenantContext::class),
                container:   $app,
                config:      $app->make(AbeonConfig::class),
                logger:      $app->bound(\Psr\Log\LoggerInterface::class)
                    ? $app->make(\Psr\Log\LoggerInterface::class)
                    : null,
            );
        });

        $this->app->singleton(SchemaDiscovery::class, function ($app) {
            return new SchemaDiscovery(
                vendorPath: $app->basePath('vendor'),
                logger: $app->bound(\Psr\Log\LoggerInterface::class)
                    ? $app->make(\Psr\Log\LoggerInterface::class)
                    : null,
            );
        });
        $this->app->singleton(EventCatalog::class);
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

                    continue;
                }

                // A typo in `ABEON_HEALTH_CHECKS` — `outbox-lag` for `outbox_lag` — used to be
                // skipped in silence, so readiness answered `ok` while checking less than the
                // operator asked for. Said out loud rather than thrown: a probe that refuses to
                // boot over a misspelt check name takes the pod down for a configuration
                // mistake whose whole cost is the check not running.
                if ($app->bound(\Psr\Log\LoggerInterface::class)) {
                    $app->make(\Psr\Log\LoggerInterface::class)->warning('abeon.health.unknown-check', [
                        'check' => $name,
                    ]);
                }
            }

            return new HealthController($checks);
        });

        $this->app->bind('abeon.health.check.db', function ($app) {
            return new DbCheck($app->make(ConnectionInterface::class));
        });

        $this->app->bind('abeon.health.check.rabbitmq', function ($app) {
            return new RabbitMqCheck($app->make(RabbitMq::class));
        });

        $this->app->bind('abeon.health.check.outbox_lag', function ($app) {
            return new OutboxLagCheck(
                db:     $app->make(DatabaseManager::class),
                config: $app->make(AbeonConfig::class),
            );
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

    private function databasePath(string $folder): string
    {
        if (function_exists('database_path')) {
            return database_path($folder);
        }

        return $this->app->basePath('database/'.$folder);
    }
}
