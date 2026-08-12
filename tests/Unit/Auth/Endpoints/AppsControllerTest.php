<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth\Endpoints;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\Endpoints\AppsController;
use Abeon\SDK\DTO\AppDescriptor;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Services\ServiceRegistry;
use PHPUnit\Framework\TestCase;

final class AppsControllerTest extends TestCase
{
    public function test_filtering_rule_includes_apps_when_prefix_matches(): void
    {
        $controller = new AppsController(
            new AuthContext(),
            $this->createMock(ServiceRegistry::class),
        );

        $user = $this->user(['crm.contacts.read', 'finance.invoices.read']);

        $this->assertTrue($controller->isVisibleTo(
            new AppDescriptor(name: 'crm', permissions: ['crm.*']),
            $user,
        ));
        $this->assertTrue($controller->isVisibleTo(
            new AppDescriptor(name: 'crm', permissions: ['crm.contacts.read']),
            $user,
        ));
        $this->assertTrue($controller->isVisibleTo(
            new AppDescriptor(name: 'finance', permissions: ['finance.invoices.read']),
            $user,
        ));
    }

    public function test_explicitly_disabled_app_is_invisible_even_with_permission(): void
    {
        $controller = new AppsController(
            new AuthContext(),
            $this->createMock(ServiceRegistry::class),
        );

        // ADR-0010/ADR-0015: an app the organisation does not have is never visible,
        // however well-permitted the user is.
        $this->assertFalse($controller->isVisibleTo(
            new AppDescriptor(name: 'crm', permissions: ['crm.*'], enabled: false),
            $this->user(['crm.contacts.read']),
        ));
    }

    public function test_null_enabled_is_not_treated_as_a_denial(): void
    {
        $controller = new AppsController(
            new AuthContext(),
            $this->createMock(ServiceRegistry::class),
        );

        // Self-registration leaves `enabled` null by contract (ADR-0015 §1), so this
        // layer cannot read null as "not assigned" — doing so would render every
        // self-registered catalogue empty. Resolving it per organisation needs the
        // tenant_apps relation, which belongs to the registry's owning service.
        $this->assertTrue($controller->isVisibleTo(
            new AppDescriptor(name: 'crm', permissions: ['crm.*'], enabled: null),
            $this->user(['crm.contacts.read']),
        ));
    }

    public function test_filtering_rule_excludes_apps_when_no_prefix_matches(): void
    {
        $controller = new AppsController(
            new AuthContext(),
            $this->createMock(ServiceRegistry::class),
        );

        $user = $this->user(['crm.contacts.read']);

        $this->assertFalse($controller->isVisibleTo(
            new AppDescriptor(name: 'finance', permissions: ['finance.*']),
            $user,
        ));
        $this->assertFalse($controller->isVisibleTo(
            new AppDescriptor(name: 'hr', permissions: ['hr.employees.read']),
            $user,
        ));
    }

    public function test_app_with_no_declared_permissions_is_invisible(): void
    {
        $controller = new AppsController(
            new AuthContext(),
            $this->createMock(ServiceRegistry::class),
        );

        $user = $this->user(['crm.contacts.read']);

        $this->assertFalse($controller->isVisibleTo(
            new AppDescriptor(name: 'mystery', permissions: []),
            $user,
        ));
    }

    public function test_user_with_no_permissions_sees_no_apps(): void
    {
        $controller = new AppsController(
            new AuthContext(),
            $this->createMock(ServiceRegistry::class),
        );

        $user = $this->user([]);

        $this->assertFalse($controller->isVisibleTo(
            new AppDescriptor(name: 'crm', permissions: ['crm.*']),
            $user,
        ));
    }

    public function test_prefix_must_be_followed_by_dot_to_match(): void
    {
        $controller = new AppsController(
            new AuthContext(),
            $this->createMock(ServiceRegistry::class),
        );

        $user = $this->user(['crmx.contacts.read']);

        $this->assertFalse($controller->isVisibleTo(
            new AppDescriptor(name: 'crm', permissions: ['crm.*']),
            $user,
        ));
    }

    private function user(array $permissions): User
    {
        return new User(
            id: '42',
            email: 'a@b.c',
            name: null,
            roles: [],
            permissions: $permissions,
            orgId: null,
        );
    }
}
