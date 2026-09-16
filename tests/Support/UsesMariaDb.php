<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * A MariaDB connection for tests that need a real database, emptied before each test.
 *
 * The suite used to give every test its own in-memory SQLite database, which was empty
 * by construction and could not show anything production would do differently: row
 * locks, native UUID columns, strict mode. Production is MariaDB (architecture §8.1),
 * so the tests are too. One shared database means each test has to start from nothing,
 * hence every table is dropped here rather than relied on to be absent.
 *
 * Settings come from `ABEON_TEST_DB_*`; the defaults match `db-up.sh` in the suite root.
 */
trait UsesMariaDb
{
    private function connectTestDatabase(Container $container): Capsule
    {
        $capsule = new Capsule($container);
        $capsule->addConnection([
            'driver'    => 'mariadb',
            'host'      => self::testDatabaseSetting('HOST', '127.0.0.1'),
            'port'      => self::testDatabaseSetting('PORT', '3306'),
            'database'  => self::testDatabaseSetting('DATABASE', 'abeon_sdk_test'),
            'username'  => self::testDatabaseSetting('USERNAME', 'abeon_sdk'),
            'password'  => self::testDatabaseSetting('PASSWORD', 'abeon_sdk'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
            'timezone'  => '+00:00',
        ]);

        $capsule->getConnection()->getSchemaBuilder()->dropAllTables();

        return $capsule;
    }

    private static function testDatabaseSetting(string $name, string $default): string
    {
        $value = getenv('ABEON_TEST_DB_'.$name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
