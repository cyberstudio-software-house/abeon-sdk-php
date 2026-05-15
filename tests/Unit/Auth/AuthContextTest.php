<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\DTO\User;
use PHPUnit\Framework\TestCase;

final class AuthContextTest extends TestCase
{
    public function test_starts_unauthenticated(): void
    {
        $ctx = new AuthContext();
        $this->assertNull($ctx->user());
        $this->assertFalse($ctx->check());
    }

    public function test_set_user(): void
    {
        $ctx = new AuthContext();
        $user = $this->makeUser(['admin'], ['crm.contacts.read']);
        $ctx->set($user);

        $this->assertSame($user, $ctx->user());
        $this->assertTrue($ctx->check());
    }

    public function test_clear(): void
    {
        $ctx = new AuthContext();
        $ctx->set($this->makeUser());
        $ctx->clear();

        $this->assertNull($ctx->user());
        $this->assertFalse($ctx->check());
    }

    public function test_has_permission_returns_false_when_unauthenticated(): void
    {
        $ctx = new AuthContext();
        $this->assertFalse($ctx->hasPermission('crm.contacts.read'));
    }

    public function test_has_permission_checks_user_claim(): void
    {
        $ctx = new AuthContext();
        $ctx->set($this->makeUser([], ['crm.contacts.read']));

        $this->assertTrue($ctx->hasPermission('crm.contacts.read'));
        $this->assertFalse($ctx->hasPermission('crm.contacts.write'));
    }

    public function test_has_role_checks_user_role(): void
    {
        $ctx = new AuthContext();
        $ctx->set($this->makeUser(['admin']));

        $this->assertTrue($ctx->hasRole('admin'));
        $this->assertFalse($ctx->hasRole('owner'));
    }

    private function makeUser(array $roles = [], array $permissions = []): User
    {
        return new User(
            id: '1',
            email: 'a@b.c',
            name: null,
            roles: $roles,
            permissions: $permissions,
            orgId: null,
        );
    }
}
