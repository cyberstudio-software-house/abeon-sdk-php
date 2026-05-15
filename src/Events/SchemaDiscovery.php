<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reads `vendor/composer/installed.json` and aggregates event schemas from
 * any installed package that declares `extra.abeon.event-schemas`
 * (a directory path relative to the package root).
 *
 * Each *.json file in the declared directory is registered under its base
 * filename (e.g. `crm.contact.created.json` → routing key `crm.contact.created`).
 *
 * Federation contract (M2/M3): SDK ships only generic schemas; domain
 * event schemas live in each owning service's repo.
 *
 * MD-2 (code review): path traversal hardened — every resolved path must
 * stay inside its declaring package directory after realpath().
 *
 * MD-8 (code review): error-suppressed file reads removed; failures are
 * reported via the injected PSR-3 logger (NullLogger by default).
 */
class SchemaDiscovery
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $vendorPath,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @return array<string, array<string, mixed>> routing_key => decoded schema
     */
    public function discover(): array
    {
        $installedJson = $this->vendorPath.'/composer/installed.json';
        if (! is_file($installedJson)) {
            $this->logger->info('abeon.schema_discovery.no_installed_json', ['path' => $installedJson]);
            return [];
        }

        $raw = file_get_contents($installedJson);
        if ($raw === false) {
            $this->logger->warning('abeon.schema_discovery.installed_json_unreadable', ['path' => $installedJson]);
            return [];
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            $this->logger->warning('abeon.schema_discovery.installed_json_invalid');
            return [];
        }

        $packages = $manifest['packages'] ?? $manifest;
        if (! is_array($packages)) {
            return [];
        }

        $schemas = [];
        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }
            $relative = $package['extra']['abeon']['event-schemas'] ?? null;
            if (! is_string($relative) || $relative === '' || ! isset($package['name'])) {
                continue;
            }

            $packageDir = $this->safeRealpath($this->vendorPath.'/'.$package['name']);
            if ($packageDir === null) {
                continue;
            }

            $dir = $this->safeRealpath($packageDir.'/'.trim($relative, '/'));
            if ($dir === null) {
                continue;
            }

            // MD-2: path-traversal guard — resolved dir MUST live inside the
            // declaring package's own realpath. Symlinks resolve here too.
            if (! str_starts_with($dir.DIRECTORY_SEPARATOR, $packageDir.DIRECTORY_SEPARATOR)) {
                $this->logger->warning('abeon.schema_discovery.path_escape', [
                    'package'  => $package['name'],
                    'declared' => $relative,
                    'resolved' => $dir,
                ]);
                continue;
            }

            $this->loadSchemasFromDir($dir, (string) $package['name'], $schemas);
        }

        return $schemas;
    }

    /**
     * @param  array<string, array<string, mixed>>  $schemas
     */
    private function loadSchemasFromDir(string $dir, string $packageName, array &$schemas): void
    {
        $files = glob($dir.'/*.json');
        if ($files === false) {
            $this->logger->warning('abeon.schema_discovery.glob_failed', [
                'package' => $packageName,
                'dir'     => $dir,
            ]);
            return;
        }

        foreach ($files as $file) {
            $routingKey = basename($file, '.json');
            if (! RoutingKey::isValid($routingKey)) {
                $this->logger->info('abeon.schema_discovery.invalid_routing_key', [
                    'package' => $packageName,
                    'file'    => $file,
                ]);
                continue;
            }

            $contents = file_get_contents($file);
            if ($contents === false) {
                $this->logger->warning('abeon.schema_discovery.file_unreadable', [
                    'package' => $packageName,
                    'file'    => $file,
                ]);
                continue;
            }

            $schema = json_decode($contents, true);
            if (! is_array($schema)) {
                $this->logger->warning('abeon.schema_discovery.schema_not_json_object', [
                    'package' => $packageName,
                    'file'    => $file,
                ]);
                continue;
            }

            $schemas[$routingKey] = $schema;
        }
    }

    private function safeRealpath(string $path): ?string
    {
        $resolved = realpath($path);
        return $resolved === false ? null : $resolved;
    }
}
