<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

final readonly class ProblemDetails
{
    /**
     * @param  array<string, mixed>  $extensions  Additional RFC 7807 extension members.
     */
    public function __construct(
        public string $type,
        public string $title,
        public int $status,
        public ?string $detail = null,
        public ?string $instance = null,
        public array $extensions = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $known      = ['type', 'title', 'status', 'detail', 'instance'];
        $extensions = array_diff_key($data, array_flip($known));

        return new self(
            type:       (string) ($data['type'] ?? 'about:blank'),
            title:      (string) ($data['title'] ?? 'Error'),
            status:     (int) ($data['status'] ?? 500),
            detail:     isset($data['detail']) ? (string) $data['detail'] : null,
            instance:   isset($data['instance']) ? (string) $data['instance'] : null,
            extensions: $extensions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            array_filter([
                'type'     => $this->type,
                'title'    => $this->title,
                'status'   => $this->status,
                'detail'   => $this->detail,
                'instance' => $this->instance,
            ], fn ($v) => $v !== null),
            $this->extensions,
        );
    }
}
