<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

final readonly class User
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $email,
        public ?string $name,
        public array $roles,
        public array $permissions,
        public ?int $orgId,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:          (string) $data['id'],
            email:       (string) $data['email'],
            name:        isset($data['name']) ? (string) $data['name'] : null,
            roles:       self::stringList($data['roles'] ?? []),
            permissions: self::stringList($data['permissions'] ?? []),
            orgId:       isset($data['org_id']) ? (int) $data['org_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'email'       => $this->email,
            'name'        => $this->name,
            'roles'       => $this->roles,
            'permissions' => $this->permissions,
            'org_id'      => $this->orgId,
        ];
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}
