<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\EventPruner;
use Abeon\SDK\Events\OutboxPublisher;
use Abeon\SDK\Events\ProcessedEvents;
use Abeon\SDK\Tests\Support\UsesMariaDb;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

/**
 * Retention for the two tables nothing ever deleted from (ADR-0002).
 *
 * Both grow for the life of the deployment, in every service and again in every client's
 * instance. What may go is the narrow part: a row the outbox has not published is an event
 * that has not happened yet, whatever its age.
 */
final class EventPrunerTest extends TestCase
{
    use UsesMariaDb;

    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = $this->connectTestDatabase(Container::getInstance());
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $schema = $this->capsule->getConnection()->getSchemaBuilder();

        $schema->create('abeon_event_outbox', function ($table): void {
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

        $schema->create('abeon_processed_events', function ($table): void {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->string('routing_key', 255);
            $table->timestamp('processed_at');
        });
    }

    public function test_old_processed_rows_go_and_recent_ones_stay(): void
    {
        $this->outboxRow('11111111-1111-4111-8111-111111111111', processedDaysAgo: 40);
        $this->outboxRow('22222222-2222-4222-8222-222222222222', processedDaysAgo: 2);
        $this->processedRow('33333333-3333-4333-8333-333333333333', daysAgo: 40);
        $this->processedRow('44444444-4444-4444-8444-444444444444', daysAgo: 2);

        $counted = $this->pruner()->prune();

        $this->assertSame(['outbox' => 1, 'processed' => 1], $counted);
        $this->assertSame(1, $this->table(OutboxPublisher::TABLE)->count());
        $this->assertSame(1, $this->table(ProcessedEvents::TABLE)->count());
    }

    /**
     * An unpublished row is an event whose consequences have not left this service. Age is
     * not a reason to drop one — a drainer that was down for a month still has work to do.
     */
    public function test_an_unpublished_row_is_never_deleted(): void
    {
        $this->outboxRow('55555555-5555-4555-8555-555555555555', processedDaysAgo: null, createdDaysAgo: 400);

        $this->assertSame(['outbox' => 0, 'processed' => 0], $this->pruner()->prune());
        $this->assertSame(1, $this->table(OutboxPublisher::TABLE)->count());
    }

    public function test_a_dry_run_counts_and_deletes_nothing(): void
    {
        $this->outboxRow('66666666-6666-4666-8666-666666666666', processedDaysAgo: 40);

        $this->assertSame(['outbox' => 1, 'processed' => 0], $this->pruner()->prune(dryRun: true));
        $this->assertSame(1, $this->table(OutboxPublisher::TABLE)->count());
    }

    private function pruner(): EventPruner
    {
        return new EventPruner($this->capsule->getDatabaseManager(), new AbeonConfig(new Repository([
            'abeon' => ['events' => ['outbox' => ['connection' => null, 'retention_days' => 30]]],
        ])));
    }

    private function table(string $name): \Illuminate\Database\Query\Builder
    {
        return $this->capsule->getConnection()->table($name);
    }

    private function outboxRow(string $eventId, ?int $processedDaysAgo, int $createdDaysAgo = 400): void
    {
        $this->table(OutboxPublisher::TABLE)->insert([
            'event_id'     => $eventId,
            'routing_key'  => 'crm.contact.created',
            'envelope'     => '{}',
            'created_at'   => date('Y-m-d H:i:s', time() - $createdDaysAgo * 86400),
            'processed_at' => $processedDaysAgo === null
                ? null
                : date('Y-m-d H:i:s', time() - $processedDaysAgo * 86400),
        ]);
    }

    private function processedRow(string $eventId, int $daysAgo): void
    {
        $this->table(ProcessedEvents::TABLE)->insert([
            'event_id'     => $eventId,
            'routing_key'  => 'crm.contact.created',
            'processed_at' => date('Y-m-d H:i:s', time() - $daysAgo * 86400),
        ]);
    }
}
