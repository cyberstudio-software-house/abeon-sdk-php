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
        /**
         * Whether this application is assigned to the caller's organisation — the
         * presence of a `tenant_apps` row for `(org_id, app)` (ADR-0015 as amended
         * by ADR-0016).
         *
         * **Organisation-relative:** the same app yields different values for
         * different callers. A cached catalogue is only valid for the organisation
         * it was fetched for. `null` on self-registration — an app cannot know
         * which organisations hold it.
         */
        public ?bool $enabled = null,
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
            enabled:     isset($data['enabled']) ? (bool) $data['enabled'] : null,
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
            'enabled'     => $this->enabled,
        ];
    }
}
