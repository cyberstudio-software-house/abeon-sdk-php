<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Audit;

use Abeon\SDK\AbeonServiceProvider;
use Abeon\SDK\Audit\Audited;
use Abeon\SDK\Audit\AuditLog;
use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Logging\CorrelationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

/**
 * The audit log (ADR-0042) on a real MariaDB: entries with actor, impersonation, diff and
 * correlation, secrets masked, and a table the database itself keeps append-only.
 */
final class AuditLogTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AbeonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $setting = static fn (string $name, string $default): string => (string) (getenv('ABEON_TEST_DB_'.$name) ?: $default);
        $app['config']->set('database.default', 'mariadb');
        $app['config']->set('database.connections.mariadb', [
            'driver' => 'mariadb', 'host' => $setting('HOST', '127.0.0.1'), 'port' => $setting('PORT', '3306'),
            'database' => $setting('DATABASE', 'abeon_sdk_test'), 'username' => $setting('USERNAME', 'abeon_sdk'), 'password' => $setting('PASSWORD', 'abeon_sdk'),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'timezone' => '+00:00',
        ]);
        $app['config']->set('abeon.service.name', 'cms');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropAllTables();
        $this->loadMigrationsFrom(__DIR__.'/../../../database/audit-migrations');
        Schema::create('audited_things', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('webhook_secret')->nullable();
            $table->json('draft')->nullable();
            $table->timestamps();
        });
    }

    public function test_an_entry_names_the_actor_the_subject_and_the_request(): void
    {
        $this->signIn(new User('7', 'anna@acme.test', 'Anna Nowak', [], [], 1));
        app(CorrelationContext::class)->set('corr-1');

        app(AuditLog::class)->record('page.moved', 'page', 41, 'Kariera', ['path' => ['/kariera', '/o-nas/kariera']], ['site_id' => 1]);

        $entry = DB::table(AuditLog::TABLE)->sole();
        $this->assertSame(['user', '7', 'Anna Nowak', null, 1], [$entry->actor_type, $entry->actor_id, $entry->actor_name, $entry->on_behalf_of, (int) $entry->org_id]);
        $this->assertSame(['page.moved', 'page', '41', 'Kariera', 'corr-1'], [$entry->action, $entry->subject_type, $entry->subject_id, $entry->subject_label, $entry->correlation_id]);
        $this->assertSame(['path' => ['/kariera', '/o-nas/kariera']], json_decode($entry->changes, true));
        $this->assertSame(['site_id' => 1], json_decode($entry->context, true));
    }

    public function test_an_impersonated_change_names_both_people(): void
    {
        $this->signIn(new User('42', 'klient@acme.test', 'Klient', [], [], 1, actorId: '9'));

        app(AuditLog::class)->record('settings.changed');

        $entry = DB::table(AuditLog::TABLE)->sole();
        $this->assertSame(['9', '42', null], [$entry->actor_id, $entry->on_behalf_of, $entry->actor_name]);
    }

    public function test_without_a_user_the_actor_is_the_system(): void
    {
        app(AuditLog::class)->record('submission.erased');

        $this->assertSame(['system', null], [DB::table(AuditLog::TABLE)->value('actor_type'), DB::table(AuditLog::TABLE)->value('actor_id')]);
    }

    public function test_a_model_records_what_changed_and_never_a_secret(): void
    {
        $this->signIn(new User('7', 'anna@acme.test', 'Anna Nowak', [], [], 1));

        $thing = AuditedThing::query()->create(['title' => 'Kontakt', 'webhook_secret' => 'whsec_1', 'draft' => ['a' => 1]]);
        $thing->update(['draft' => ['a' => 2]]);
        $thing->update(['title' => 'Kontakt z nami', 'webhook_secret' => 'whsec_2']);
        $thing->delete();

        $entries = DB::table(AuditLog::TABLE)->orderBy('id')->get();
        $this->assertSame(['audited_thing.created', 'audited_thing.updated', 'audited_thing.deleted'], $entries->pluck('action')->all(), 'A change to an excluded field writes nothing.');
        $this->assertSame(['title' => [null, 'Kontakt'], 'webhook_secret' => AuditLog::MASK], json_decode($entries[0]->changes, true));
        $this->assertSame(['title' => ['Kontakt', 'Kontakt z nami'], 'webhook_secret' => AuditLog::MASK], json_decode($entries[1]->changes, true));
        $this->assertSame('Kontakt z nami', $entries[2]->subject_label);
        $this->assertStringNotContainsString('whsec_', (string) $entries->pluck('changes')->implode(''));
    }

    public function test_the_database_refuses_to_change_or_delete_an_entry(): void
    {
        app(AuditLog::class)->record('page.published');

        foreach ([fn () => DB::table(AuditLog::TABLE)->update(['action' => 'x']), fn () => DB::table(AuditLog::TABLE)->delete()] as $attempt) {
            try {
                $attempt();
                $this->fail('The audit log accepted a change.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('abeon_audit_log', $e->getMessage());
            }
        }

        $this->artisan('abeon:audit:check')->expectsOutput('The audit log is append-only: UPDATE and DELETE were refused.')->assertSuccessful();
    }

    public function test_only_entries_past_the_retention_are_pruned(): void
    {
        config(['abeon.audit.retention_days' => 30]);
        foreach ([400, 200, 1] as $days) {
            DB::table(AuditLog::TABLE)->insert(['occurred_at' => Carbon::now()->subDays($days), 'actor_type' => 'system', 'action' => "old.{$days}"]);
        }

        $this->artisan('abeon:audit:prune')->expectsOutput('Pruned 1 audit entries older than 365 days.')->assertSuccessful();

        $this->assertSame(['old.200', 'old.1'], DB::table(AuditLog::TABLE)->orderBy('id')->pluck('action')->all());
    }

    private function signIn(User $user): void
    {
        app(AuthContext::class)->set($user);
    }
}

final class AuditedThing extends Model
{
    use Audited;

    protected $table = 'audited_things';

    protected $guarded = [];

    /** @var list<string> */
    protected array $auditExcept = ['draft'];

    protected $casts = ['draft' => 'array'];
}
