<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Abeon\SDK\Storage\SignedUrl;

/**
 * What the browser needs to send the bytes: one signed address, or a multipart upload to ask
 * part addresses for. `duplicateOf` names a ready file with the same SHA-256, when the caller
 * sent one, so the interface can say "this file is already here".
 */
final readonly class UploadTicket
{
    public function __construct(
        public StoredFile $file,
        public ?SignedUrl $upload,
        public ?int $partSize,
        public ?int $partCount,
        public ?string $duplicateOf,
    ) {
    }

    public function isMultipart(): bool
    {
        return $this->partSize !== null;
    }
}
