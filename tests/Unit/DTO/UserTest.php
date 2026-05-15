<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\DTO;

use Abeon\SDK\DTO\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function test_from_array_round_trip(): void
    {
        $data = [
            'id'          => '42',
            'email'       => 'jan@example.com',
            'name'        => 'Jan',
            'roles'       => ['admin', 'sales_manager'],
            'permissions' => ['crm.contacts.read', 'crm.contacts.write'],
            'org_id'      => 1,
        ];

        $user = User::fromArray($data);

        $this->assertSame('42', $user->id);
        $this->assertSame('jan@example.com', $user->email);
        $this->assertSame('Jan', $user->name);
        $this->assertSame(['admin', 'sales_manager'], $user->roles);
        $this->assertSame(['crm.contacts.read', 'crm.contacts.write'], $user->permissions);
        $this->assertSame(1, $user->orgId);

        // Round-trip via toArray()
        $this->assertSame($data, $user->toArray());
    }

    public function test_handles_missing_optional_fields(): void
    {
        $user = User::fromArray([
            'id'          => '1',
            'email'       => 'a@b.c',
            'roles'       => [],
            'permissions' => [],
        ]);

        $this->assertNull($user->name);
        $this->assertNull($user->orgId);
    }

    public function test_has_permission(): void
    {
        $user = User::fromArray([
            'id'          => '1',
            'email'       => 'a@b.c',
            'roles'       => [],
            'permissions' => ['crm.contacts.read'],
        ]);

        $this->assertTrue($user->hasPermission('crm.contacts.read'));
        $this->assertFalse($user->hasPermission('crm.contacts.write'));
    }

    public function test_has_role(): void
    {
        $user = User::fromArray([
            'id'          => '1',
            'email'       => 'a@b.c',
            'roles'       => ['admin'],
            'permissions' => [],
        ]);

        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse($user->hasRole('owner'));
    }

    public function test_coerces_role_and_permission_arrays_to_strings(): void
    {
        $user = User::fromArray([
            'id'          => '1',
            'email'       => 'a@b.c',
            'roles'       => ['admin', 42],
            'permissions' => ['perm.a.b', 99],
        ]);

        $this->assertSame(['admin', '42'], $user->roles);
        $this->assertSame(['perm.a.b', '99'], $user->permissions);
    }
}
