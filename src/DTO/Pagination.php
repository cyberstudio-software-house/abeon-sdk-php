<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

final readonly class Pagination
{
    public function __construct(
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $perPage = max(1, (int) ($data['per_page'] ?? 25));
        $total   = max(0, (int) ($data['total'] ?? 0));
        $derived = max(1, (int) ceil($total / $perPage));

        return new self(
            currentPage: (int) ($data['current_page'] ?? 1),
            perPage:     $perPage,
            total:       $total,
            lastPage:    (int) ($data['last_page'] ?? $derived),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage,
            'per_page'     => $this->perPage,
            'total'        => $this->total,
            'last_page'    => $this->lastPage,
        ];
    }
}
