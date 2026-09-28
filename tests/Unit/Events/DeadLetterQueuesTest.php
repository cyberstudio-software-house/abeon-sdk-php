<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\Event;
use Abeon\SDK\Events\EventConsumer;
use Abeon\SDK\Events\EventHandler;
use Abeon\SDK\Events\ProcessedEvents;
use Abeon\SDK\Events\RabbitMq;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Tenancy\TenantContext;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * The queues a refused event ends up in (ADR-0002).
 *
 * They are named here and nowhere else, and until `abeon:events:dlq` nothing outside the
 * broker's own console could even name them — so a handler broken by a deploy piled events
 * up somewhere no tool on the platform could see.
 */
final class DeadLetterQueuesTest extends TestCase
{
    public function test_every_subscription_has_a_dead_letter_queue(): void
    {
        $this->assertSame(
            ['auth.org.created' => 'unified.auth.org.created.dlq'],
            $this->consumer()->deadLetterQueues(),
        );
    }

    /**
     * An instance bound to one client has a queue of its own (ADR-0031 §6), so its dead
     * letters are that client's — replaying them into another instance's queue would put
     * one client's events into another's database.
     */
    public function test_an_instance_has_its_own(): void
    {
        $this->assertSame(
            ['auth.org.created' => 'unified-org7.auth.org.created.dlq'],
            $this->consumer(instanceOrgId: 7)->deadLetterQueues(),
        );
    }

    private function consumer(?int $instanceOrgId = null): EventConsumer
    {
        $handler = new class implements EventHandler
        {
            public function subscribesTo(): array
            {
                return ['auth.org.created'];
            }

            public function handle(Event $event): void
            {
            }
        };

        $processed = $this->createMock(ProcessedEvents::class);

        $consumer = new EventConsumer(
            rabbit:      $this->createMock(RabbitMq::class),
            processed:   $processed,
            correlation: new CorrelationContext(),
            tenants:     new TenantContext(new AuthContext()),
            container:   new Container(),
            config:      new AbeonConfig(new Repository(['abeon' => ['service' => [
                'name'   => 'unified',
                'org_id' => $instanceOrgId === null ? null : (string) $instanceOrgId,
            ]]])),
        );

        return $consumer->withHandlers([$handler]);
    }
}
