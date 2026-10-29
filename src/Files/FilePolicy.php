<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

/**
 * What an application accepts: types with a size limit each, and when an upload goes in parts.
 * Read from `abeon.files` so each application sets its own list.
 */
final readonly class FilePolicy
{
    private const ALIASES = ['image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'application/x-pdf' => 'application/pdf'];

    /**
     * A ZIP container is what content sniffing sees for an office document, and plain text is
     * what it sees for CSV; the declared type is believed only within that family.
     */
    private const SNIFFED_AS = [
        'application/zip' => '#^application/(zip|vnd\.openxmlformats-officedocument\..+|vnd\.oasis\.opendocument\..+)$#',
        'text/plain'      => '#^text/(plain|csv)$#',
        'text/csv'        => '#^text/(plain|csv)$#',
        'application/csv' => '#^text/csv$#',
    ];

    /**
     * @param  array<string, int>  $types  type => maximum bytes
     */
    public function __construct(
        public array $types,
        public int $multipartThreshold,
        public int $partSize,
        public int $pendingHours,
        public int $trashDays,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, int|string> $types */
        $types = $config['types'] ?? [];

        return new self(
            array_map('intval', array_change_key_case($types)),
            (int) ($config['multipart_threshold'] ?? 64 * 1024 ** 2),
            (int) ($config['part_size'] ?? 16 * 1024 ** 2),
            (int) ($config['pending_hours'] ?? 24),
            (int) ($config['trash_days'] ?? 30),
        );
    }

    public static function normalise(string $type): string
    {
        $type = strtolower(trim(explode(';', $type, 2)[0]));

        return self::ALIASES[$type] ?? $type;
    }

    public function assertAccepts(string $type, int $size): void
    {
        $limit = $this->types[$type] ?? null;

        if ($limit === null) {
            throw new FileRejected(FileRejected::TYPE_NOT_ALLOWED, "Files of type '{$type}' are not accepted.");
        }

        if ($size < 1 || $size > $limit) {
            throw new FileRejected(FileRejected::TOO_LARGE, "A '{$type}' file may be at most {$limit} bytes.");
        }
    }

    public function contentMatches(string $declared, string $sniffed): bool
    {
        $sniffed = self::normalise($sniffed);

        if ($sniffed === $declared) {
            return true;
        }

        $family = self::SNIFFED_AS[$sniffed] ?? null;

        return $family !== null && preg_match($family, $declared) === 1;
    }

    public function isMultipart(int $size): bool
    {
        return $size > $this->multipartThreshold;
    }

    public function partCount(int $size): int
    {
        return (int) ceil($size / $this->partSize);
    }
}
