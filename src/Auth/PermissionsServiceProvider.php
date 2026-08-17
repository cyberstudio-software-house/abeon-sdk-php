<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;

/**
 * Bridge between Abeon's AuthContext and Laravel's Gate.
 *
 * Attaches a Gate::before hook so any ability check (e.g. Gate::allows('crm.contacts.read'))
 * is satisfied if the current Abeon user has the matching permission claim.
 *
 * Resolved lazily by AbeonServiceProvider; not a Laravel service provider itself
 * despite the name (matches the contract in section A of the Phase 0 plan).
 */
class PermissionsServiceProvider
{
    public function __construct(private readonly AuthContext $context)
    {
    }

    /**
     * **The first parameter must stay nullable.** Laravel decides whether a `before`
     * callback may run for a guest by reflecting on it: `parameterAllowsGuests()`
     * requires the first parameter to be typed-nullable or to default to null, and an
     * untyped parameter fails that test. It was untyped until 2026-08-17.
     *
     * On this platform every check is a guest check. Identity lives in `AuthContext`,
     * populated from the bearer token; nothing ever calls `Auth::login()`, so
     * `$request->user()` is null on every request and `Illuminate\Auth\Middleware\
     * Authorize` hands the Gate a null user. With the parameter untyped the bridge was
     * therefore skipped on every call, and `->middleware('can:...')` — documented in
     * two places as the way to gate a route — denied everybody, including a user
     * holding the exact permission. No test covered the bridge, so nothing said so.
     *
     * Answering for guests grants nothing by itself: an unauthenticated request has an
     * empty `AuthContext`, `hasPermission()` is false, the callback returns null, and
     * the Gate goes on to deny exactly as it would have.
     */
    public function attach(GateContract $gate): void
    {
        $gate->before(function (?object $user, string $ability): ?bool {
            return $this->context->hasPermission($ability) ? true : null;
        });
    }
}
