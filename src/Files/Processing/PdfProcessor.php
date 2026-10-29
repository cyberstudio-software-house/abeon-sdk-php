<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Processing;

/**
 * PDFs with poppler: the page count, and the first page as an image for the `inside` presets.
 * The document itself is stored as it arrived.
 */
final class PdfProcessor
{
    public function __construct(
        private readonly Toolbox $tools,
        private readonly ImageProcessor $images,
    ) {
    }

    /**
     * @param  array<string, array{width: int, height?: int|null, fit?: string}>  $presets
     * @return array{pages: int, variants: list<Rendition>}
     */
    public function process(string $workDir, string $source, array $presets): array
    {
        $info = $this->tools->run($workDir, 'pdfinfo', $source);
        $pages = preg_match('/^Pages:\s+(\d+)/m', $info, $match) === 1 ? (int) $match[1] : 0;

        $this->tools->run($workDir, 'pdftoppm', '-f', '1', '-l', '1', '-png', '-scale-to', '1280', '-singlefile', $source, "{$workDir}/preview");

        $inside = array_filter($presets, static fn (array $preset): bool => ($preset['fit'] ?? 'inside') !== 'cover');

        return [
            'pages'    => $pages,
            'variants' => $this->images->variants($workDir, "{$workDir}/preview.png", 'image/png', $inside, 0.5, 0.5),
        ];
    }
}
