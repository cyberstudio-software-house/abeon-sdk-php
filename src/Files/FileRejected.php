<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use RuntimeException;

/**
 * An upload or a change the module refuses. `reason` is a stable code an application maps to its
 * own response (a 422 for a type, a 413 for a size, a 409 for a state).
 */
final class FileRejected extends RuntimeException
{
    public const TYPE_NOT_ALLOWED = 'type_not_allowed';

    public const TOO_LARGE = 'too_large';

    public const QUOTA_EXCEEDED = 'quota_exceeded';

    public const NOT_PENDING = 'not_pending';

    public const MISSING_OBJECT = 'missing_object';

    public const SIZE_MISMATCH = 'size_mismatch';

    public const CONTENT_MISMATCH = 'content_mismatch';

    public const NOT_MULTIPART = 'not_multipart';

    public const TOO_MANY_PIXELS = 'too_many_pixels';

    public const PROCESSING_FAILED = 'processing_failed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
