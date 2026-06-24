<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

final readonly class AppDescriptor
{
    /**
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $name,
        public ?string $label = null,
        public ?string $path = null,
        public ?string $icon = null,
        public ?string $version = null,
        public array $permissions = [],
        public ?string $category = null,
        public ?int $order = null,
        public ?string $mode = null,
        public ?bool $fullscreen = null,
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
            label:       isset($data['label']) ? (string) $data['label'] : null,
            path:        isset($data['path']) ? (string) $data['path'] : null,
            icon:        isset($data['icon']) ? (string) $data['icon'] : null,
            version:     isset($data['version']) ? (string) $data['version'] : null,
            permissions: is_array($permissions)
                ? array_values(array_map('strval', $permissions))
                : [],
            category:    isset($data['category']) ? (string) $data['category'] : null,
            order:       isset($data['order']) ? (int) $data['order'] : null,
            mode:        isset($data['mode']) ? (string) $data['mode'] : null,
            fullscreen:  isset($data['fullscreen']) ? (bool) $data['fullscreen'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'label'       => $this->label,
            'path'        => $this->path,
            'icon'        => $this->icon,
            'version'     => $this->version,
            'permissions' => $this->permissions,
            'category'    => $this->category,
            'order'       => $this->order,
            'mode'        => $this->mode,
            'fullscreen'  => $this->fullscreen,
        ];
    }
}
