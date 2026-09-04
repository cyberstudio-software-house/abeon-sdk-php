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
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A handler runs **inside the organisation the envelope names** (ADR-0002 / ADR-0018).
 *
 * `TenantContext`'s own docblock has always listed `runFor($event->orgId, ...)` as the
 * way a consumer enters a tenant, and until 2026-09-04 nothing called it. A consumer has
 * no request and therefore no `AuthContext` to fall back on, so every handler ran with
 * no organisation at all: `require()` threw, and a handler that instead read "no tenant"
 * as "no filter" would have queried every organisation's rows.
 */
final class EventConsumerTenantTest extends TestCase
{
    public function test_a_handler_runs_inside_the_envelopes_organisation(): void
    {
        [$consumer, $tenants, $handler] = $this->consumerWithRecordingHandler();

        $this->deliver($consumer, orgId: 7);

        $this->assertSame(7, $handler->seenOrgId);
        $this->assertNull($tenants->current(), 'The tenant must not outlive the message.');
    }

    public function test_a_platform_level_event_reaches_the_handler_with_no_tenant(): void
    {
        // Null is passed through rather than refused. It means "no organisation", and it
        // is the handler that knows whether it needs one — refusing here would drop
        // legitimately untenanted events, such as registry self-registration.
        [$consumer, , $handler] = $this->consumerWithRecordingHandler();

        $this->deliver($consumer, orgId: null);

        $this->assertTrue($handler->handled);
        $this->assertNull($handler->seenOrgId);
    }

    public function test_a_failing_handler_does_not_leave_the_worker_pinned(): void
    {
        // A consumer process handles one organisation's message after another's. A
        // tenant that survived a thrown handler would be inherited by the next message,
        // which is the worst version of this bug: silent, and cross-tenant.
        $tenants = new TenantContext(new AuthContext());
        $handler = new class implements EventHandler
        {
            public function subscribesTo(): array
            {
                return ['auth.org.created'];
            }

            public function handle(Event $event): void
            {
                throw new \RuntimeException('boom');
            }
        };

        $consumer = $this->consumer($tenants, $handler);
        $this->deliver($consumer, orgId: 7);

        $this->assertNull($tenants->current());
    }

    /**
     * @return array{0: EventConsumer, 1: TenantContext, 2: object}
     */
    private function consumerWithRecordingHandler(): array
    {
        $tenants = new TenantContext(new AuthContext());

        $handler = new class($tenants) implements EventHandler
        {
            public ?int $seenOrgId = null;

            public bool $handled = false;

            public function __construct(private readonly TenantContext $tenants)
            {
            }

            public function subscribesTo(): array
            {
                return ['auth.org.created'];
            }

            public function handle(Event $event): void
            {
                $this->handled   = true;
                $this->seenOrgId = $this->tenants->current();
            }
        };

        return [$this->consumer($tenants, $handler), $tenants, $handler];
    }

    private function consumer(TenantContext $tenants, EventHandler $handler): EventConsumer
    {
        $consumer = new EventConsumer(
            rabbit:      $this->createMock(RabbitMq::class),
            processed:   $this->neverProcessed(),
            correlation: new CorrelationContext(),
            tenants:     $tenants,
            container:   new Container(),
            config:      new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => 'unified']]])),
        );

        return $consumer->withHandlers([$handler]);
    }

    private function neverProcessed(): ProcessedEvents
    {
        $processed = $this->createMock(ProcessedEvents::class);
        $processed->method('isProcessed')->willReturn(false);

        return $processed;
    }

    private function deliver(EventConsumer $consumer, ?int $orgId): void
    {
        $envelope = [
            'event_id'   => 'e1',
            'event_type' => 'auth.org.created',
            'timestamp'  => '2026-09-04T10:00:00.000Z',
            'source'     => 'auth',
            'version'    => '1.0',
            'org_id'     => $orgId,
            'actor'      => ['type' => 'system'],
            'data'       => ['org_id' => $orgId ?? 0],
            'metadata'   => [],
        ];

        $message = new AMQPMessage((string) json_encode($envelope));
        $message->setDeliveryTag(1);

        $method = new ReflectionMethod(EventConsumer::class, 'onMessage');
        $method->invoke($consumer, $message, $this->createMock(AMQPChannel::class));
    }
}
