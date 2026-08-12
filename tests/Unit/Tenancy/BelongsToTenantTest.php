<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Tenancy;

use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Tenancy\BelongsToTenant;
use Abeon\SDK\Tenancy\TenantContext;
use Abeon\SDK\Tenancy\TenantScope;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises the scope against a real SQLite database rather than a mocked
 * builder — the thing under test is the SQL that comes out, and a mock would
 * happily agree with a wrong implementation.
 */
final class BelongsToTenantTest extends TestCase
{
    private Capsule $capsule;

    private TenantContext $tenants;

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
        // Eloquent model events (and so the creating hook that stamps org_id) only
        // fire when a dispatcher is set. A Laravel app always has one; Capsule does
        // not wire it by default.
        $this->capsule->setEventDispatcher(new Dispatcher($container));
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->capsule->getConnection()->getSchemaBuilder()->create('invoices', function ($table): void {
            $table->increments('id');
            $table->unsignedBigInteger('org_id')->index();
            $table->string('number');
        });

        // The scope resolves TenantContext from the container, exactly as it does
        // inside a Laravel app.
        $this->tenants = new TenantContext();
        $container->instance(TenantContext::class, $this->tenants);

        TenantScope::resetScoping();
        Invoice::clearBootedModels();
    }

    protected function tearDown(): void
    {
        TenantScope::resetScoping();
        Container::setInstance(null);

        parent::tearDown();
    }

    private function seed(): void
    {
        // Written past the scope, the way a migration or a seeder would.
        $this->capsule->getConnection()->table('invoices')->insert([
            ['org_id' => 1, 'number' => 'ORG1-A'],
            ['org_id' => 1, 'number' => 'ORG1-B'],
            ['org_id' => 2, 'number' => 'ORG2-A'],
        ]);
    }

    // --- reads ---

    public function test_queries_are_constrained_to_the_current_organisation(): void
    {
        $this->seed();

        $this->tenants->set(1);

        $numbers = Invoice::query()->pluck('number')->all();
        sort($numbers);

        $this->assertSame(['ORG1-A', 'ORG1-B'], $numbers);
    }

    public function test_another_organisations_row_is_invisible_even_by_id(): void
    {
        $this->seed();
        $foreignId = (int) $this->capsule->getConnection()
            ->table('invoices')->where('number', 'ORG2-A')->value('id');

        $this->tenants->set(1);

        // Not merely filtered out of lists — a direct lookup must miss too, or a
        // guessed id becomes a cross-client read.
        $this->assertNull(Invoice::query()->find($foreignId));
    }

    public function test_switching_organisation_switches_what_is_visible(): void
    {
        $this->seed();

        $this->tenants->set(1);
        $this->assertSame(2, Invoice::query()->count());

        $this->tenants->set(2);
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_querying_with_no_tenant_throws_rather_than_returning_everything(): void
    {
        $this->seed();

        // The rule that matters more than the mechanism (ADR-0018): the unsafe
        // state is loud. Returning all three rows here would be the bug.
        $this->expectException(AuthException::class);
        Invoice::query()->count();
    }

    // --- writes ---

    public function test_create_stamps_the_current_organisation(): void
    {
        $this->tenants->set(3);

        $invoice = Invoice::query()->create(['number' => 'NEW-1']);

        $this->assertSame(3, (int) $invoice->getAttribute('org_id'));
    }

    public function test_create_with_no_tenant_throws(): void
    {
        $this->expectException(AuthException::class);
        Invoice::query()->create(['number' => 'NEW-2']);
    }

    public function test_an_explicit_organisation_on_create_is_honoured(): void
    {
        $this->tenants->set(1);

        // Overwriting a caller's explicit value would be a worse surprise than an
        // invalid insert — the caller said what they meant.
        $invoice = Invoice::query()->create(['number' => 'NEW-3', 'org_id' => 5]);

        $this->assertSame(5, (int) $invoice->getAttribute('org_id'));
    }

    public function test_a_quiet_save_bypasses_the_stamp_and_the_column_catches_it(): void
    {
        $this->tenants->set(3);

        $invoice = new Invoice(['number' => 'QUIET-1']);

        // saveQuietly() suppresses model events, so the creating hook does not run
        // and org_id is never stamped. This is a real hole in event-based stamping
        // and cannot be closed from inside the trait — which is exactly why the
        // column must be declared NOT NULL: the database is the backstop.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $invoice->saveQuietly();
    }

    // --- the escape hatch ---

    public function test_without_tenant_scope_sees_every_organisation(): void
    {
        $this->seed();
        $this->tenants->set(1);

        $count = Invoice::withoutTenantScope(fn () => Invoice::query()->count());

        $this->assertSame(3, $count);
    }

    public function test_without_tenant_scope_works_with_no_tenant_at_all(): void
    {
        $this->seed();

        // Console commands and migrations run tenant-less; the escape hatch is how
        // they read.
        $this->assertSame(3, Invoice::withoutTenantScope(fn () => Invoice::query()->count()));
    }

    public function test_scoping_is_restored_after_the_escape_hatch(): void
    {
        $this->seed();
        $this->tenants->set(1);

        Invoice::withoutTenantScope(fn () => Invoice::query()->count());

        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_scoping_is_restored_when_the_escape_hatch_throws(): void
    {
        $this->seed();
        $this->tenants->set(1);

        try {
            Invoice::withoutTenantScope(function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        // Leaving scoping off after a throw would make every later query in a
        // long-lived worker span organisations.
        $this->assertFalse(TenantScope::isDisabled());
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_nested_escape_hatches_do_not_re_enable_scoping_early(): void
    {
        $this->seed();
        $this->tenants->set(1);

        $inner = Invoice::withoutTenantScope(function () {
            Invoice::withoutTenantScope(fn () => Invoice::query()->count());

            // Still inside the outer block — the inner one must not have restored.
            return Invoice::query()->count();
        });

        $this->assertSame(3, $inner);
        $this->assertSame(2, Invoice::query()->count());
    }

    // --- consumer shape ---

    public function test_run_for_scopes_a_consumer_style_handler(): void
    {
        $this->seed();

        // A consumer has no auth context; it enters the tenant from the envelope.
        $count = $this->tenants->runFor(2, fn () => Invoice::query()->count());

        $this->assertSame(1, $count);

        $this->expectException(AuthException::class);
        Invoice::query()->count();
    }
}

/**
 * @property int $org_id
 */
final class Invoice extends Model
{
    use BelongsToTenant;

    protected $table = 'invoices';

    public $timestamps = false;

    protected $guarded = [];
}
