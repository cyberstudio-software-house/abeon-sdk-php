<?php

declare(strict_types=1);

namespace Abeon\SDK\DTO;

/**
 * A single cross-app search hit (ADR-0011). Returned by the abeon-search
 * service and surfaced as a command-palette result on the frontend.
 */
final readonly class SearchResult
{
    public function __construct(
        public string $id,
        public string $title,
        public string $sourceApp,
        public string $entityType,
        public string $url,
        public ?string $subtitle = null,
        public ?string $icon = null,
        public ?float $score = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id:         (string) $data['id'],
            title:      (string) $data['title'],
            sourceApp:  (string) $data['source_app'],
            entityType: (string) $data['entity_type'],
            url:        (string) $data['url'],
            subtitle:   isset($data['subtitle']) ? (string) $data['subtitle'] : null,
            icon:       isset($data['icon']) ? (string) $data['icon'] : null,
            score:      isset($data['score']) ? (float) $data['score'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'subtitle'    => $this->subtitle,
            'source_app'  => $this->sourceApp,
            'entity_type' => $this->entityType,
            'url'         => $this->url,
            'icon'        => $this->icon,
            'score'       => $this->score,
        ];
    }
}
