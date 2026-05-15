<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\DTO\User;

/**
 * Request-scoped holder for the currently authenticated user.
 *
 * Bound as `$app->scoped()` in AbeonServiceProvider — fresh per HTTP request
 * in php-fpm.
 *
 * Octane / Swoole compatibility (LO-5):
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

    public function clear(): void
    {
        $this->user = null;
    }
}
