<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Health;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\OutboxPublisher;
use Abeon\SDK\Health\CheckResult;
use Abeon\SDK\Health\OutboxLagCheck;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

final class OutboxLagCheckTest extends TestCase
{
    private Capsule $capsule;
    private AbeonConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->capsule = new Capsule(Container::getInstance());
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->config = new AbeonConfig(new Repository([
            'abeon' => [
                'events' => ['outbox' => ['connection' => null, 'lag_threshold' => 60]],
            ],
        ]));
    }

    private function check(): OutboxLagCheck
    {
        return new OutboxLagCheck($this->capsule->getDatabaseManager(), $this->config);
    }

    private function createTable(): void
    {
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

    public function test_returns_degraded_when_table_missing(): void
    {
        $result = $this->check()->run();
        $this->assertSame(CheckResult::STATUS_DEGRADED, $result->status);
        $this->assertStringContainsString('migration', (string) $result->message);
    }

    public function test_returns_ok_when_outbox_empty(): void
    {
        $this->createTable();
        $result = $this->check()->run();
        $this->assertSame(CheckResult::STATUS_OK, $result->status);
    }

    public function test_returns_ok_when_unprocessed_rows_under_threshold(): void
    {
        $this->createTable();
        $this->capsule->getConnection()->table(OutboxPublisher::TABLE)->insert([
            'event_id'    => '11111111-1111-4111-8111-111111111111',
            'routing_key' => 'crm.contact.created',
            'envelope'    => '{}',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $result = $this->check()->run();
        $this->assertSame(CheckResult::STATUS_OK, $result->status);
    }

    public function test_returns_degraded_when_lag_exceeds_threshold(): void
    {
        $this->createTable();
        $this->capsule->getConnection()->table(OutboxPublisher::TABLE)->insert([
            'event_id'    => '22222222-2222-4222-8222-222222222222',
            'routing_key' => 'crm.contact.created',
            'envelope'    => '{}',
            'created_at'  => date('Y-m-d H:i:s', time() - 300),  // 5 minutes ago
        ]);

        $result = $this->check()->run();
        $this->assertSame(CheckResult::STATUS_DEGRADED, $result->status);
        $this->assertMatchesRegularExpression('/\d+s old/', (string) $result->message);
    }

    public function test_processed_rows_do_not_count_toward_lag(): void
    {
        $this->createTable();
        $this->capsule->getConnection()->table(OutboxPublisher::TABLE)->insert([
            'event_id'     => '33333333-3333-4333-8333-333333333333',
            'routing_key'  => 'crm.contact.created',
            'envelope'     => '{}',
            'created_at'   => date('Y-m-d H:i:s', time() - 3600),
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->check()->run();
        $this->assertSame(CheckResult::STATUS_OK, $result->status);
    }
}
