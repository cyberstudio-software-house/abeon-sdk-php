<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

/**
 * A file finished processing: its original and variants are where `StoredFile` says. Raised
 * inside the application; nothing leaves it unless the application publishes an event of its own.
 */
final class FileReady
{
    public function __construct(public readonly StoredFile $file)
    {
    }
}
