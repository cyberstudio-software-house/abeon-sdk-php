<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Processing;

use Abeon\SDK\Files\FileRejected;

/**
 * Images with libvips. The original is rotated upright and written again without its metadata —
 * all of EXIF, XMP and IPTC, which carry GPS, the author and the camera's serial number — keeping
 * only the colour profile. Variants are WebP plus a fallback in a format every browser reads.
 */
final class ImageProcessor
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'image/avif' => 'avif',
    ];

    private const ORIGINAL_OPTIONS = [
        'image/jpeg' => '[keep=icc,Q=90]',
        'image/png'  => '[keep=icc]',
        'image/webp' => '[keep=icc,Q=90]',
        'image/avif' => '[keep=icc,Q=70]',
    ];

    private const FALLBACK = [
        'image/jpeg' => 'image/jpeg',
        'image/avif' => 'image/jpeg',
        'image/png'  => 'image/png',
        'image/gif'  => 'image/png',
    ];

    private const VARIANT_OPTIONS = [
        'image/webp' => '[keep=none,Q=80]',
        'image/jpeg' => '[keep=none,Q=82]',
        'image/png'  => '[keep=none]',
    ];

    public function __construct(
        private readonly Toolbox $tools,
        private readonly int $maxPixels,
    ) {
    }

    public static function handles(string $mime): bool
    {
        return isset(self::EXTENSIONS[$mime]);
    }

    /**
     * @param  array<string, array{width: int, height?: int|null, fit?: string}>  $presets
     * @return array{original: Rendition|null, variants: list<Rendition>}
     */
    public function process(string $workDir, string $source, string $mime, array $presets, float $focalX, float $focalY, bool $cleanOriginal): array
    {
        [$width, $height] = $this->dimensions($workDir, $source);

        if ($width * $height > $this->maxPixels) {
            throw new FileRejected(FileRejected::TOO_MANY_PIXELS, "{$width}×{$height} is more than {$this->maxPixels} pixels.");
        }

        $original = null;
        $base = $source;

        if ($cleanOriginal) {
            $original = $this->cleanOriginal($workDir, $source, $mime);
            $base = $original->path;
        }

        return ['original' => $original, 'variants' => $this->variants($workDir, $base, $mime, $presets, $focalX, $focalY)];
    }

    /**
     * @param  array<string, array{width: int, height?: int|null, fit?: string}>  $presets
     * @return list<Rendition>
     */
    public function variants(string $workDir, string $base, string $mime, array $presets, float $focalX, float $focalY): array
    {
        [$width, $height] = $this->dimensions($workDir, $base);
        $formats = array_values(array_unique(array_filter(['image/webp', self::FALLBACK[$mime] ?? null])));
        $variants = [];

        foreach ($presets as $name => $preset) {
            $targetWidth = (int) $preset['width'];
            $targetHeight = isset($preset['height']) ? (int) $preset['height'] : null;
            $cover = ($preset['fit'] ?? 'inside') === 'cover' && $targetHeight !== null;

            foreach ($formats as $format) {
                $out = "{$workDir}/{$name}.".self::EXTENSIONS[$format];
                $target = $out.self::VARIANT_OPTIONS[$format];

                if ($cover) {
                    $this->cover($workDir, $base, $target, $width, $height, $targetWidth, $targetHeight, $focalX, $focalY);
                } else {
                    $this->tools->run($workDir, 'vips', 'thumbnail', $base, $target, (string) $targetWidth,
                        '--height', (string) ($targetHeight ?? 100000), '--size', 'down');
                }

                [$w, $h] = $this->dimensions($workDir, $out);
                $variants[] = new Rendition($out, $format, (string) $name, $w, $h);
            }
        }

        return $variants;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function dimensions(string $workDir, string $path): array
    {
        return [
            (int) trim($this->tools->run($workDir, 'vipsheader', '-f', 'width', $path)),
            (int) trim($this->tools->run($workDir, 'vipsheader', '-f', 'height', $path)),
        ];
    }

    private function cleanOriginal(string $workDir, string $source, string $mime): Rendition
    {
        $out = "{$workDir}/original.".self::EXTENSIONS[$mime];

        if ($mime === 'image/gif') {
            copy($source, $out);
        } else {
            $this->tools->run($workDir, 'vips', 'autorot', $source, $out.self::ORIGINAL_OPTIONS[$mime]);
        }

        [$width, $height] = $this->dimensions($workDir, $out);

        return new Rendition($out, $mime, null, $width, $height);
    }

    /**
     * Fill the box, cropped around the focal point. Never enlarges: a smaller image is cropped to
     * the box's proportions instead.
     */
    private function cover(string $workDir, string $base, string $target, int $width, int $height, int $boxWidth, int $boxHeight, float $focalX, float $focalY): void
    {
        $scale = min(1.0, max($boxWidth / $width, $boxHeight / $height));
        $scaledWidth = max(1, (int) round($width * $scale));
        $scaledHeight = max(1, (int) round($height * $scale));

        $cropWidth = min($scaledWidth, $boxWidth, (int) round($scaledHeight * $boxWidth / $boxHeight));
        $cropHeight = min($scaledHeight, (int) round($cropWidth * $boxHeight / $boxWidth));

        $left = (int) max(0, min($scaledWidth - $cropWidth, round($focalX * $scaledWidth - $cropWidth / 2)));
        $top = (int) max(0, min($scaledHeight - $cropHeight, round($focalY * $scaledHeight - $cropHeight / 2)));

        $scaled = "{$workDir}/scaled.png";
        $this->tools->run($workDir, 'vips', 'thumbnail', $base, $scaled.'[compression=0]', (string) $scaledWidth,
            '--height', (string) $scaledHeight, '--size', 'force');
        $this->tools->run($workDir, 'vips', 'extract_area', $scaled, $target,
            (string) $left, (string) $top, (string) $cropWidth, (string) $cropHeight);
        @unlink($scaled);
    }
}
