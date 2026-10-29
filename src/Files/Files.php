<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Abeon\SDK\Files\Processing\ProcessFile;
use Abeon\SDK\Storage\ObjectPath;
use Abeon\SDK\Storage\ObjectStore;
use Abeon\SDK\Storage\SignedUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

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

    /**
     * Where the subject of the picture is, as fractions of its width and height. Cropped variants
     * are made again around it.
     */
    public function setFocalPoint(StoredFile $file, float $x, float $y): void
    {
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new \InvalidArgumentException('A focal point is two fractions between 0 and 1.');
        }

        $file->forceFill(['focal_x' => round($x, 4), 'focal_y' => round($y, 4)])->save();

        if ($file->status === StoredFile::STATUS_READY) {
            Bus::dispatch(new ProcessFile($file->id, variantsOnly: true));
        }
    }

    /**
     * A file something still uses stays where it is; the refusal carries the list for
     * "where used" (Files PRD §6).
     */
    public function trash(StoredFile $file): void
    {
        $usages = $file->usages()->orderBy('entity_type')->orderBy('entity_id')
            ->get(['entity_type', 'entity_id', 'label', 'admin_url'])
            ->map(static fn ($usage): array => [
                'entity_type' => (string) $usage->entity_type,
                'entity_id'   => (string) $usage->entity_id,
                'label'       => $usage->label === null ? null : (string) $usage->label,
                'admin_url'   => $usage->admin_url === null ? null : (string) $usage->admin_url,
            ])->values()->all();

        if ($usages !== []) {
            throw new FileRejected(FileRejected::IN_USE, "File {$file->uuid} is used in ".count($usages).' places.', $usages);
        }

        $file->delete();
    }

    public function restore(StoredFile $file): void
    {
        $file->restore();
    }

    /**
     * Objects and record gone at once, usages included — the erasure path, which a file in use
     * does not stop (Files PRD §11a). The trash empties through here too, where nothing uses a file.
     */
    public function purge(StoredFile $file): void
    {
        foreach ($file->variants()->pluck('object_key') as $key) {
            $this->store->delete(ObjectPath::fromKey((string) $key));
        }

        $path = $file->path();
        if ($path !== null) {
            $this->store->delete($path);
        }

        $file->usages()->delete();
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
     * What the records say against what the storage holds (Files PRD §5.1). A record whose object
     * is missing is reported; an object no record knows is reported and, once older than
     * `$orphanDays`, deleted — the age keeps an upload that is still arriving out of it.
     *
     * ponytail: keeps every known key in memory, fine to a few hundred thousand files; page the
     * comparison by key prefix when an application holds more.
     *
     * @return array{missing: list<string>, orphans: list<string>, deleted: int}
     */
    public function reconcile(string $service, int $orphanDays = 7): array
    {
        $known = array_fill_keys(StoredFile::withTrashed()->whereNotNull('object_key')->pluck('object_key')->all(), true)
            + array_fill_keys(FileVariant::query()->pluck('object_key')->all(), true);

        $stored = [];
        $orphans = [];
        $deleted = 0;
        $cutoff = Carbon::now()->subDays($orphanDays);
        $cursor = null;

        do {
            $page = $this->store->list("{$service}/", $cursor);

            foreach ($page['objects'] as $object) {
                $stored[$object['key']] = true;

                if (isset($known[$object['key']])) {
                    continue;
                }

                $orphans[] = $object['key'];

                if (Carbon::parse($object['last_modified'])->lt($cutoff)) {
                    $this->store->delete(ObjectPath::fromKey($object['key']));
                    $deleted++;
                }
            }

            $cursor = $page['next_cursor'];
        } while ($cursor !== null);

        $expected = StoredFile::query()->where('status', StoredFile::STATUS_READY)->pluck('object_key')
            ->merge(FileVariant::query()->pluck('object_key'))
            ->filter()
            ->map(static fn (mixed $key): string => (string) $key);

        return [
            'missing' => $expected->reject(static fn (string $key): bool => isset($stored[$key]))->values()->all(),
            'orphans' => $orphans,
            'deleted' => $deleted,
        ];
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
