<?php

declare(strict_types=1);

namespace Abeon\SDK\Storage;

use DateTimeImmutable;

/**
 * An address AbeonUnified signed for one object and one operation, valid for minutes.
 *
 * `headers` are part of the signature, not advice: a `PUT` whose `Content-Type` differs
 * from the one signed is refused by the storage, which is what stops a signature for an
 * image being spent on something else.
 */
final class SignedUrl
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly DateTimeImmutable $expiresAt,
        public readonly array $headers = [],
    ) {
    }
}
