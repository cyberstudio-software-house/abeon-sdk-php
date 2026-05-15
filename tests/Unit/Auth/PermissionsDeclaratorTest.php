<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth;

use Abeon\SDK\Auth\PermissionsDeclarator;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Permission;
use Abeon\SDK\Events\EventPublisher;
use Abeon\SDK\DTO\Actor;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class PermissionsDeclaratorTest extends TestCase
{
    private function config(array $permissions = []): AbeonConfig
    {
        return new AbeonConfig(new Repository([
            'abeon' => [
                'service'     => ['name' => 'crm'],
                'permissions' => $permissions,
            ],
        ]));
    }

    private function recordingPublisher(): EventPublisher
    {
        return new class implements EventPublisher {
            /** @var list<array{routingKey: string, data: array, actor: ?Actor, causationId: ?string}> */
            public array $published = [];

            public function publish(
                string $routingKey,
                array $data,
                ?Actor $actor = null,
                ?string $causationId = null,
            ): string {
                $this->published[] = compact('routingKey', 'data', 'actor', 'causationId');
                return 'event-id-' . count($this->published);
            }
        };
    }

    public function test_declared_returns_permission_objects(): void
    {
        $declarator = new PermissionsDeclarator(
            $this->config(['crm.contacts.read', 'crm.deals.manage']),
            $this->recordingPublisher(),
        );

        $declared = $declarator->declared();

        $this->assertCount(2, $declared);
        $this->assertContainsOnlyInstancesOf(Permission::class, $declared);
        $this->assertSame('crm.contacts.read', $declared[0]->name);
    }

    public function test_payload_contains_service_and_permissions(): void
    {
        $declarator = new PermissionsDeclarator(
            $this->config(['crm.contacts.read', 'crm.contacts.write']),
            $this->recordingPublisher(),
        );

        $payload = $declarator->payload();

        $this->assertSame('crm', $payload['service']);
        $this->assertSame(['crm.contacts.read', 'crm.contacts.write'], $payload['permissions']);
    }

    public function test_declare_publishes_event(): void
    {
        $publisher = $this->recordingPublisher();
        $declarator = new PermissionsDeclarator(
            $this->config(['crm.contacts.read']),
            $publisher,
        );

        $eventId = $declarator->declare();

        $this->assertNotEmpty($eventId);
        $this->assertCount(1, $publisher->published);
        $this->assertSame('service.permissions.declared', $publisher->published[0]['routingKey']);
        $this->assertSame(
            ['service' => 'crm', 'permissions' => ['crm.contacts.read']],
            $publisher->published[0]['data'],
        );
    }

    public function test_empty_permissions_yields_empty_list(): void
    {
        $declarator = new PermissionsDeclarator($this->config([]), $this->recordingPublisher());

        $this->assertSame([], $declarator->declared());
        $this->assertSame(['service' => 'crm', 'permissions' => []], $declarator->payload());
    }
}
