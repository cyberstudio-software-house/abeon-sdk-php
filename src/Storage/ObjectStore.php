<?php

declare(strict_types=1);

namespace Abeon\SDK\Storage;

use Abeon\SDK\Client\ServiceClient;
use Abeon\SDK\Config\AbeonConfig;
use DateTimeImmutable;

/**
 * Files, for an application that holds no storage credentials (ADR-0034).
 *
 * The application asks AbeonUnified for an address for one object and one operation and
 * then moves the bytes itself — usually from the browser, straight to the storage. The
 * keys stay with Unified, which is stronger than ADR-0021's "Unified issues credentials"
 * and removes the question of what a leaked application credential could reach.
 *
 * There is no storage driver here, on purpose: this component is HTTP to Unified and a
 * prefix check, so the core SDK gains no dependency on how the bytes are actually stored
 * (ADR-0021's package split, now with nothing left to put in the second package).
 */
final class ObjectStore
{
    public function __construct(
        private readonly ServiceClient $client,
        private readonly AbeonConfig $config,
    ) {
    }

    public function publicPath(string $path): ObjectPath
    {
        return ObjectPath::publicPath($this->config->serviceName(), $path);
    }

    public function privatePath(string $path): ObjectPath
    {
        return ObjectPath::privatePath($this->config->serviceName(), $path);
    }

    public function uploadUrl(ObjectPath $path, ?string $contentType = null): SignedUrl
    {
        return $this->sign($path, 'put', $contentType);
    }

    /**
     * A short-lived address for a private object. Public ones are not signed at all —
     * see `publicUrl()`.
     */
    public function readUrl(ObjectPath $path): SignedUrl
    {
        if ($path->isPublic()) {
            throw new \LogicException("A public object has a stable address; use publicUrl(): {$path->key()}");
        }

        return $this->sign($path, 'get');
    }

    /**
     * The stable, cacheable address of a public object.
     *
     * Derived, not fetched: it is the same string for the life of the object, so asking
     * Unified for it would make a page of images depend on Unified being up. The base is
     * this instance's container — every deployment of a business application serves one
     * organisation (ADR-0031), so there is one container to point at and the provisioner
     * sets it beside `ABEON_ORG_ID`.
     */
    public function publicUrl(ObjectPath $path): string
    {
        if (! $path->isPublic()) {
            throw new \LogicException("A private object has no public address: {$path->key()}");
        }

        return rtrim($this->config->storagePublicBaseUrl(), '/').'/'.$path->key();
    }

    public function delete(ObjectPath $path): void
    {
        $this->client->service('unified')
            ->post('/api/v1/internal/storage/delete', ['key' => $path->key()]);
    }

    private function sign(ObjectPath $path, string $operation, ?string $contentType = null): SignedUrl
    {
        $response = $this->client->service('unified')->post('/api/v1/internal/storage/sign', array_filter([
            'key'          => $path->key(),
            'operation'    => $operation,
            'content_type' => $contentType,
        ], static fn (mixed $value): bool => $value !== null));

        /** @var array{url: string, expires_at: string, headers?: array<string, string>} $signed */
        $signed = $response->json('data');

        return new SignedUrl(
            $signed['url'],
            new DateTimeImmutable($signed['expires_at']),
            $signed['headers'] ?? [],
        );
    }
}
