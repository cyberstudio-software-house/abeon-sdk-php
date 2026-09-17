<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\AcceptsEventVersions;
use Abeon\SDK\Events\Event;
use Abeon\SDK\Events\EventConsumer;
use Abeon\SDK\Events\EventHandler;
use Abeon\SDK\Events\EventUpcaster;
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

final class EventConsumerVersionTest extends TestCase
{
    public function test_a_one_point_x_event_reaches_a_plain_handler(): void
    {
        $handler = $this->recordingHandler();

        $outcome = $this->deliver([$handler], [], '1.3');

        $this->assertSame('ack', $outcome);
        $this->assertSame(['1.3'], $handler->seen);
    }

    public function test_a_two_point_zero_event_is_dead_lettered_when_no_handler_accepts_it(): void
    {
        $handler = $this->recordingHandler();

        $outcome = $this->deliver([$handler], [], '2.0');

        $this->assertSame('nack', $outcome);
        $this->assertSame([], $handler->seen);
    }

    public function test_an_unparseable_version_is_dead_lettered(): void
    {
        $handler = $this->recordingHandler();

        $this->assertSame('nack', $this->deliver([$handler], [], 'latest'));
        $this->assertSame([], $handler->seen);
    }

    public function test_an_upcaster_lets_a_one_point_zero_handler_read_a_two_point_zero_event(): void
    {
        $handler = $this->recordingHandler();
        $upcaster = new class implements EventUpcaster
        {
            public function supports(Event $event): bool
            {
                return $event->version === '2.0';
            }

            public function upcast(Event $event): Event
            {
                return new Event(
                    $event->eventId, $event->eventType, $event->timestamp, $event->source, '1.0',
                    $event->orgId, $event->actor, ['name' => $event->data['display_name'] ?? null], $event->metadata,
                );
            }
        };

        $outcome = $this->deliver([$handler], [$upcaster], '2.0', ['display_name' => 'Acme']);

        $this->assertSame('ack', $outcome);
        $this->assertSame(['1.0'], $handler->seen);
        $this->assertSame(['name' => 'Acme'], $handler->data);
    }

    public function test_a_handler_that_declares_version_two_receives_it_unchanged(): void
    {
        $handler = new class extends RecordingHandler implements AcceptsEventVersions
        {
            public function acceptedMajorVersions(): array
            {
                return [1, 2];
            }
        };

        $this->assertSame('ack', $this->deliver([$handler], [], '2.1'));
        $this->assertSame(['2.1'], $handler->seen);
    }

    public function test_an_upcaster_that_never_finishes_is_dead_lettered(): void
    {
        $handler = $this->recordingHandler();
        $loop = new class implements EventUpcaster
        {
            public function supports(Event $event): bool
            {
                return true;
            }

            public function upcast(Event $event): Event
            {
                return $event;
            }
        };

        $this->assertSame('nack', $this->deliver([$handler], [$loop], '1.0'));
        $this->assertSame([], $handler->seen);
    }

    private function recordingHandler(): RecordingHandler
    {
        return new RecordingHandler();
    }

    /**
     * @param  list<EventHandler>  $handlers
     * @param  list<EventUpcaster>  $upcasters
     * @param  array<string, mixed>  $data
     */
    private function deliver(array $handlers, array $upcasters, string $version, array $data = []): string
    {
        $processed = $this->createMock(ProcessedEvents::class);
        $processed->method('isProcessed')->willReturn(false);

        $consumer = (new EventConsumer(
            rabbit:      $this->createMock(RabbitMq::class),
            processed:   $processed,
            correlation: new CorrelationContext(),
            tenants:     new TenantContext(new AuthContext()),
            container:   new Container(),
            config:      new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => 'unified']]])),
        ))->withHandlers($handlers)->withUpcasters($upcasters);

        $message = new AMQPMessage((string) json_encode([
            'event_id'   => 'e1',
            'event_type' => 'auth.org.updated',
            'timestamp'  => '2026-09-17T10:00:00.000Z',
            'source'     => 'auth',
            'version'    => $version,
            'org_id'     => 1,
            'actor'      => ['type' => 'system'],
            'data'       => $data,
            'metadata'   => [],
        ]));
        $message->setDeliveryTag(1);

        $outcome = 'none';
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('basic_ack')->willReturnCallback(function () use (&$outcome): void {
            $outcome = 'ack';
        });
        $channel->method('basic_nack')->willReturnCallback(function () use (&$outcome): void {
            $outcome = 'nack';
        });

        (new ReflectionMethod(EventConsumer::class, 'onMessage'))->invoke($consumer, $message, $channel);

        return $outcome;
    }
}

class RecordingHandler implements EventHandler
{
    /** @var list<string> */
    public array $seen = [];

    /** @var array<string, mixed> */
    public array $data = [];

    public function subscribesTo(): array
    {
        return ['auth.org.updated'];
    }

    public function handle(Event $event): void
    {
        $this->seen[] = $event->version;
        $this->data = $event->data;
    }
}
