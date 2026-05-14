<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

final readonly class Role
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $name,
        public array $permissions = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $permissions = $data['permissions'] ?? [];

        return new self(
            name:        (string) $data['name'],
            permissions: is_array($permissions)
                ? array_values(array_map('strval', $permissions))
                : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'permissions' => $this->permissions,
        ];
    }
}
