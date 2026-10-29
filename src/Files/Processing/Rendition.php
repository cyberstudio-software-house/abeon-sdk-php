<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Processing;

/**
 * One file written by processing: the cleaned original (`preset` null) or a variant.
 */
final readonly class Rendition
{
    public function __construct(
        public string $path,
        public string $mime,
        public ?string $preset,
        public ?int $width,
        public ?int $height,
    ) {
    }
}
