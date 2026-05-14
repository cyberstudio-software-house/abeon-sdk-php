<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Permission;

/**
 * Reads permissions DECLARED by this service from config('abeon.permissions')
 * and builds the payload for the `service.permissions.declared` event (M5).
 *
 * The actual event publish lands in Sprint 2 once EventPublisher is wired —
 * Sprint 1 only stages the data so consumers can call ::eventPayload().
 */
class PermissionsDeclarator
{
    public function __construct(private readonly AbeonConfig $config)
    {
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
     * Payload for `service.permissions.declared`.
     *
     * @return array{service: string, permissions: list<string>}
     */
    public function eventPayload(): array
    {
        return [
            'service'     => $this->config->serviceName(),
            'permissions' => $this->config->declaredPermissions(),
        ];
    }
}
