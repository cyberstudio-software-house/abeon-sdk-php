<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Tenancy\TenantContext;
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

    /** @var list<EventHandler>|null Memoized resolved handler list. */
    private ?array $cachedHandlers = null;

    private LoggerInterface $logger;

    public function __construct(
        private readonly RabbitMq $rabbit,
        private readonly ProcessedEvents $processed,
        private readonly CorrelationContext $correlation,
        private readonly TenantContext $tenants,
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
        $this->cachedHandlers = null;  // invalidate memoization

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
            // **The tenant comes from the envelope, and nothing else can supply it.**
            // A consumer has no request and therefore no `AuthContext` to fall back on,
            // so before this a handler ran with no organisation at all — `require()`
            // threw, and any handler that instead treated "no tenant" as "no filter"
            // read every organisation's rows. `TenantContext`'s own docblock has always
            // named this call as the way in; nothing made it.
            //
            // A null `org_id` is passed through rather than refused: a platform-level
            // event legitimately has none, and it is the *handler* that knows whether it
            // needs a tenant (ADR-0018).
            $handled = $this->tenants->runFor($event->orgId, function () use ($event): int {
                $handled = 0;

                foreach ($this->matchingHandlers($event->eventType) as $handler) {
                    $handler->handle($event);
                    $handled++;
                }

                return $handled;
            });

            if ($handled === 0) {
                // The broker already matched this message to a queue we bound, and then
                // `matches()` re-derived the same decision in PHP and disagreed. Those two
                // implementations have diverged before — `#` meant "one or more segments"
                // here while meaning "zero or more" to AMQP — and when they disagree the
                // message is acked with nothing done and nothing said.
                //
                // Not an error: a handler removed while its queue still exists produces
                // this legitimately, and nacking would dead-letter a message nobody wants.
                // But it should never be silent.
                $this->logger->warning('event-consumer.no-matching-handler', [
                    'event_id'   => $event->eventId,
                    'event_type' => $event->eventType,
                ]);
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
        foreach ($this->resolvedHandlers() as $handler) {
            foreach ($handler->subscribesTo() as $pattern) {
                if ($this->matches($routingKey, $pattern)) {
                    yield $handler;
                    break;
                }
            }
        }
    }

    /**
     * Memoize the materialized handler list.
     *
     * `$container->tagged('abeon.event_handler')` used to be
     * was called on every matchingHandlers() and subscriptions() pass. Worse,
     * Laravel's tagged() returns a RewindableGenerator that can be one-shot
     * in some container configurations — re-iterating could silently yield
     * empty. Materializing once into an array is both faster and safer.
     *
     * @return list<EventHandler>
     */
    private function resolvedHandlers(): array
    {
        if ($this->cachedHandlers !== null) {
            return $this->cachedHandlers;
        }

        $source = $this->handlers !== []
            ? $this->handlers
            : $this->container->tagged('abeon.event_handler');

        $resolved = [];
        foreach ($source as $handler) {
            if ($handler instanceof EventHandler) {
                $resolved[] = $handler;
            }
        }

        return $this->cachedHandlers = $resolved;
    }

    /**
     * AMQP topic wildcard match.
     *   `*` — exactly one segment (e.g. `crm.*.created` matches `crm.contact.created`).
     *   `#` — zero or more segments (e.g. `crm.#` matches `crm`, `crm.contact`, `crm.contact.created`).
     *
     * `#` was once translated to `.+` (one or more characters), which
     * (a) treated it as "one+ segments" (off-by-one vs AMQP spec) and (b) failed
     * to match the empty suffix case. Now uses `.*` to allow zero+.
     */
    private function matches(string $routingKey, string $pattern): bool
    {
        if ($pattern === $routingKey) {
            return true;
        }

        $regex = '/^'.str_replace(['\.', '\*', '\#'], ['\.', '[^.]+', '.*'], preg_quote($pattern, '/')).'$/';

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

        $keys = [];
        foreach ($this->resolvedHandlers() as $handler) {
            foreach ($handler->subscribesTo() as $pattern) {
                $keys[$pattern] = true;
            }
        }

        return array_keys($keys);
    }
}
