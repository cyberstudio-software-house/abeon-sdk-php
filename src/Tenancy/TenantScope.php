<?php

declare(strict_types=1);

namespace Abeon\SDK\Tenancy;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global query scope constraining a model to the current organisation (ADR-0018).
 *
 * **Fails closed.** With no tenant in context the scope throws rather than
 * returning every organisation's rows. That is the whole point: any scoping
 * mechanism can be bypassed, so what makes the system safe is that the unsafe
 * state is loud — in development, on the first query, rather than in production
 * as a cross-client disclosure.
 *
 * Applies to reads only. Inserts are covered by `BelongsToTenant`'s creating hook.
 */
class TenantScope implements Scope
{
    /**
     * Depth of nested `withoutScoping()` calls. A counter rather than a bool so
     * nesting cannot have an inner block re-enable scoping for an outer one.
     */
    private static int $disabled = 0;

    public function __construct(private readonly ?TenantContext $tenants = null)
    {
    }

    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (self::$disabled > 0) {
            return;
        }

        // The trait supplies getTenantColumn(); models using this scope always have
        // it, but PHPStan cannot see that from the Scope interface's signature.
        $column = method_exists($model, 'getTenantColumn')
            ? (string) $model->getTenantColumn()
            : 'org_id';

        $builder->where(
            $model->qualifyColumn($column),
            '=',
            $this->context()->require(),
        );
    }

    /**
     * Run a callback with tenant scoping disabled.
     *
     * Reached through `Model::withoutTenantScope()` — one greppable token, so
     * auditing cross-organisation access is a search rather than a code review.
     *
     * Restores on exceptions, so a throwing callback cannot leave scoping off for
     * the rest of the process — which in a long-lived worker would mean every
     * later query silently spanning organisations.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withoutScoping(Closure $callback): mixed
    {
        self::$disabled++;

        try {
            return $callback();
        } finally {
            self::$disabled--;
        }
    }

    /**
     * Whether scoping is currently suspended. For assertions and diagnostics.
     */
    public static function isDisabled(): bool
    {
        return self::$disabled > 0;
    }

    /**
     * Reset the suspension counter. Test-support only — production code must let
     * `withoutScoping()`'s `finally` do this.
     */
    public static function resetScoping(): void
    {
        self::$disabled = 0;
    }

    private function context(): TenantContext
    {
        if ($this->tenants !== null) {
            return $this->tenants;
        }

        /** @var TenantContext $resolved */
        $resolved = Container::getInstance()->make(TenantContext::class);

        return $resolved;
    }
}
