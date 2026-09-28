<?php

declare(strict_types=1);

namespace Abeon\SDK\Events\Commands;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\EventConsumer;
use Abeon\SDK\Events\RabbitMq;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Read — and optionally replay — the events this service refused (ADR-0002).
 *
 * `EventConsumer` nacks a message it cannot handle and RabbitMQ moves it to a dead-letter
 * queue per routing key. That queue had no reader at all: nothing counted it, nothing
 * emptied it, and nothing said so. A handler broken by a deploy therefore looked exactly
 * like a quiet Tuesday — the events kept arriving, kept being refused, and kept piling up
 * where only the broker's own web console would show them.
 *
 * Replay republishes to the main exchange under the original routing key, which is the
 * same path the drainer publishes on, so the consumer handles them as if they had just
 * arrived. `ProcessedEvents` makes a replay of something that did get through harmless.
 */
class DeadLetterCommand extends Command
{
    protected $signature = 'abeon:events:dlq
        {--replay : Republish what is there to the main exchange}
        {--limit=500 : At most this many messages per queue when replaying}';

    protected $description = 'Count or replay the events this service dead-lettered.';

    public function handle(RabbitMq $rabbit, EventConsumer $consumer, AbeonConfig $config): int
    {
        $queues = $consumer->deadLetterQueues();

        if ($queues === []) {
            $this->info('This service subscribes to nothing, so it has no dead letters.');

            return self::SUCCESS;
        }

        $channel = $rabbit->channel();
        $replay = (bool) $this->option('replay');
        $limit = max(1, (int) $this->option('limit'));
        $total = 0;

        foreach ($queues as $routingKey => $queue) {
            // Declared exactly as `EventConsumer` declares it, which is idempotent and
            // returns the depth. A passive declare would be tidier and kills the channel
            // when the queue does not exist yet — which is the normal state of a service
            // that has never refused anything.
            $declared = $channel->queue_declare(
                queue: $queue, passive: false, durable: true, exclusive: false, auto_delete: false,
            );

            $depth = is_array($declared) ? (int) $declared[1] : 0;
            $total += $depth;
            $this->line(sprintf('%-48s %6d', $queue, $depth));

            if (! $replay || $depth < 1) {
                continue;
            }

            $moved = 0;

            while ($moved < $limit) {
                $message = $channel->basic_get($queue);

                if ($message === null) {
                    break;
                }

                $channel->basic_publish(
                    new AMQPMessage($message->getBody(), [
                        'content_type'  => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    ]),
                    $config->rabbitMqExchange(),
                    $routingKey,
                );

                // Acked after the republish, never before: a crash in between then costs a
                // redelivery rather than the event.
                $message->ack();
                $moved++;
            }

            $this->info("Replayed {$moved} message(s) from {$queue}.");
        }

        if (! $replay && $total > 0) {
            $this->warn("{$total} dead-lettered event(s) are waiting. Fix the handler, then run with --replay.");
        }

        return self::SUCCESS;
    }
}
