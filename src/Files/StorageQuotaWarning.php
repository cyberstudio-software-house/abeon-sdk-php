<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

/**
 * The organisation has used 80% of its storage (Files PRD §5.1). Raised at most once a day by
 * `Uploads::begin()`; the application decides who hears about it.
 */
final class StorageQuotaWarning
{
    public const THRESHOLD = 0.8;

    public function __construct(
        public readonly int $usedBytes,
        public readonly int $quotaBytes,
    ) {
    }
}
