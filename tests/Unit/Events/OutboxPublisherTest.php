<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\EnvelopeBuilder;
use Abeon\SDK\Events\OutboxPublisher;
use Abeon\SDK\Exceptions\ContractViolationException;
use Abeon\SDK\Logging\CorrelationContext;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

final class OutboxPublisherTest extends TestCase
{
    private Capsule $capsule;
    private OutboxPublisher $publisher;

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

        $config = new AbeonConfig(new Repository([
            'abeon' => [
                'service' => ['name' => 'crm'],
                'events'  => ['outbox' => ['connection' => null]],
            ],
        ]));

        $this->publisher = new OutboxPublisher(
            new EnvelopeBuilder($config, new CorrelationContext(), new AuthContext()),
            $this->capsule->getDatabaseManager(),
            $config,
        );
    }

    public function test_publish_outside_transaction_throws_contract_violation(): void
    {
        $this->expectException(ContractViolationException::class);
        $this->expectExceptionMessageMatches('/outside a database transaction/');

        $this->publisher->publish('crm.contact.created', ['contact_id' => 1]);
    }

    public function test_publish_inside_transaction_writes_envelope_to_outbox(): void
    {
        $connection = $this->capsule->getConnection();

        $eventId = $connection->transaction(fn () =>
            $this->publisher->publish('crm.contact.created', ['contact_id' => 1])
        );

        $this->assertNotEmpty($eventId);

        $row = $connection->table('abeon_event_outbox')->where('event_id', $eventId)->first();
        $this->assertNotNull($row);
        $this->assertSame('crm.contact.created', $row->routing_key);
        $this->assertNull($row->processed_at);
        $this->assertSame(0, (int) $row->attempts);

        $envelope = json_decode($row->envelope, true);
        $this->assertSame($eventId, $envelope['event_id']);
        $this->assertSame(['contact_id' => 1], $envelope['data']);
    }

    public function test_enforcement_can_be_disabled_via_ctor_flag(): void
    {
        $config = new AbeonConfig(new Repository([
            'abeon' => [
                'service' => ['name' => 'crm'],
                'events'  => ['outbox' => ['connection' => null]],
            ],
        ]));

        $publisher = new OutboxPublisher(
            new EnvelopeBuilder($config, new CorrelationContext(), new AuthContext()),
            $this->capsule->getDatabaseManager(),
            $config,
            enforceTransaction: false,
        );

        $eventId = $publisher->publish('crm.contact.created', []);
        $this->assertNotEmpty($eventId);
    }
}
