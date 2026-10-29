<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Abeon\SDK\Storage\ObjectPath;
use Abeon\SDK\Storage\ObjectStore;
use Abeon\SDK\Storage\SignedUrl;
use Illuminate\Support\Carbon;

/**
 * A file once it exists: where to read it, and the trash. A trashed file keeps its objects for
 * `abeon.files.trash_days` and can be restored; `purge` removes the objects and the record at
 * once, which is also the path for an erasure request (Files PRD §11a).
 */
final class Files
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly FilePolicy $policy,
    ) {
    }

    public function find(string $uuid): ?StoredFile
    {
        return StoredFile::query()->where('uuid', $uuid)->first();
    }

    public function publicUrl(StoredFile $file): ?string
    {
        $path = $file->path();

        return $path !== null && $path->isPublic() && $file->status === StoredFile::STATUS_READY
            ? $this->store->publicUrl($path)
            : null;
    }

    public function readUrl(StoredFile $file): SignedUrl
    {
        $path = $file->path();

        if ($path === null || $path->isPublic()) {
            throw new \LogicException("File {$file->uuid} has no private object to sign.");
        }

        return $this->store->readUrl($path);
    }

    public function trash(StoredFile $file): void
    {
        $file->delete();
    }

    public function restore(StoredFile $file): void
    {
        $file->restore();
    }

    public function purge(StoredFile $file): void
    {
        foreach ($file->variants()->pluck('object_key') as $key) {
            $this->store->delete(ObjectPath::fromKey((string) $key));
        }

        $path = $file->path();
        if ($path !== null) {
            $this->store->delete($path);
        }

        $file->forceDelete();
    }

    public function purgeExpiredTrash(): int
    {
        $count = 0;

        $expired = StoredFile::onlyTrashed()
            ->where('deleted_at', '<', Carbon::now()->subDays($this->policy->trashDays))
            ->lazyById();

        foreach ($expired as $file) {
            $this->purge($file);
            $count++;
        }

        return $count;
    }

    /**
     * Uploads begun and never completed, and uploads that failed. The bytes, if any arrived,
     * sit under a private staging key, so removing them loses nothing anybody could reach.
     */
    public function prunePending(string $service): int
    {
        $count = 0;

        /** @var \Illuminate\Support\LazyCollection<int, StoredFile> $abandoned */
        $abandoned = StoredFile::query()
            ->whereIn('status', [StoredFile::STATUS_PENDING, StoredFile::STATUS_FAILED])
            ->where('created_at', '<', Carbon::now()->subHours($this->policy->pendingHours))
            ->lazyById();

        foreach ($abandoned as $file) {
            $staging = $file->stagingPath($service);

            if ($file->upload_id !== null) {
                $this->store->abortMultipart($staging, $file->upload_id);
            }

            $this->store->delete($staging);
            $file->forceDelete();
            $count++;
        }

        return $count;
    }
}
