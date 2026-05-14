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

    public function attach(GateContract $gate): void
    {
        $gate->before(function ($_, string $ability): ?bool {
            return $this->context->hasPermission($ability) ? true : null;
        });
    }
}
