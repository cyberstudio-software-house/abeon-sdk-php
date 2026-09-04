<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\RabbitMq;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * How a DSN names a vhost.
 *
 * `amqp://guest:guest@host:5672/` — the trailing slash every runbook writes — used to
 * parse to the *empty* vhost, and RabbitMQ answers `NOT_ALLOWED - vhost  not found`.
 * The only way to connect was to leave the slash off, which no example does. Nothing
 * caught it because nothing on this platform had connected to a broker.
 */
final class RabbitMqDsnTest extends TestCase
{
    #[DataProvider('dsnCases')]
    public function test_vhost_from_dsn(string $dsn, string $expected): void
    {
        $rabbit = new RabbitMq(new AbeonConfig(new Repository(['abeon' => ['events' => ['dsn' => $dsn]]])));

        $method = new ReflectionMethod(RabbitMq::class, 'vhostFrom');

        $this->assertSame($expected, $method->invoke($rabbit, (array) parse_url($dsn)));
    }

    /** @return array<string, array{string, string}> */
    public static function dsnCases(): array
    {
        return [
            'trailing slash is the default vhost' => ['amqp://guest:guest@rabbit:5672/', '/'],
            'no path at all is the default vhost' => ['amqp://guest:guest@rabbit:5672', '/'],
            'a named vhost'                       => ['amqp://guest:guest@rabbit:5672/abeon', 'abeon'],
            // A vhost may legally be named with a leading slash, so exactly one is trimmed.
            'a vhost whose name starts with /'    => ['amqp://guest:guest@rabbit:5672//scoped', '/scoped'],
        ];
    }
}
