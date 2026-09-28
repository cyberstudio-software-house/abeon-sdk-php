<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Tenancy;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TenantContextTest extends TestCase
{
    public function test_falls_back_to_the_authenticated_user(): void
    {
        // The HTTP path needs no wiring: AuthMiddleware populates AuthContext and
        // the tenant follows from it.
        $this->assertSame(7, $this->context(orgId: 7)->current());
    }

    public function test_is_null_with_no_user_and_nothing_set(): void
    {
        $this->assertNull((new TenantContext(new AuthContext()))->current());
        $this->assertNull((new TenantContext())->current());
    }

    public function test_require_throws_when_there_is_no_organisation(): void
    {
        $this->expectException(AuthException::class);
        (new TenantContext(new AuthContext()))->require();
    }

    public function test_explicit_organisation_overrides_the_user(): void
    {
        $context = $this->context(orgId: 7);
        $context->set(9);

        $this->assertSame(9, $context->current());
    }

    public function test_explicit_null_is_distinct_from_unset(): void
    {
        // "Explicitly no organisation" (a platform-level event) must not silently
        // fall back to the authenticated user's — that would scope platform work
        // to whoever happened to trigger it.
        $context = $this->context(orgId: 7);
        $context->set(null);

        $this->assertNull($context->current());
        $this->assertFalse($context->has());

        $context->clear();

        $this->assertSame(7, $context->current());
    }

    public function test_run_for_restores_the_previous_organisation(): void
    {
        $context = $this->context(orgId: 7);

        $inner = $context->runFor(9, function () use ($context) {
            return $context->current();
        });

        $this->assertSame(9, $inner);
        $this->assertSame(7, $context->current(), 'runFor() must restore what it found');
    }

    public function test_run_for_restores_after_an_exception(): void
    {
        $context = $this->context(orgId: 7);

        try {
            $context->runFor(9, function (): void {
                throw new RuntimeException('handler blew up');
            });
        } catch (RuntimeException) {
            // expected
        }

        // A failing consumer handler must not leave the worker pinned to the
        // organisation it was processing.
        $this->assertSame(7, $context->current());
    }

    public function test_run_for_nests(): void
    {
        $context = $this->context(orgId: 1);

        $seen = [];
        $context->runFor(2, function () use ($context, &$seen): void {
            $seen[] = $context->current();
            $context->runFor(3, function () use ($context, &$seen): void {
                $seen[] = $context->current();
            });
            $seen[] = $context->current();
        });
        $seen[] = $context->current();

        $this->assertSame([2, 3, 2, 1], $seen);
    }

    public function test_consumer_shaped_usage_without_an_auth_context(): void
    {
        // EventConsumer does not populate AuthContext, so a handler has nothing to
        // fall back on and must enter the tenant explicitly (ADR-0018).
        $context = new TenantContext();

        $this->assertNull($context->current());

        $orgId = $context->runFor(42, fn () => $context->require());

        $this->assertSame(42, $orgId);
        $this->assertNull($context->current());
    }

    /**
     * ADR-0031 §4: a deployment with `ABEON_ORG_ID` set serves one client. The value was
     * read only to refuse somebody else's token, so a queued job or a console command in
     * such an instance had no organisation at all — it published envelopes with
     * `org_id: null` and was refused a signed file address, in a deployment where the
     * tenant is a matter of configuration.
     */
    public function test_a_bound_instance_has_its_own_organisation(): void
    {
        $context = new TenantContext(null, 7);

        $this->assertSame(7, $context->current());
        $this->assertSame(7, $context->require());
    }

    public function test_an_explicit_tenant_still_wins_in_a_bound_instance(): void
    {
        $context = new TenantContext(null, 7);

        $this->assertSame(9, $context->runFor(9, fn (): int => $context->require()));

        // Including "explicitly no organisation", which is a different state from unset.
        $this->assertNull($context->runFor(null, fn (): ?int => $context->current()));
        $this->assertSame(7, $context->current());
    }

    public function test_an_unbound_service_is_unchanged(): void
    {
        $this->assertNull((new TenantContext())->current());
    }

    private function context(?int $orgId): TenantContext
    {
        $auth = new AuthContext();
        $auth->set(new User(
            id: '1', email: 'a@b.c', name: null,
            roles: [], permissions: [], orgId: $orgId,
        ));

        return new TenantContext($auth);
    }
}
