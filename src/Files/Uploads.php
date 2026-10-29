<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Abeon\SDK\Client\ServiceCallException;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Files\Processing\ProcessFile;
use Abeon\SDK\Storage\ObjectPath;
use Abeon\SDK\Storage\ObjectStore;
use Abeon\SDK\Storage\SignedUrl;
use finfo;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Two-step upload (Files PRD §5.1). `begin` records the file as pending and hands out an address
 * under a private staging key; the browser sends the bytes straight to the storage; `complete`
 * checks what actually arrived — that it exists, its size, and its type judged by its content
 * rather than by what the browser claimed — before anything treats it as a file.
 */
final class Uploads
{
    private const SNIFF_BYTES = 65536;

    public function __construct(
        private readonly ObjectStore $store,
        private readonly AbeonConfig $config,
        private readonly FilePolicy $policy,
        private readonly HttpFactory $http,
    ) {
    }

    public function begin(
        string $originalName,
        string $type,
        int $size,
        string $visibility = ObjectPath::VISIBILITY_PUBLIC,
        ?int $folderId = null,
        ?int $uploadedBy = null,
        ?string $sha256 = null,
    ): UploadTicket {
        $type = FilePolicy::normalise($type);
        $this->policy->assertAccepts($type, $size);
        $this->assertWithinQuota($size);

        if (! in_array($visibility, [ObjectPath::VISIBILITY_PUBLIC, ObjectPath::VISIBILITY_PRIVATE], true)) {
            throw new \InvalidArgumentException("Unknown visibility: {$visibility}");
        }

        $sha256 = $sha256 !== null && preg_match('/^[0-9a-f]{64}$/', strtolower($sha256)) === 1 ? strtolower($sha256) : null;
        $displayName = Str::limit(trim($originalName) !== '' ? trim($originalName) : 'file', 255, '');

        $file = StoredFile::query()->create([
            'uuid'          => (string) Str::uuid7(),
            'visibility'    => $visibility,
            'original_name' => $displayName,
            'slug'          => self::slug($displayName),
            'display_name'  => $displayName,
            'mime'          => $type,
            'size'          => $size,
            'sha256'        => $sha256,
            'folder_id'     => $folderId,
            'status'        => StoredFile::STATUS_PENDING,
            'uploaded_by'   => $uploadedBy,
        ]);

        $staging = $file->stagingPath($this->config->serviceName());
        $file->object_key = $staging->key();

        $duplicateOf = $sha256 === null ? null : StoredFile::query()
            ->where('sha256', $sha256)
            ->where('status', StoredFile::STATUS_READY)
            ->value('uuid');

        if ($this->policy->isMultipart($size)) {
            $file->upload_id = $this->store->beginMultipart($staging, $type);
            $file->save();

            return new UploadTicket($file, null, $this->policy->partSize, $this->policy->partCount($size), $duplicateOf);
        }

        $file->save();

        return new UploadTicket($file, $this->store->uploadUrl($staging, $type), null, null, $duplicateOf);
    }

    public function partUrl(StoredFile $file, int $partNumber): SignedUrl
    {
        $this->assertPending($file);

        if ($file->upload_id === null) {
            throw new FileRejected(FileRejected::NOT_MULTIPART, 'This upload is a single request, not parts.');
        }

        if ($partNumber < 1 || $partNumber > $this->policy->partCount($file->size)) {
            throw new \InvalidArgumentException("Part {$partNumber} is outside this upload.");
        }

        return $this->store->partUrl($file->stagingPath($this->config->serviceName()), $file->upload_id, $partNumber);
    }

    /**
     * @param  list<array{part_number: int, etag: string}>  $parts  for a multipart upload
     */
    public function complete(StoredFile $file, array $parts = []): StoredFile
    {
        $this->assertPending($file);

        // Claimed before anything is checked: two `complete` calls racing would otherwise both
        // pass the check above and start two jobs on one upload.
        $claimed = StoredFile::query()->whereKey($file->id)->where('status', StoredFile::STATUS_PENDING)
            ->update(['status' => StoredFile::STATUS_PROCESSING]);

        if ($claimed === 0) {
            throw new FileRejected(FileRejected::NOT_PENDING, "File {$file->uuid} is already being completed.");
        }

        $file->status = StoredFile::STATUS_PROCESSING;
        $staging = $file->stagingPath($this->config->serviceName());

        if ($file->upload_id !== null) {
            try {
                $this->store->completeMultipart($staging, $file->upload_id, $parts);
            } catch (ServiceCallException) {
                $this->fail($file, FileRejected::MISSING_OBJECT, 'The parts could not be assembled.');
            }
        }

        $object = $this->store->head($staging);

        if ($object === null) {
            $this->fail($file, FileRejected::MISSING_OBJECT, 'Nothing arrived at the upload address.');
        }

        if ($object['size'] !== $file->size) {
            $this->fail($file, FileRejected::SIZE_MISMATCH, "Expected {$file->size} bytes, {$object['size']} arrived.");
        }

        $sniffed = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($this->firstBytes($staging));

        if (! $this->policy->contentMatches($file->mime, $sniffed)) {
            $this->fail($file, FileRejected::CONTENT_MISMATCH, "Declared '{$file->mime}', but the content is '{$sniffed}'.");
        }

        $file->forceFill(['status' => StoredFile::STATUS_PROCESSING, 'upload_id' => null])->save();
        Bus::dispatch(new ProcessFile($file->id, expectedEtag: $object['etag']));

        return $file;
    }

    public static function slug(string $name): string
    {
        $extension = Str::lower(Str::ascii(pathinfo($name, PATHINFO_EXTENSION)));
        $base = Str::slug(Str::ascii(pathinfo($name, PATHINFO_FILENAME)));
        $base = Str::limit($base !== '' ? $base : 'file', 100, '');
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?? '';

        return $extension === '' ? $base : $base.'.'.Str::limit($extension, 10, '');
    }

    private function firstBytes(ObjectPath $path): string
    {
        $signed = $this->store->readUrl($path);

        return $this->http->withHeaders(['Range' => 'bytes=0-'.(self::SNIFF_BYTES - 1)])
            ->timeout(30)
            ->get($signed->url)
            ->throw()
            ->body();
    }

    private function fail(StoredFile $file, string $reason, string $message): never
    {
        $file->forceFill(['status' => StoredFile::STATUS_FAILED, 'failure_reason' => $reason, 'upload_id' => null])->save();
        $this->store->delete($file->stagingPath($this->config->serviceName()));

        throw new FileRejected($reason, $message);
    }

    private function assertPending(StoredFile $file): void
    {
        if ($file->status !== StoredFile::STATUS_PENDING) {
            throw new FileRejected(FileRejected::NOT_PENDING, "File {$file->uuid} is {$file->status}, not pending.");
        }
    }

    private function assertWithinQuota(int $size): void
    {
        $usage = $this->store->usage();
        $inFlight = (int) StoredFile::query()
            ->whereIn('status', [StoredFile::STATUS_PENDING, StoredFile::STATUS_PROCESSING])
            ->sum('size');

        if ($usage['used_bytes'] + $inFlight + $size > $usage['quota_bytes']) {
            throw new FileRejected(FileRejected::QUOTA_EXCEEDED, 'The organisation has used its storage quota.');
        }

        $after = $usage['used_bytes'] + $inFlight + $size;

        if ($after >= StorageQuotaWarning::THRESHOLD * $usage['quota_bytes'] && Cache::add('abeon-files:quota-warning', true, 86400)) {
            Event::dispatch(new StorageQuotaWarning($after, $usage['quota_bytes']));
        }
    }
}
