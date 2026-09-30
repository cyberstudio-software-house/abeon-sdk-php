<?php

declare(strict_types=1);

namespace Abeon\SDK\Support;

/**
 * UUIDv4 generation without external dependency.
 *
 * Consolidates what was previously duplicated across CorrelationContext,
 * EnvelopeBuilder, and ServiceTokenProvider.
 */
final class Uuid
{
    /** Strict UUIDv4 format with version + variant bits set. */
    public const REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Generate a UUID v4 string using `random_bytes(16)` (cryptographically secure).
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Any UUID shape, whatever version and variant nibbles it carries.
     *
     * For values this platform did not mint: an upstream gateway's correlation id, or a v7 from
     * a service that moved to time-ordered ids. `v4()` above is what this platform mints, and
     * `REGEX` still pins that shape for anything asserting on our own ids — but a *received*
     * value is only ever checked for shape, because replacing somebody else's id breaks the one
     * chain ADR-0003 exists to keep.
     */
    public static function isWellFormed(string $value): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $value);
    }
}
