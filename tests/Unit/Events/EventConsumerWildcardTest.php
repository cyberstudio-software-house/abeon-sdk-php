<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Events\EventConsumer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Exercises `EventConsumer::matches()` directly via reflection — the method
 * is private but the AMQP wildcard semantics are a public contract worth
 * locking down with explicit test cases. See ADR-0002.
 */
final class EventConsumerWildcardTest extends TestCase
{
    private function invokeMatches(string $routingKey, string $pattern): bool
    {
        $consumer = $this->createMock(EventConsumer::class);
        $method = new ReflectionMethod(EventConsumer::class, 'matches');
        return (bool) $method->invoke($consumer, $routingKey, $pattern);
    }

    #[DataProvider('matchCases')]
    public function test_pattern_match(string $routingKey, string $pattern, bool $expected): void
    {
        $this->assertSame($expected, $this->invokeMatches($routingKey, $pattern));
    }

    public static function matchCases(): array
    {
        return [
            // Exact match
            'exact'                          => ['crm.contact.created', 'crm.contact.created', true],
            'exact mismatch'                 => ['crm.contact.created', 'crm.contact.updated', false],

            // * = exactly one segment
            'star matches one segment'       => ['crm.contact.created', 'crm.*.created', true],
            'star does NOT match two'        => ['crm.contact.sub.created', 'crm.*.created', false],
            'star does NOT match zero'       => ['crm.created', 'crm.*.created', false],

            // # = zero or more segments (AMQP spec — HI-4 fix)
            'hash matches one segment'       => ['crm.contact', 'crm.#', true],
            'hash matches two segments'      => ['crm.contact.created', 'crm.#', true],
            'hash matches many segments'     => ['crm.a.b.c.d', 'crm.#', true],
            'hash inside pattern'            => ['crm.contact.created', 'crm.#.created', true],
        ];
    }
}
