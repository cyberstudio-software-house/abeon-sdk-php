<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

final readonly class Actor
{
    public function __construct(
        public string $type,
        public ?string $userId = null,
        public ?string $serviceName = null,
    ) {
    }

    public static function user(string $userId): self
    {
        return new self(type: 'user', userId: $userId);
    }

    public static function service(string $serviceName): self
    {
        return new self(type: 'service', serviceName: $serviceName);
    }

    public static function system(): self
    {
        return new self(type: 'system');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type:        (string) ($data['type'] ?? 'system'),
            userId:      isset($data['user_id']) ? (string) $data['user_id'] : null,
            serviceName: isset($data['service_name']) ? (string) $data['service_name'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'type'         => $this->type,
            'user_id'      => $this->userId,
            'service_name' => $this->serviceName,
        ], fn ($v) => $v !== null);
    }
}
