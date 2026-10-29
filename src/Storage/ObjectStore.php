<?php

declare(strict_types=1);

namespace Abeon\SDK\Storage;

use Abeon\SDK\Client\ServiceCallException;
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

    /**
     * **The type is required.** ADR-0034 §4 signs it into the address, and AbeonUnified refuses
     * a `put` without one — a signature obtained with no type is a general-purpose write handle
     * for that key, so an address issued for an avatar could be spent on HTML that the
     * container then serves to anybody under `public/`. It was optional here long enough to
     * promise a call the platform would answer with a 422.
     */
    public function uploadUrl(ObjectPath $path, string $contentType): SignedUrl
    {
        if (trim($contentType) === '') {
            throw new \InvalidArgumentException('An upload address is signed over its content type; give one.');
        }

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

    /**
     * What the storage holds at this key, or null when nothing is there. How an upload is
     * checked before it is believed: the browser said it sent the bytes, this says whether it did.
     *
     * @return array{size: int, etag: string, content_type: string, last_modified: string}|null
     */
    public function head(ObjectPath $path): ?array
    {
        try {
            $response = $this->client->service('unified')
                ->post('/api/v1/internal/storage/head', ['key' => $path->key()]);
        } catch (ServiceCallException $e) {
            if ($e->problem->status === 404) {
                return null;
            }

            throw $e;
        }

        /** @var array{size: int, etag: string, content_type: string, last_modified: string} $object */
        $object = $response->json('data');

        return $object;
    }

    /**
     * One page of this service's objects under `$prefix` (its own `{service}/` by default).
     *
     * @return array{objects: list<array{key: string, size: int, etag: string, last_modified: string}>, next_cursor: string|null}
     */
    public function list(?string $prefix = null, ?string $cursor = null, int $limit = 1000): array
    {
        /** @var array{objects: list<array{key: string, size: int, etag: string, last_modified: string}>, next_cursor: string|null} $page */
        $page = $this->client->service('unified')->post('/api/v1/internal/storage/list', array_filter([
            'prefix' => $prefix,
            'cursor' => $cursor,
            'limit'  => $limit,
        ], static fn (mixed $value): bool => $value !== null))->json('data');

        return $page;
    }

    /**
     * Bytes the organisation stores across every application, and how many it may.
     *
     * @return array{used_bytes: int, quota_bytes: int}
     */
    public function usage(): array
    {
        /** @var array{used_bytes: int, quota_bytes: int} $usage */
        $usage = $this->client->service('unified')->get('/api/v1/internal/storage/usage')->json('data');

        return $usage;
    }

    public function beginMultipart(ObjectPath $path, string $contentType): string
    {
        return (string) $this->client->service('unified')->post('/api/v1/internal/storage/multipart/create', [
            'key'          => $path->key(),
            'content_type' => $contentType,
        ])->json('data.upload_id');
    }

    public function partUrl(ObjectPath $path, string $uploadId, int $partNumber): SignedUrl
    {
        $signed = $this->client->service('unified')->post('/api/v1/internal/storage/multipart/part', [
            'key'         => $path->key(),
            'upload_id'   => $uploadId,
            'part_number' => $partNumber,
        ])->json('data');

        return $this->signedUrlFrom($signed);
    }

    /**
     * @param  list<array{part_number: int, etag: string}>  $parts
     */
    public function completeMultipart(ObjectPath $path, string $uploadId, array $parts): void
    {
        $this->client->service('unified')->post('/api/v1/internal/storage/multipart/complete', [
            'key'       => $path->key(),
            'upload_id' => $uploadId,
            'parts'     => $parts,
        ]);
    }

    public function abortMultipart(ObjectPath $path, string $uploadId): void
    {
        $this->client->service('unified')->post('/api/v1/internal/storage/multipart/abort', [
            'key'       => $path->key(),
            'upload_id' => $uploadId,
        ]);
    }

    private function sign(ObjectPath $path, string $operation, ?string $contentType = null): SignedUrl
    {
        $response = $this->client->service('unified')->post('/api/v1/internal/storage/sign', array_filter([
            'key'          => $path->key(),
            'operation'    => $operation,
            'content_type' => $contentType,
        ], static fn (mixed $value): bool => $value !== null));

        return $this->signedUrlFrom($response->json('data'));
    }

    private function signedUrlFrom(mixed $signed): SignedUrl
    {
        /** @var array{url: string, expires_at: string, headers?: array<string, string>} $signed */
        return new SignedUrl(
            $signed['url'],
            new DateTimeImmutable($signed['expires_at']),
            $signed['headers'] ?? [],
        );
    }
}
