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

final class EventConsumerVersionTest extends TestCase
{
    public function test_a_one_point_x_event_reaches_a_plain_handler(): void
    {
        $handler = $this->recordingHandler();

        $outcome = $this->deliver([$handler], '1.3');

        $this->assertSame('ack', $outcome);
        $this->assertSame(['1.3'], $handler->seen);
    }

    public function test_a_two_point_zero_event_is_dead_lettered_when_no_handler_accepts_it(): void
    {
        $handler = $this->recordingHandler();

        $outcome = $this->deliver([$handler], '2.0');

        $this->assertSame('nack', $outcome);
        $this->assertSame([], $handler->seen);
    }

    public function test_an_unparseable_version_is_dead_lettered(): void
    {
        $handler = $this->recordingHandler();

        $this->assertSame('nack', $this->deliver([$handler], 'latest'));
        $this->assertSame([], $handler->seen);
    }

    private function recordingHandler(): RecordingHandler
    {
        return new RecordingHandler();
    }

    /**
     * @param  list<EventHandler>  $handlers
     * @param  array<string, mixed>  $data
     */
    private function deliver(array $handlers, string $version, array $data = []): string
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
        ))->withHandlers($handlers);

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
