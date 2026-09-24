<?php

declare(strict_types=1);

namespace Abeon\SDK\Storage;

use InvalidArgumentException;

/**
 * Where one file lives inside the organisation's container (ADR-0021 as amended by
 * ADR-0034): `{service}/{visibility}/{path}`.
 *
 * The prefix is the access boundary — the container policy grants anonymous reads under
 * any `public/` prefix and nowhere else, and AbeonUnified signs only inside the caller's own
 * `{service}/`. That boundary is only worth the regex that keeps a path from walking out
 * of it, so the check lives in a value object that cannot be bypassed by forgetting to
 * call it: every segment must start with an alphanumeric, which leaves `..`, an absolute
 * path and an empty segment unrepresentable rather than merely unwelcome.
 *
 * Checked here **and** again in AbeonUnified. The one that matters is Unified's — this
 * one only turns a mistake into an exception at the line that made it.
 */
final class ObjectPath
{
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_PRIVATE = 'private';

    private const KEY_PATTERN = '#^[a-z][a-z0-9_-]*/(public|private)/[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$#';

    private function __construct(
        public readonly string $service,
        public readonly string $visibility,
        public readonly string $path,
    ) {
    }

    public static function publicPath(string $service, string $path): self
    {
        return self::make($service, self::VISIBILITY_PUBLIC, $path);
    }

    public static function privatePath(string $service, string $path): self
    {
        return self::make($service, self::VISIBILITY_PRIVATE, $path);
    }

    public static function fromKey(string $key): self
    {
        $segments = explode('/', $key, 3);

        if (count($segments) !== 3) {
            throw new InvalidArgumentException("Not an object key: {$key}");
        }

        return self::make($segments[0], $segments[1], $segments[2]);
    }

    public function key(): string
    {
        return "{$this->service}/{$this->visibility}/{$this->path}";
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    private static function make(string $service, string $visibility, string $path): self
    {
        $key = "{$service}/{$visibility}/{$path}";

        if (strlen($key) > 1024 || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Refused object key: {$key}");
        }

        return new self($service, $visibility, $path);
    }
}
