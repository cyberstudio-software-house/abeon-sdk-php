<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
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

    // --- organisation context (ADR-0016 / ADR-0018) ---

    public function test_org_id_returns_the_users_organisation(): void
    {
        $ctx = new AuthContext();
        $ctx->set($this->makeUser(orgId: 7));

        $this->assertSame(7, $ctx->orgId());
        $this->assertSame(7, $ctx->requireOrgId());
    }

    public function test_org_id_is_null_with_no_user(): void
    {
        $this->assertNull((new AuthContext())->orgId());
    }

    public function test_require_org_id_throws_with_no_user(): void
    {
        // The whole point of requireOrgId(): a missing tenant must fail loudly
        // rather than let a caller fall back to an unscoped query.
        $this->expectException(AuthException::class);
        (new AuthContext())->requireOrgId();
    }

    public function test_require_org_id_throws_when_the_user_carries_no_organisation(): void
    {
        $ctx = new AuthContext();
        $ctx->set($this->makeUser(orgId: null));

        $this->expectException(AuthException::class);
        $ctx->requireOrgId();
    }

    public function test_clear_drops_the_organisation_too(): void
    {
        $ctx = new AuthContext();
        $ctx->set($this->makeUser(orgId: 7));
        $ctx->clear();

        // Leaking a previous request's organisation into the next one is the
        // cross-tenant bug this context exists to avoid (see the class PHPDoc).
        $this->assertNull($ctx->orgId());
    }

    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    private function makeUser(array $roles = [], array $permissions = [], ?int $orgId = null): User
    {
        return new User(
            id: '1',
            email: 'a@b.c',
            name: null,
            roles: $roles,
            permissions: $permissions,
            orgId: $orgId,
        );
    }
}
