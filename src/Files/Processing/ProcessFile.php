<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Processing;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Files\FilePolicy;
use Abeon\SDK\Files\FileReady;
use Abeon\SDK\Files\FileRejected;
use Abeon\SDK\Files\FileVariant;
use Abeon\SDK\Files\StoredFile;
use Abeon\SDK\Storage\ObjectPath;
use Abeon\SDK\Storage\ObjectStore;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Turns an upload into a file (Files PRD §5.1): reads the staged bytes, cleans the original,
 * makes the variants, puts everything at its final key and marks the file ready. With
 * `variantsOnly` it remakes the variants from the final original, after the focal point moved.
 *
 * The bytes pass through the application here and only here (Files PRD §3, rule 1).
 */
final class ProcessFile implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** Seconds: a 100 MB download and the parsers' own limits fit, a stuck job does not. */
    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  string|null  $expectedEtag  what `complete` saw at the staging key; the bytes processed must be those
     */
    public function __construct(
        public readonly int $fileId,
        public readonly bool $variantsOnly = false,
        public readonly ?string $expectedEtag = null,
    ) {
        $this->onQueue((string) config('abeon.files.queue', 'files'));
    }

    public function handle(ObjectStore $store, AbeonConfig $config, HttpFactory $http, ImageProcessor $images, PdfProcessor $pdfs, FilePolicy $policy): void
    {
        $file = StoredFile::withTrashed()->find($this->fileId);
        $expected = $this->variantsOnly ? StoredFile::STATUS_READY : StoredFile::STATUS_PROCESSING;

        if ($file === null || $file->status !== $expected) {
            return;
        }

        $service = $config->serviceName();
        $source = $this->variantsOnly ? $file->path() : $file->stagingPath($service);

        if ($source === null) {
            return;
        }

        $workDir = sys_get_temp_dir().'/abeon-file-'.bin2hex(random_bytes(8));
        mkdir($workDir, 0700);

        try {
            $input = "{$workDir}/source";

            if (! $this->variantsOnly && ! $this->unchanged($store, $source, $file)) {
                $file->forceFill(['status' => StoredFile::STATUS_FAILED, 'failure_reason' => FileRejected::CONTENT_MISMATCH])->save();

                return;
            }

            $http->timeout(300)->sink($input)->get($this->readUrl($store, $source))->throw();

            if (! $this->variantsOnly && ! $this->stillMatches($policy, $file, $input)) {
                $file->forceFill(['status' => StoredFile::STATUS_FAILED, 'failure_reason' => FileRejected::CONTENT_MISMATCH])->save();

                return;
            }

            /** @var array<string, array{width: int, height?: int|null, fit?: string}> $presets */
            $presets = (array) config('abeon.files.presets', []);
            $focalX = $file->focal_x ?? 0.5;
            $focalY = $file->focal_y ?? 0.5;

            $original = null;
            $pages = null;

            try {
                if (ImageProcessor::handles($file->mime)) {
                    $result = $images->process($workDir, $input, $file->mime, $presets, $focalX, $focalY, ! $this->variantsOnly);
                    $original = $result['original'];
                    $variants = $result['variants'];
                } elseif ($file->mime === 'application/pdf') {
                    $result = $pdfs->process($workDir, $input, $presets);
                    $pages = $result['pages'];
                    $variants = $result['variants'];
                } else {
                    $variants = [];
                }
            } catch (FileRejected $e) {
                $file->forceFill(['status' => StoredFile::STATUS_FAILED, 'failure_reason' => $e->reason])->save();

                return;
            } catch (ToolFailed) {
                if (! $this->variantsOnly) {
                    $file->forceFill(['status' => StoredFile::STATUS_FAILED, 'failure_reason' => FileRejected::PROCESSING_FAILED])->save();
                }

                return;
            }

            $visibility = $file->visibility;
            $final = $file->path();

            if (! $this->variantsOnly) {
                $final = ObjectPath::fromKey("{$service}/{$visibility}/files/{$file->uuid}/{$file->slug}");
                $this->put($store, $http, $final, $original?->path ?? $input, $file->mime);
            }

            $stored = [];
            foreach ($variants as $variant) {
                $key = ObjectPath::fromKey("{$service}/{$visibility}/thumbnails/{$file->uuid}/".basename($variant->path));
                $this->put($store, $http, $key, $variant->path, $variant->mime);
                $stored[] = [
                    'preset'     => (string) $variant->preset,
                    'object_key' => $key->key(),
                    'mime'       => $variant->mime,
                    'width'      => $variant->width,
                    'height'     => $variant->height,
                    'size'       => (int) filesize($variant->path),
                ];
            }

            $stale = DB::transaction(function () use ($file, $stored, $final, $original, $pages, $input): array {
                $keep = array_column($stored, 'object_key');
                $stale = $file->variants()->whereNotIn('object_key', $keep)->pluck('object_key')->all();

                $file->variants()->delete();
                foreach ($stored as $row) {
                    FileVariant::query()->create(['file_id' => $file->id] + $row);
                }

                if (! $this->variantsOnly) {
                    $file->forceFill([
                        'object_key' => $final?->key(),
                        'sha256'     => hash_file('sha256', $input),
                        'size'       => (int) filesize($original?->path ?? $input),
                        'width'      => $original?->width,
                        'height'     => $original?->height,
                        'pages'      => $pages,
                        'status'     => StoredFile::STATUS_READY,
                    ])->save();
                }

                return $stale;
            });

            foreach ($stale as $key) {
                $store->delete(ObjectPath::fromKey((string) $key));
            }

            if (! $this->variantsOnly) {
                Event::dispatch(new FileReady($file));

                try {
                    $store->delete($file->stagingPath($service));
                } catch (\Throwable $e) {
                    // The file is ready either way; a staging object left behind is what
                    // `abeon:files:reconcile` exists for.
                    report($e);
                }
            }
        } finally {
            self::removeDirectory($workDir);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($this->variantsOnly) {
            return;
        }

        StoredFile::query()->whereKey($this->fileId)->where('status', StoredFile::STATUS_PROCESSING)
            ->update(['status' => StoredFile::STATUS_FAILED, 'failure_reason' => FileRejected::PROCESSING_FAILED]);
    }

    /**
     * The staging address stays signed for minutes after `complete`; the object processed has to be
     * the one that was checked, not one written over it since.
     */
    private function unchanged(ObjectStore $store, ObjectPath $source, StoredFile $file): bool
    {
        if ($this->expectedEtag === null) {
            return true;
        }

        $object = $store->head($source);

        return $object !== null && $object['etag'] === $this->expectedEtag && $object['size'] === $file->size;
    }

    private function stillMatches(FilePolicy $policy, StoredFile $file, string $input): bool
    {
        $sniffed = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($input);

        return filesize($input) === $file->size && $policy->contentMatches($file->mime, $sniffed);
    }

    private static function removeDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $path) {
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function readUrl(ObjectStore $store, ObjectPath $path): string
    {
        return $path->isPublic() ? $store->publicUrl($path) : $store->readUrl($path)->url;
    }

    private function put(ObjectStore $store, HttpFactory $http, ObjectPath $path, string $localFile, string $mime): void
    {
        $signed = $store->uploadUrl($path, $mime);
        $stream = fopen($localFile, 'rb');

        if ($stream === false) {
            throw new \RuntimeException("Cannot read {$localFile}");
        }

        try {
            $http->timeout(300)
                ->withHeaders($signed->headers)
                ->withBody(Utils::streamFor($stream), $mime)
                ->put($signed->url)
                ->throw();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
