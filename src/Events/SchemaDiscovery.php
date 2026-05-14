<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

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
 */
class SchemaDiscovery
{
    public function __construct(private readonly string $vendorPath)
    {
    }

    /**
     * @return array<string, array<string, mixed>> routing_key => decoded schema
     */
    public function discover(): array
    {
        $installedJson = $this->vendorPath.'/composer/installed.json';
        if (! is_file($installedJson)) {
            return [];
        }

        $raw = @file_get_contents($installedJson);
        if ($raw === false) {
            return [];
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
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

            $dir = $this->vendorPath.'/'.$package['name'].'/'.trim($relative, '/');
            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*.json') ?: [] as $file) {
                $routingKey = basename($file, '.json');
                if (! RoutingKey::isValid($routingKey)) {
                    continue;
                }
                $contents = @file_get_contents($file);
                if ($contents === false) {
                    continue;
                }
                $schema = json_decode($contents, true);
                if (is_array($schema)) {
                    $schemas[$routingKey] = $schema;
                }
            }
        }

        return $schemas;
    }
}
