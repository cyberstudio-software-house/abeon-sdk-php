<?php

declare(strict_types=1);

namespace Abeon\SDK\Theme;

/**
 * The accent palette of the chrome (ADR-0009 amendment, 2026-11-16). An organisation picks one
 * for everybody, a user may override it for themselves; only these names are accepted, because
 * each one is a tested set of tokens in `@abeon/ui`, never a free colour.
 */
final class Accent
{
    public const DEFAULT = 'cyberstudio';

    /** @var list<string> */
    public const NAMES = ['cyberstudio', 'indigo', 'blue', 'emerald', 'violet', 'rose', 'orange'];

    public static function isKnown(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::NAMES, true);
    }
}
