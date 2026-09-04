<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;

/**
 * Request-scoped holder for the currently authenticated user.
 *
 * Bound as `$app->scoped()` in AbeonServiceProvider — fresh per HTTP request
 * in php-fpm.
 *
 * Octane / Swoole compatibility:
 *   - Scoped bindings are reset between requests by Octane.
 *   - Custom long-lived workers MUST call `clear()` between requests, or
 *     leak the previous request's auth into the next one.
 *   - `EventConsumer` does NOT set this — events have an `Actor` field
 *     in the envelope; handlers should consult `Event::$actor`, not
 *     `auth()->user()`.
 */
class AuthContext
{
    private ?User $user = null;

    public function set(User $user): void
    {
        $this->user = $user;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->user?->hasPermission($permission) ?? false;
    }

    public function hasRole(string $role): bool
    {
        return $this->user?->hasRole($role) ?? false;
    }

    /**
     * Organisation the current request is scoped to (ADR-0016), or null when there
     * is no authenticated user — a console command, a queued job, or a consumer.
     *
     * Consumers must NOT reach for this: `EventConsumer` does not populate
     * `AuthContext`. Take the tenant from `Event::$orgId` instead (ADR-0002).
     */
    public function orgId(): ?int
    {
        return $this->user?->orgId;
    }

    /**
     * Same as `orgId()`, but throws when there is no organisation.
     *
     * This is the accessor tenant-scoped code should use. Per ADR-0018 the absence
     * of a tenant is an error, never a wildcard: returning null here and letting a
     * caller fall back to "unfiltered" is the cross-organisation disclosure bug this
     * whole mechanism exists to prevent.
     *
     * @throws \Abeon\SDK\Exceptions\AuthException
     */
    public function requireOrgId(): int
    {
        $orgId = $this->orgId();

        if ($orgId === null) {
            throw AuthException::noOrganisation(
                $this->user === null
                    ? 'No authenticated user, so no organisation to scope to'
                    : 'Authenticated user carries no organisation',
            );
        }

        return $orgId;
    }

    public function clear(): void
    {
        $this->user = null;
    }
}
