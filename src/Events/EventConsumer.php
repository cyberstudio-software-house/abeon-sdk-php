<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Logging\CorrelationContext;
use Illuminate\Contracts\Container\Container;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Subscribes to RabbitMQ queues bound to the main exchange, dispatches
 * each message to handlers tagged 'abeon.event_handler' that declare the
 * matching routing key in subscribesTo().
 *
 * Failed deliveries are nacked without requeue → routed via DLX to the
 * per-queue dead-letter queue for triage.
 */
class EventConsumer
{
    /** @var list<EventHandler> */
    private array $handlers = [];

    private LoggerInterface $logger;

    public function __construct(
        private readonly RabbitMq $rabbit,
        private readonly ProcessedEvents $processed,
        private readonly CorrelationContext $correlation,
        private readonly Container $container,
        private readonly AbeonConfig $config,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param  iterable<EventHandler>  $handlers
     */
    public function withHandlers(iterable $handlers): self
    {
        foreach ($handlers as $handler) {
            $this->handlers[] = $handler;
        }

        return $this;
    }

    public function run(int $maxIterations = 0): void
    {
        $channel = $this->rabbit->channel();
        $channel->basic_qos(0, $this->config->consumerPrefetchCount(), false);

        $subscriptions = $this->subscriptions();
        if ($subscriptions === []) {
            $this->logger->warning('event-consumer.no-subscriptions');

            return;
        }

        $prefix   = $this->config->consumerQueuePrefix();
        $exchange = $this->config->rabbitMqExchange();
        $dlx      = $this->config->rabbitMqDeadLetterExchange();

        foreach ($subscriptions as $routingKey) {
            $queue = "{$prefix}.{$routingKey}";
            $dlq   = "{$queue}.dlq";

            $channel->queue_declare(
                queue:       $queue,
                passive:     false,
                durable:     true,
                exclusive:   false,
                auto_delete: false,
                nowait:      false,
                arguments:   new \PhpAmqpLib\Wire\AMQPTable([
                    'x-dead-letter-exchange' => $dlx,
                ]),
            );
            $channel->queue_bind($queue, $exchange, $routingKey);

            $channel->queue_declare(
                queue: $dlq, passive: false, durable: true, exclusive: false, auto_delete: false,
            );
            $channel->queue_bind($dlq, $dlx, $routingKey);

            $channel->basic_consume(
                queue:        $queue,
                consumer_tag: '',
                no_local:     false,
                no_ack:       false,
                exclusive:    false,
                nowait:       false,
                callback:     fn (AMQPMessage $msg) => $this->onMessage($msg, $channel),
            );
        }

        $iter = 0;
        $stop = false;

        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stop): void { $stop = true; });
            pcntl_signal(SIGINT,  function () use (&$stop): void { $stop = true; });
        }

        while ($channel->is_consuming() && ! $stop) {
            try {
                $channel->wait(timeout: 1);
            } catch (\PhpAmqpLib\Exception\AMQPTimeoutException) {
                // No message in 1s — loop and re-check stop flag.
            }
            $iter++;
            if ($maxIterations > 0 && $iter >= $maxIterations) {
                break;
            }
        }

        $this->rabbit->close();
    }

    private function onMessage(AMQPMessage $message, AMQPChannel $channel): void
    {
        $deliveryTag = $message->getDeliveryTag();
        $body        = $message->getBody();
        $decoded     = json_decode($body, true);

        if (! is_array($decoded) || ! isset($decoded['event_id'], $decoded['event_type'])) {
            $this->logger->error('event-consumer.malformed', ['body' => substr($body, 0, 500)]);
            $channel->basic_nack($deliveryTag, multiple: false, requeue: false);

            return;
        }

        $event = Event::fromEnvelope($decoded);

        if ($this->processed->isProcessed($event->eventId)) {
            $channel->basic_ack($deliveryTag);

            return;
        }

        $cid = $event->correlationId();
        if ($cid !== null) {
            $this->correlation->set($cid);
        }

        try {
            foreach ($this->matchingHandlers($event->eventType) as $handler) {
                $handler->handle($event);
            }
            $this->processed->markProcessed($event->eventId, $event->eventType);
            $channel->basic_ack($deliveryTag);
        } catch (Throwable $e) {
            $this->logger->error('event-consumer.handler-failed', [
                'event_id'   => $event->eventId,
                'event_type' => $event->eventType,
                'error'      => $e->getMessage(),
            ]);
            $channel->basic_nack($deliveryTag, multiple: false, requeue: false);
        } finally {
            $this->correlation->clear();
        }
    }

    /**
     * @return iterable<EventHandler>
     */
    private function matchingHandlers(string $routingKey): iterable
    {
        $all = $this->handlers !== [] ? $this->handlers : $this->container->tagged('abeon.event_handler');

        foreach ($all as $handler) {
            if (! $handler instanceof EventHandler) {
                continue;
            }
            foreach ($handler->subscribesTo() as $pattern) {
                if ($this->matches($routingKey, $pattern)) {
                    yield $handler;
                    break;
                }
            }
        }
    }

    /**
     * Topic-style wildcard match: `*` matches one segment, `#` matches one+.
     */
    private function matches(string $routingKey, string $pattern): bool
    {
        if ($pattern === $routingKey) {
            return true;
        }

        $regex = '/^'.str_replace(['\.', '\*', '\#'], ['\.', '[^.]+', '.+'], preg_quote($pattern, '/')).'$/';

        return (bool) preg_match($regex, $routingKey);
    }

    /**
     * @return list<string>
     */
    private function subscriptions(): array
    {
        $declared = $this->config->consumerSubscriptions();
        if ($declared !== []) {
            return $declared;
        }

        $all = $this->handlers !== [] ? $this->handlers : $this->container->tagged('abeon.event_handler');
        $keys = [];
        foreach ($all as $handler) {
            if ($handler instanceof EventHandler) {
                foreach ($handler->subscribesTo() as $pattern) {
                    $keys[$pattern] = true;
                }
            }
        }

        return array_keys($keys);
    }
}
