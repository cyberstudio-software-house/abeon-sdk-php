<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

final readonly class Permission
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name:        (string) $data['name'],
            description: isset($data['description']) ? (string) $data['description'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
        ];
    }
}
