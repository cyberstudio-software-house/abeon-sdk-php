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
        $this->cachedHandlers = null;

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

        $malformed = is_array($decoded) ? $this->envelopeProblem($decoded) : 'not a JSON object';

        if ($malformed !== null) {
            $this->logger->error('event-consumer.malformed', [
                'problem' => $malformed,
                'body'    => substr($body, 0, 500),
            ]);
            $channel->basic_nack($deliveryTag, multiple: false, requeue: false);

            return;
        }

        $event = Event::fromEnvelope($decoded);

        if ($this->processed->isProcessed($event->eventId)) {
            $channel->basic_ack($deliveryTag);

            return;
        }

        // ADR-0031 §6: an instance bound to one organisation acks another organisation's
        // events unhandled. Its queue is its own, so nothing else was going to receive
        // them. Platform-level events (no org_id) still pass.
        $instanceOrgId = $this->config->instanceOrgId();
        if ($instanceOrgId !== null && $event->orgId !== null && $event->orgId !== $instanceOrgId) {
            $this->logger->debug('event-consumer.other-organisation', [
                'event_id' => $event->eventId,
                'org_id'   => $event->orgId,
            ]);
            $channel->basic_ack($deliveryTag);

            return;
        }

        $cid = $event->correlationId();
        if ($cid !== null) {
            $this->correlation->set($cid);
        }

        try {
            // The tenant comes from the envelope, and nothing else can supply it; a null
            // `org_id` passes through (ADR-0018). See docs/notes/event-consumer.md.
            $handled = $this->tenants->runFor($event->orgId, function () use ($event): int {
                $handled = 0;

                foreach ($this->matchingHandlers($event->eventType) as $handler) {
                    $this->assertAcceptsVersion($handler, $event);

                    // Every subscription shares this loop and this channel, so a slow
                    // handler stalls the rest. See docs/notes/event-consumer.md.
                    $started = microtime(true);
                    $handler->handle($event);
                    $elapsed = microtime(true) - $started;

                    if ($elapsed > $this->config->consumerSlowHandlerSeconds()) {
                        $this->logger->warning('event-consumer.handler-slow', [
                            'handler'    => $handler::class,
                            'event_id'   => $event->eventId,
                            'event_type' => $event->eventType,
                            'seconds'    => round($elapsed, 2),
                        ]);
                    }

                    $handled++;
                }

                return $handled;
            });

            if ($handled === 0) {
                // The broker matched this to a queue we bound and `matches()` disagreed;
                // not an error, but never silent. See docs/notes/event-consumer.md.
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

    private function assertAcceptsVersion(EventHandler $handler, Event $event): void
    {
        // Version 1 for every handler. `AcceptsEventVersions` existed for a handler that
        // accepts more than one major, and no handler on this platform ever implemented it —
        // the day a 2.0 producer appears, the interface comes back with its first caller.
        $accepted = [1];
        $major = self::majorVersion($event->version);

        if ($major !== null && in_array($major, $accepted, true)) {
            return;
        }

        $this->logger->warning('event-consumer.unsupported-version', [
            'event_id'   => $event->eventId,
            'event_type' => $event->eventType,
            'version'    => $event->version,
            'handler'    => $handler::class,
            'accepted'   => $accepted,
        ]);

        throw new \RuntimeException(
            $handler::class." does not accept {$event->eventType} version {$event->version}",
        );
    }

    private static function majorVersion(string $version): ?int
    {
        return preg_match('/^(\d+)(\.\d+)*$/', $version, $m) === 1 ? (int) $m[1] : null;
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
     * `tagged()` was re-iterated per pass and can be one-shot — see docs/notes/event-consumer.md.
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
     * AMQP topic wildcard match: `*` is exactly one segment, `#` is zero or more.
     * This has disagreed with the broker before — see docs/notes/event-consumer.md.
     */
    private function matches(string $routingKey, string $pattern): bool
    {
        if ($pattern === $routingKey) {
            return true;
        }

        $regex = '/^'.str_replace(
            ['\.\#', '\#', '\*'],
            ['(\..*)?', '.*', '[^.]+'],
            preg_quote($pattern, '/'),
        ).'$/';

        return (bool) preg_match($regex, $routingKey);
    }

    /**
     * Why this envelope is not one, or null when it is.
     * ADR-0002: all nine required fields, not two — see docs/notes/event-consumer.md.
     *
     * @param  array<string, mixed>  $envelope
     */
    private function envelopeProblem(array $envelope): ?string
    {
        foreach (['event_id', 'event_type', 'timestamp', 'source', 'version', 'actor', 'data'] as $key) {
            if (! isset($envelope[$key]) || $envelope[$key] === '') {
                return "missing {$key}";
            }
        }

        // Present-and-null is the whole point of this one: a platform-level event says so by
        // carrying the key with a null, and a publisher that leaves it out has not said it.
        if (! array_key_exists('org_id', $envelope)) {
            return 'missing org_id';
        }

        $orgId = $envelope['org_id'];

        if ($orgId !== null && ! (is_int($orgId) && $orgId > 0)) {
            return 'org_id is neither null nor a positive integer';
        }

        if (! is_array($envelope['actor']) || ! is_array($envelope['data'])) {
            return 'actor and data must be objects';
        }

        return null;
    }

    /**
     * The dead-letter queue behind each subscription, keyed by the routing key it holds.
     * Public because something has to read them — see docs/notes/event-consumer.md.
     *
     * @return array<string, string> routing key => queue name
     */
    public function deadLetterQueues(): array
    {
        $prefix = $this->config->consumerQueuePrefix();
        $queues = [];

        foreach ($this->subscriptions() as $routingKey) {
            $queues[$routingKey] = "{$prefix}.{$routingKey}.dlq";
        }

        return $queues;
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
