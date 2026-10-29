<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Files;

use Abeon\SDK\AbeonServiceProvider;
use Abeon\SDK\Client\ServiceTokenProvider;
use Orchestra\Testbench\TestCase;

/**
 * The files module in a Laravel application: a real MariaDB with the module's migrations, a
 * service token that is never checked, and AbeonUnified left for each test to fake.
 */
abstract class FilesTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AbeonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $setting = static fn (string $name, string $default): string => (string) (getenv('ABEON_TEST_DB_'.$name) ?: $default);

        $app['config']->set('database.default', 'mariadb');
        $app['config']->set('database.connections.mariadb', [
            'driver'    => 'mariadb',
            'host'      => $setting('HOST', '127.0.0.1'),
            'port'      => $setting('PORT', '3306'),
            'database'  => $setting('DATABASE', 'abeon_sdk_test'),
            'username'  => $setting('USERNAME', 'abeon_sdk'),
            'password'  => $setting('PASSWORD', 'abeon_sdk'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
        ]);
        $app['config']->set('abeon.service.name', 'cms');
        $app['config']->set('abeon.services.unified.url', 'http://unified.test');
        $app['config']->set('abeon.client.max_retries', 0);
        $app['config']->set('abeon.storage.public_base_url', 'http://storage.test/abeon-org-1');
        $app['config']->set('abeon.files.multipart_threshold', 5000);
        $app['config']->set('abeon.files.part_size', 2000);

        $app->instance(ServiceTokenProvider::class, new class extends ServiceTokenProvider
        {
            public function __construct()
            {
            }

            public function token(?int $orgId = null): string
            {
                return 'fake-service-token';
            }
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropAllTables();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/files-migrations');
    }
}
