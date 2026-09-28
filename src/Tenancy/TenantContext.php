<?php

declare(strict_types=1);

namespace Abeon\SDK\Tenancy;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Exceptions\AuthException;
use Closure;

/**
 * The organisation the current unit of work belongs to (ADR-0016 / ADR-0018).
 *
 * Deliberately separate from `AuthContext`, because the tenant does not always
 * come from an authenticated user:
 *
 *   | Context          | Source                                                  |
 *   |------------------|---------------------------------------------------------|
 *   | HTTP request     | falls back to `AuthContext::orgId()` — no wiring needed  |
 *   | Event consumer   | `runFor($event->orgId, ...)` — `EventConsumer` does NOT   |
 *   |                  | populate `AuthContext`, so there is nothing to fall back  |
 *   |                  | on (see `AuthContext`'s class docblock)                   |
 *   | Queued job       | `runFor($orgId, ...)`, captured at dispatch               |
 *   | Console command  | none — must opt in explicitly per invocation              |
 *   | Bound instance   | `ABEON_ORG_ID` — the last fallback, below both of those   |
 *
 * **The bound instance's own organisation is a source too** (ADR-0031 §4). A deployment with
 * `ABEON_ORG_ID` set serves exactly one client, and the value was used only to *refuse*
 * somebody else's token — so inside such an instance a queued job or a console command had no
 * organisation at all: it published envelopes with `org_id: null` and was refused a signed
 * file address, in a deployment where the tenant is a matter of configuration.
 *
 * Bound as `$app->scoped()` in `AbeonServiceProvider`: fresh per HTTP request in
 * php-fpm, reset between requests by Octane. Custom long-lived workers MUST call
 * `clear()` between units of work, or leak one organisation into the next.
 */
class TenantContext
{
    private ?int $orgId = null;

    /**
     * Whether a tenant was set explicitly. Distinct from `$orgId === null`,
     * because "explicitly no organisation" (a platform-level event) and "nothing
     * set yet, fall back to the user" are different states.
     */
    private bool $explicit = false;

    public function __construct(
        private readonly ?AuthContext $auth = null,
        private readonly ?int $instanceOrgId = null,
    ) {
    }

    /**
     * Current organisation, or null when there is none.
     *
     * Null means "no organisation" — never "all organisations". Callers that need
     * a tenant must use `require()`, or check for null and refuse.
     */
    public function current(): ?int
    {
        if ($this->explicit) {
            return $this->orgId;
        }

        // The instance's own organisation comes last: an explicit `runFor()` and an
        // authenticated user both say more about *this* unit of work than the deployment
        // does. A user from another organisation never reaches here — `JwtValidator` refuses
        // that token before any of this (ADR-0031 §4) — so the two can never disagree.
        return $this->auth?->orgId() ?? $this->instanceOrgId;
    }

    /**
     * Current organisation, or throw.
     *
     * The accessor tenant-scoped code should use. Per ADR-0018 a missing tenant is
     * an error, never a wildcard — returning null and letting a caller fall back to
     * "unfiltered" is the cross-organisation disclosure bug this exists to prevent.
     *
     * @throws AuthException
     */
    public function require(): int
    {
        $orgId = $this->current();

        if ($orgId === null) {
            throw AuthException::noOrganisation(
                'No organisation in the current context. In an HTTP request this means no '
                .'authenticated user; in an event consumer or queued job, wrap the work in '
                .'TenantContext::runFor($orgId, ...) — consumers have no auth context to fall back on.',
            );
        }

        return $orgId;
    }

    public function has(): bool
    {
        return $this->current() !== null;
    }

    /**
     * Set the organisation explicitly, overriding the authenticated user's.
     *
     * Prefer `runFor()`, which restores the previous value afterwards. Use this
     * only where a scope-guarded closure genuinely does not fit.
     */
    public function set(?int $orgId): void
    {
        $this->orgId    = $orgId;
        $this->explicit = true;
    }

    /**
     * Drop any explicit organisation, falling back to the authenticated user again.
     */
    public function clear(): void
    {
        $this->orgId    = null;
        $this->explicit = false;
    }

    /**
     * Run a callback with the organisation set, restoring the previous state after.
     *
     * The way consumers and queued jobs enter a tenant:
     *
     *     $tenants->runFor($event->orgId, fn () => $this->handle($event));
     *
     * Restores on exceptions too, so a failing handler cannot leave a worker
     * process pinned to one organisation.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runFor(?int $orgId, Closure $callback): mixed
    {
        $previousOrgId   = $this->orgId;
        $previousExplicit = $this->explicit;

        $this->set($orgId);

        try {
            return $callback();
        } finally {
            $this->orgId    = $previousOrgId;
            $this->explicit = $previousExplicit;
        }
    }
}
