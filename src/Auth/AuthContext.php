<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\DTO\User;

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
