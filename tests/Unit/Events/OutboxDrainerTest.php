<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\OutboxDrainer;
use Abeon\SDK\Events\RabbitMq;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;

final class OutboxDrainerTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = Container::getInstance();
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->capsule->getConnection()->getSchemaBuilder()->create('abeon_event_outbox', function ($table): void {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->string('routing_key', 255);
            $table->longText('envelope');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
        });
    }

    public function test_publishes_pending_rows_and_marks_them_processed(): void
    {
        $this->insertRow('e1', 'crm.contact.created');
        $this->insertRow('e2', 'crm.contact.updated');

        [$drainer, $channel] = $this->makeDrainer();
        $channel->expects($this->exactly(2))->method('basic_publish');

        $this->assertSame(2, $drainer->drainOnce());

        $unprocessed = $this->table()->whereNull('processed_at')->count();
        $this->assertSame(0, $unprocessed);
    }

    public function test_does_not_republish_already_processed_rows(): void
    {
        $this->insertRow('e1', 'crm.contact.created');

        [$drainer, $channel] = $this->makeDrainer();
        $channel->expects($this->once())->method('basic_publish');

        $this->assertSame(1, $drainer->drainOnce());
        $this->assertSame(0, $drainer->drainOnce(), 'second pass should find nothing pending');
    }

    public function test_records_failure_when_publish_throws(): void
    {
        $this->insertRow('e1', 'crm.contact.created');

        [$drainer, $channel] = $this->makeDrainer();
        $channel->method('basic_publish')->willThrowException(new \RuntimeException('broker down'));

        // The row is counted as processed-this-pass even though it failed.
        $this->assertSame(1, $drainer->drainOnce());

        $row = $this->table()->where('event_id', 'e1')->first();
        $this->assertNull($row->processed_at);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertStringContainsString('broker down', (string) $row->last_error);
    }

    public function test_skip_locked_config_is_a_safe_noop_on_sqlite(): void
    {
        // SQLite has no row locking; the driver guard must short-circuit so
        // enabling skip_locked never produces invalid SQL. (Real SKIP LOCKED
        // behaviour is exercised against MariaDB in integration, not here.)
        $this->insertRow('e1', 'crm.contact.created');

        [$drainer, $channel] = $this->makeDrainer(skipLocked: true);
        $channel->expects($this->once())->method('basic_publish');

        $this->assertSame(1, $drainer->drainOnce());
    }

    /**
     * @return array{0: OutboxDrainer, 1: AMQPChannel&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeDrainer(bool $skipLocked = false): array
    {
        $channel = $this->createMock(AMQPChannel::class);
        $rabbit  = $this->createMock(RabbitMq::class);
        $rabbit->method('channel')->willReturn($channel);

        $drainer = new OutboxDrainer(
            $this->capsule->getDatabaseManager(),
            $rabbit,
            $this->config($skipLocked),
        );

        return [$drainer, $channel];
    }

    private function config(bool $skipLocked): AbeonConfig
    {
        return new AbeonConfig(new Repository([
            'abeon' => [
                'service' => ['name' => 'crm'],
                'events'  => [
                    'exchange' => 'abeon.events',
                    'outbox'   => [
                        'connection'  => null,
                        'skip_locked' => $skipLocked,
                        'max_attempts' => 5,
                        'batch_size'   => 100,
                    ],
                ],
            ],
        ]));
    }

    private function insertRow(string $eventId, string $routingKey): void
    {
        $this->table()->insert([
            'event_id'    => $eventId,
            'routing_key' => $routingKey,
            'envelope'    => json_encode([
                'event_id'   => $eventId,
                'event_type' => $routingKey,
                'data'       => ['id' => 1],
            ]),
            'created_at'  => date('Y-m-d H:i:s'),
            'attempts'    => 0,
        ]);
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->capsule->getConnection()->table('abeon_event_outbox');
    }
}
