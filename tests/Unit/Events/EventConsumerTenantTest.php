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

    /**
     * ADR-0031 §6. A bound instance has its own queue, so it receives every
     * organisation's events; handing another client's to its handlers would write them
     * into this client's database.
     */
    public function test_a_bound_instance_skips_another_organisations_event(): void
    {
        [$consumer, , $handler] = $this->consumerWithRecordingHandler(instanceOrgId: 7);

        $this->deliver($consumer, orgId: 8);

        $this->assertFalse($handler->handled);
    }

    public function test_a_bound_instance_handles_its_own_organisations_event(): void
    {
        [$consumer, , $handler] = $this->consumerWithRecordingHandler(instanceOrgId: 7);

        $this->deliver($consumer, orgId: 7);

        $this->assertSame(7, $handler->seenOrgId);
    }

    public function test_a_bound_instance_still_receives_platform_level_events(): void
    {
        [$consumer, , $handler] = $this->consumerWithRecordingHandler(instanceOrgId: 7);

        $this->deliver($consumer, orgId: null);

        $this->assertTrue($handler->handled);
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

    public function test_a_delivery_that_matches_no_handler_is_not_silent(): void
    {
        // The broker matched this message to a queue we bound; `matches()` then re-derived
        // the same decision in PHP and disagreed. Those two implementations have diverged
        // before — `#` meant "one or more segments" here and "zero or more" to AMQP — and
        // the message is acked either way. Silence is what makes such a divergence cost a
        // debugging session instead of a log line.
        $logger  = new class extends \Psr\Log\AbstractLogger
        {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::WARNING) {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $tenants  = new TenantContext(new AuthContext());
        $consumer = (new EventConsumer(
            rabbit:      $this->createMock(RabbitMq::class),
            processed:   $this->neverProcessed(),
            correlation: new CorrelationContext(),
            tenants:     $tenants,
            container:   new Container(),
            config:      new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => 'unified']]])),
            logger:      $logger,
        ))->withHandlers([]);

        $this->deliver($consumer, orgId: 7);

        $this->assertSame(['event-consumer.no-matching-handler'], $logger->warnings);
    }

    /**
     * Every subscription runs in this one loop on one channel, so a handler that waits holds
     * up every other event the service consumes — and from outside, slow and hung look the
     * same: no dead letters, no errors, a queue that stops moving.
     */
    public function test_a_slow_handler_says_so(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger
        {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $warnings = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === \Psr\Log\LogLevel::WARNING) {
                    $this->warnings[] = [(string) $message, $context];
                }
            }
        };

        $handler = new class implements EventHandler
        {
            public function subscribesTo(): array
            {
                return ['auth.org.created'];
            }

            public function handle(Event $event): void
            {
                usleep(120_000);
            }
        };

        $consumer = (new EventConsumer(
            rabbit:      $this->createMock(RabbitMq::class),
            processed:   $this->neverProcessed(),
            correlation: new CorrelationContext(),
            tenants:     new TenantContext(new AuthContext()),
            container:   new Container(),
            config:      new AbeonConfig(new Repository(['abeon' => [
                'service' => ['name' => 'unified'],
                'events'  => ['consumer' => ['slow_handler_seconds' => 0.1]],
            ]])),
            logger:      $logger,
        ))->withHandlers([$handler]);

        $this->deliver($consumer, orgId: 7);

        $this->assertSame('event-consumer.handler-slow', $logger->warnings[0][0] ?? null);
        $this->assertSame('auth.org.created', $logger->warnings[0][1]['event_type'] ?? null);
    }

    /**
     * ADR-0002 says a message that does not conform to `_envelope.json` is refused. The check
     * was two of its nine required fields, and everything else was read with a cast — so
     * `org_id: "acme"` became organisation 0 and a missing one became a platform-level event,
     * both silently, and a handler wrote them as if they were meant.
     */
    public function test_a_malformed_envelope_is_refused_rather_than_cast(): void
    {
        foreach ([
            'an org_id that is not a number' => ['org_id' => 'acme'],
            'an org_id that is a boolean'    => ['org_id' => true],
            'an org_id that is zero'         => ['org_id' => 0],
            'no org_id at all'               => ['org_id' => '__absent__'],
            'no source'                      => ['source' => '__absent__'],
            'no timestamp'                   => ['timestamp' => '__absent__'],
            'data that is not an object'     => ['data' => 'nope'],
        ] as $case => $override) {
            [$consumer, , $handler] = $this->consumerWithRecordingHandler();

            $channel = $this->createMock(AMQPChannel::class);
            $channel->expects($this->once())->method('basic_nack');
            $channel->expects($this->never())->method('basic_ack');

            $this->deliver($consumer, orgId: 7, overrides: $override, channel: $channel);

            $this->assertFalse($handler->handled, "handled a message with {$case}");
        }
    }

    /**
     * The whole of the consumer's idempotency: every other test in this file stubs
     * `isProcessed()` to false and none asserts `markProcessed()`, so both halves could be
     * deleted with the suite green — and every redelivery would re-run every handler.
     */
    public function test_a_redelivered_message_is_acked_without_running_a_handler(): void
    {
        $tenants = new TenantContext(new AuthContext());

        $handler = new class implements EventHandler
        {
            public bool $handled = false;

            public function subscribesTo(): array
            {
                return ['auth.org.created'];
            }

            public function handle(Event $event): void
            {
                $this->handled = true;
            }
        };

        $processed = $this->createMock(ProcessedEvents::class);
        $processed->method('isProcessed')->willReturn(true);
        $processed->expects($this->never())->method('markProcessed');

        $consumer = (new EventConsumer(
            rabbit:      $this->createMock(RabbitMq::class),
            processed:   $processed,
            correlation: new CorrelationContext(),
            tenants:     $tenants,
            container:   new Container(),
            config:      new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => 'unified']]])),
        ))->withHandlers([$handler]);

        $channel = $this->createMock(AMQPChannel::class);
        $channel->expects($this->once())->method('basic_ack');

        $this->deliver($consumer, orgId: 7, channel: $channel);

        $this->assertFalse($handler->handled);
    }

    public function test_a_handled_message_is_written_down(): void
    {
        $tenants = new TenantContext(new AuthContext());

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
        $processed->method('isProcessed')->willReturn(false);
        $processed->expects($this->once())->method('markProcessed')->with('e1', 'auth.org.created');

        $consumer = (new EventConsumer(
            rabbit:      $this->createMock(RabbitMq::class),
            processed:   $processed,
            correlation: new CorrelationContext(),
            tenants:     $tenants,
            container:   new Container(),
            config:      new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => 'unified']]])),
        ))->withHandlers([$handler]);

        $this->deliver($consumer, orgId: 7);
    }

    /**
     * @return array{0: EventConsumer, 1: TenantContext, 2: object}
     */
    private function consumerWithRecordingHandler(?int $instanceOrgId = null): array
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

        return [$this->consumer($tenants, $handler, $instanceOrgId), $tenants, $handler];
    }

    private function consumer(TenantContext $tenants, EventHandler $handler, ?int $instanceOrgId = null): EventConsumer
    {
        $consumer = new EventConsumer(
            rabbit:      $this->createMock(RabbitMq::class),
            processed:   $this->neverProcessed(),
            correlation: new CorrelationContext(),
            tenants:     $tenants,
            container:   new Container(),
            config:      new AbeonConfig(new Repository(['abeon' => ['service' => [
                'name'   => 'unified',
                'org_id' => $instanceOrgId === null ? null : (string) $instanceOrgId,
            ]]])),
        );

        return $consumer->withHandlers([$handler]);
    }

    private function neverProcessed(): ProcessedEvents
    {
        $processed = $this->createMock(ProcessedEvents::class);
        $processed->method('isProcessed')->willReturn(false);

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function deliver(EventConsumer $consumer, ?int $orgId, array $overrides = [], ?AMQPChannel $channel = null): void
    {
        $envelope = array_replace([
            'event_id'   => 'e1',
            'event_type' => 'auth.org.created',
            'timestamp'  => '2026-09-04T10:00:00.000Z',
            'source'     => 'auth',
            'version'    => '1.0',
            'org_id'     => $orgId,
            'actor'      => ['type' => 'system'],
            'data'       => ['org_id' => $orgId ?? 0],
            'metadata'   => [],
        ], $overrides);

        foreach ($overrides as $key => $value) {
            if ($value === '__absent__') {
                unset($envelope[$key]);
            }
        }

        $message = new AMQPMessage((string) json_encode($envelope));
        $message->setDeliveryTag(1);

        $method = new ReflectionMethod(EventConsumer::class, 'onMessage');
        $method->invoke($consumer, $message, $channel ?? $this->createMock(AMQPChannel::class));
    }
}
