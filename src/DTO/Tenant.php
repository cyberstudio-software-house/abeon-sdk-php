<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

/**
 * A client organisation (ADR-0016).
 *
 * Returned by `GET /api/v1/auth/tenants` as the list a user may switch between.
 * Deliberately thin — identity and display only. Roles and permissions are *not*
 * here: they are held per membership and arrive in the re-issued JWT
 * (ADR-0017), so a client can never derive its own authorisation from this list.
 *
 * Canonical schema: `schemas/dto/tenant.json`.
 */
final readonly class Tenant
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?string $logoUrl = null,
        /** True for the organisation the caller's token is scoped to. Server-populated. */
        public ?bool $current = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:      (int) ($data['id'] ?? 0),
            name:    (string) ($data['name'] ?? ''),
            slug:    (string) ($data['slug'] ?? ''),
            logoUrl: isset($data['logo_url']) ? (string) $data['logo_url'] : null,
            current: isset($data['current']) ? (bool) $data['current'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'slug'     => $this->slug,
            'logo_url' => $this->logoUrl,
            'current'  => $this->current,
        ];
    }
}
