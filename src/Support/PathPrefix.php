<?php

declare(strict_types=1);

namespace Abeon\SDK\Support;

use Abeon\SDK\Config\AbeonConfig;

/**
 * Build absolute paths from app-relative ones using this service's
 * configured prefix (`abeon.app_descriptor.path`).
 *
 * Use case: event payloads carrying `links.self`, notification action URLs,
 * cross-app references in emails — anywhere a consumer might receive a path
 * without knowing where it should be mounted under Traefik.
 */
class PathPrefix
{
    public function __construct(private readonly AbeonConfig $config)
    {
    }

    /**
     * Prepend this service's path prefix to a relative path.
     *
     * - `/contacts/42` + prefix `/crm` → `/crm/contacts/42`
     * - already-prefixed paths are returned unchanged
     * - absolute URLs (`https://…`) pass through
     * - no prefix configured → input returned as-is
     */
    public function absolute(string $relative): string
    {
        if ($relative === '') {
            return $this->prefix();
        }

        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        $prefix = $this->prefix();
        if ($prefix === '') {
            return $relative;
        }

        if (str_starts_with($relative, $prefix.'/') || $relative === $prefix) {
            return $relative;
        }

        return $prefix.'/'.ltrim($relative, '/');
    }

    /**
     * The configured prefix without trailing slash. Empty string when
     * the service has not declared `app_descriptor.path`.
     */
    public function prefix(): string
    {
        $descriptor = $this->config->appDescriptor();
        $path       = $descriptor['path'] ?? null;

        if (! is_string($path) || $path === '' || $path === '/') {
            return '';
        }

        return '/'.trim($path, '/');
    }
}
