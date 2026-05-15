<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\ProcessedEvents;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\TestCase;

final class ProcessedEventsTest extends TestCase
{
    private Capsule $capsule;
    private ProcessedEvents $processed;

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

        $this->capsule->getConnection()->getSchemaBuilder()->create('abeon_processed_events', function ($table): void {
            $table->string('event_id', 36)->primary();
            $table->string('routing_key', 255);
            $table->timestamp('processed_at')->useCurrent();
        });

        $config = new AbeonConfig(new Repository(['abeon' => ['events' => ['outbox' => ['connection' => null]]]]));

        $this->processed = new ProcessedEvents($this->capsule->getDatabaseManager(), $config);
    }

    public function test_is_processed_returns_false_for_new_event(): void
    {
        $this->assertFalse($this->processed->isProcessed('event-1'));
    }

    public function test_mark_processed_records_event(): void
    {
        $this->assertTrue($this->processed->markProcessed('event-1', 'crm.contact.created'));
        $this->assertTrue($this->processed->isProcessed('event-1'));
    }

    public function test_mark_processed_returns_false_on_duplicate(): void
    {
        $this->processed->markProcessed('event-1', 'crm.contact.created');
        // SQLite unique-constraint violation → caught and returns false.
        $this->assertFalse($this->processed->markProcessed('event-1', 'crm.contact.created'));
    }

    public function test_distinct_events_can_be_marked_independently(): void
    {
        $this->assertTrue($this->processed->markProcessed('event-1', 'crm.contact.created'));
        $this->assertTrue($this->processed->markProcessed('event-2', 'crm.deal.won'));

        $this->assertTrue($this->processed->isProcessed('event-1'));
        $this->assertTrue($this->processed->isProcessed('event-2'));
    }

    public function test_genuine_db_error_rethrows(): void
    {
        // Drop the table to force a non-duplicate QueryException.
        $this->capsule->getConnection()->getSchemaBuilder()->drop('abeon_processed_events');

        $this->expectException(QueryException::class);
        $this->processed->markProcessed('event-x', 'crm.contact.created');
    }
}
