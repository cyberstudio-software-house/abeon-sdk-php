<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Permission;
use Abeon\SDK\Events\EventPublisher;

/**
 * Reads permissions DECLARED by this service from config('abeon.permissions')
 * and publishes the `service.permissions.declared` event so Auth can build
 * the master RBAC catalog without per-service migrations (M5).
 *
 * Triggered manually via `php artisan abeon:permissions:declare` —
 * recommended as a post-deploy step alongside `abeon:registry:register`.
 */
class PermissionsDeclarator
{
    public function __construct(
        private readonly AbeonConfig $config,
        private readonly EventPublisher $publisher,
    ) {
    }

    /**
     * @return list<Permission>
     */
    public function declared(): array
    {
        return array_map(
            fn (string $name) => new Permission(name: $name),
            $this->config->declaredPermissions(),
        );
    }

    /**
     * @return array{service: string, permissions: list<string>}
     */
    public function payload(): array
    {
        return [
            'service'     => $this->config->serviceName(),
            'permissions' => $this->config->declaredPermissions(),
        ];
    }

    /**
     * Publish the declaration event. Returns the generated event_id.
     */
    public function declare(): string
    {
        return $this->publisher->publish('service.permissions.declared', $this->payload());
    }
}
