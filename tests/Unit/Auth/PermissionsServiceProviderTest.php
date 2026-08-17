<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\PermissionsServiceProvider;
use Abeon\SDK\DTO\User;
use Illuminate\Auth\Access\Gate;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

/**
 * The Gate bridge, which had no test until 2026-08-17 and did not work.
 *
 * `->middleware('can:some.permission.here')` is documented in the boilerplate's
 * routes file and in `EnsureAppEntitled` as the way to gate a route on a permission.
 * It denied everybody. Laravel decides whether a `Gate::before` callback may run for
 * a guest by reflecting on its first parameter, and the bridge's was untyped — so the
 * callback was skipped whenever the Gate had no user.
 *
 * Which is always. Identity on this platform lives in `AuthContext`, filled from the
 * bearer token; nothing calls `Auth::login()`, so `$request->user()` is null on every
 * request. The bridge was skipped on every check without ever failing loudly: a 403
 * for somebody who lacks the permission and a 403 for somebody who holds it look the
 * same from outside.
 *
 * The first test here is the one that would have caught it.
 */
final class PermissionsServiceProviderTest extends TestCase
{
    public function test_it_answers_for_a_null_user_because_identity_lives_in_authcontext(): void
    {
        $gate = $this->gateFor(['crm.contacts.read']);

        // `forUser(null)` is what `Illuminate\Auth\Middleware\Authorize` does on every
        // request in this platform. This assertion failing means `can:` is dead again.
        $this->assertTrue($gate->forUser(null)->allows('crm.contacts.read'));
    }

    public function test_a_permission_not_held_is_still_denied(): void
    {
        $gate = $this->gateFor(['crm.contacts.read']);

        $this->assertFalse($gate->forUser(null)->allows('core.users.manage'));
    }

    public function test_an_unauthenticated_context_grants_nothing(): void
    {
        // Answering for guests is not the same as trusting them: with no user in the
        // context there is no permission to match, so every ability falls through to
        // the Gate's own denial.
        $gate = $this->gate(new AuthContext());

        $this->assertFalse($gate->forUser(null)->allows('crm.contacts.read'));
    }

    public function test_the_match_is_the_whole_permission_not_a_prefix(): void
    {
        // Permissions are three literal segments (ADR-0001). `AuthContext` owns this
        // rule; the assertion is here because the Gate is where a prefix bug would be
        // felt — as a route silently opening.
        $gate = $this->gateFor(['core.users.manage']);

        $this->assertFalse($gate->forUser(null)->allows('core.users.manage_nothing'));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function gateFor(array $permissions): Gate
    {
        $context = new AuthContext();
        $context->set(new User(
            id: '1',
            email: 'admin@abeon.dev',
            name: 'Dev Admin',
            roles: ['admin'],
            permissions: $permissions,
            orgId: 1,
        ));

        return $this->gate($context);
    }

    private function gate(AuthContext $context): Gate
    {
        $container = new Container();
        $gate = new Gate($container, static fn () => null);

        (new PermissionsServiceProvider($context))->attach($gate);

        return $gate;
    }
}
