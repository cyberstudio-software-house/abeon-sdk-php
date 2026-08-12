<?php

declare(strict_types=1);

namespace Abeon\SDK\Tenancy;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;

/**
 * Marks an Eloquent model as belonging to an organisation (ADR-0016 / ADR-0018).
 *
 *     class Invoice extends Model
 *     {
 *         use BelongsToTenant;
 *     }
 *
 * Two effects:
 *
 *   - Reads are constrained to the current organisation by `TenantScope`.
 *   - Inserts are stamped with it, so application code never writes `org_id`
 *     by hand and cannot forget to.
 *
 * Both fail closed: with no tenant in context, querying or creating throws
 * rather than silently spanning organisations.
 *
 * **What this does not cover** (ADR-0018 states these up front, and they are the
 * reason the trait is not a security boundary on its own):
 *
 *   - `DB::table()`, raw SQL and query-builder joins bypass Eloquent scopes entirely.
 *   - Migrations, seeders and console commands run tenant-less by nature.
 *   - **Quiet saves.** `saveQuietly()` and `Model::withoutEvents()` suppress the
 *     `creating` hook, so the row is inserted unstamped. This cannot be closed from
 *     inside the trait — which is why the column below must be **NOT NULL**: the
 *     database is the backstop for the one hole in stamping.
 *   - Object storage is a separate concern — see ADR-0021.
 *
 * The migration must carry the column, **not nullable**, and indexed — every query
 * on the model now filters by it:
 *
 *     $table->unsignedBigInteger('org_id')->index();   // NOT NULL by default
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            /** @var BelongsToTenant&Model $model */
            $column = $model->getTenantColumn();

            // Already set — an explicit cross-organisation write, or a restore.
            // Honour it rather than overwriting: silently rewriting a caller's
            // explicit value would be a worse surprise than an invalid insert.
            if ($model->getAttribute($column) !== null) {
                return;
            }

            if (TenantScope::isDisabled()) {
                // Inside withoutTenantScope() there is no single organisation to
                // stamp. Creating a row here must name its organisation explicitly.
                return;
            }

            $model->setAttribute($column, static::tenantContext()->require());
        });
    }

    /**
     * Column holding the organisation. Override to rename per model; `org_id`
     * matches the JWT claim and the event envelope (ADR-0001, ADR-0002), so
     * consistency is worth more than a prettier local name.
     */
    public function getTenantColumn(): string
    {
        return 'org_id';
    }

    /**
     * Run a callback with tenant scoping suspended.
     *
     *     Invoice::withoutTenantScope(fn () => Invoice::sum('total'));
     *
     * The only sanctioned way to read across organisations, and deliberately a
     * single greppable token so an audit is a search. Every call site should be
     * able to answer "why is this allowed to see every client's data?".
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutTenantScope(Closure $callback): mixed
    {
        return TenantScope::withoutScoping($callback);
    }

    protected static function tenantContext(): TenantContext
    {
        /** @var TenantContext $context */
        $context = Container::getInstance()->make(TenantContext::class);

        return $context;
    }
}
