<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Files;

use Abeon\SDK\Files\FilePolicy;
use Abeon\SDK\Files\FileRejected;
use Abeon\SDK\Files\Files;
use Abeon\SDK\Files\FileVariant;
use Abeon\SDK\Files\Processing\ProcessFile;
use Abeon\SDK\Files\StorageQuotaWarning;
use Abeon\SDK\Files\StoredFile;
use Abeon\SDK\Files\Uploads;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The files module against a real database and a faked AbeonUnified. What matters is that
 * nothing the browser claims is believed: the size and the type are checked against what the
 * storage holds before a file leaves `pending`.
 */
final class UploadsTest extends FilesTestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    /** @var array<string, int>|null */
    private ?array $head = ['size' => 1000];

    private string $bytes = self::JPEG;

    private int $used = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ProcessFile::class]);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/storage/usage')            => Http::response(['data' => ['used_bytes' => $this->used, 'quota_bytes' => 10000]]),
                str_ends_with($path, '/storage/sign')             => Http::response(['data' => [
                    'url'        => 'http://storage.test/abeon-org-1/'.$request->data()['key'].'?X-Amz-Signature=x',
                    'expires_at' => '2026-10-29T10:00:00+00:00',
                    'headers'    => [],
                ]]),
                str_ends_with($path, '/storage/head')             => $this->head === null
                    ? Http::response(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], 404)
                    : Http::response(['data' => $this->head + ['etag' => 'e', 'content_type' => 'image/jpeg', 'last_modified' => '']]),
                str_ends_with($path, '/storage/multipart/create') => Http::response(['data' => ['upload_id' => 'up-1']]),
                str_ends_with($path, '/storage/multipart/part')   => Http::response(['data' => ['url' => 'http://storage.test/part', 'expires_at' => '2026-10-29T10:00:00+00:00', 'headers' => []]]),
                str_contains($path, '/storage/')                  => Http::response(null, 204),
                default                                            => Http::response($this->bytes, 206),
            };
        });
    }

    public function test_an_upload_begins_pending_under_a_private_staging_key(): void
    {
        $ticket = $this->uploads()->begin('Zażółć gęślą jaźń.JPG', 'image/jpeg', 1000, uploadedBy: 5);

        $file = $ticket->file->fresh();
        $this->assertNotNull($file);
        $this->assertSame(StoredFile::STATUS_PENDING, $file->status);
        $this->assertSame("cms/private/uploads/{$file->uuid}", $file->object_key);
        $this->assertSame('zazolc-gesla-jazn.jpg', $file->slug);
        $this->assertSame('public', $file->visibility);
        $this->assertSame(5, $file->uploaded_by);
        $this->assertFalse($ticket->isMultipart());
        $this->assertNotNull($ticket->upload);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/storage/sign')
            && $request->data() === ['key' => "cms/private/uploads/{$file->uuid}", 'operation' => 'put', 'content_type' => 'image/jpeg']);
    }

    public function test_a_type_or_size_outside_the_policy_is_refused_before_anything_is_recorded(): void
    {
        $this->assertRejected(FileRejected::TYPE_NOT_ALLOWED, fn () => $this->uploads()->begin('logo.svg', 'image/svg+xml', 100));
        $this->assertRejected(FileRejected::TOO_LARGE, fn () => $this->uploads()->begin('huge.jpg', 'image/jpeg', 21 * 1024 ** 2));
        $this->assertRejected(FileRejected::TOO_LARGE, fn () => $this->uploads()->begin('empty.jpg', 'image/jpeg', 0));

        $this->assertSame(0, StoredFile::query()->count());
    }

    public function test_an_upload_past_the_organisation_s_quota_is_refused(): void
    {
        $this->used = 9500;

        $this->assertRejected(FileRejected::QUOTA_EXCEEDED, fn () => $this->uploads()->begin('a.jpg', 'image/jpeg', 1000));
        $this->assertSame(0, StoredFile::query()->count());
    }

    public function test_crossing_eighty_percent_of_the_quota_warns_once_a_day(): void
    {
        Event::fake([StorageQuotaWarning::class]);

        $this->used = 7000;
        $this->uploads()->begin('a.jpg', 'image/jpeg', 900);
        Event::assertNotDispatched(StorageQuotaWarning::class);

        $this->used = 7900;
        $this->uploads()->begin('b.jpg', 'image/jpeg', 200);
        $this->uploads()->begin('c.jpg', 'image/jpeg', 200);

        Event::assertDispatchedTimes(StorageQuotaWarning::class, 1);
        // 7900 stored + 900 still uploading + 200 asked for: uploads in flight count, or a burst
        // of begins would each see room that only one of them has.
        Event::assertDispatched(StorageQuotaWarning::class, fn (StorageQuotaWarning $e): bool => $e->usedBytes === 9000 && $e->quotaBytes === 10000);
    }

    public function test_uploads_still_in_flight_count_against_the_quota(): void
    {
        $this->used = 8000;
        $this->uploads()->begin('a.jpg', 'image/jpeg', 1000);

        $this->assertRejected(FileRejected::QUOTA_EXCEEDED, fn () => $this->uploads()->begin('b.jpg', 'image/jpeg', 1001));
    }

    public function test_a_large_file_goes_in_parts(): void
    {
        $ticket = $this->uploads()->begin('scan.pdf', 'application/pdf', 6000);

        $this->assertTrue($ticket->isMultipart());
        $this->assertSame(2000, $ticket->partSize);
        $this->assertSame(3, $ticket->partCount);
        $this->assertSame('up-1', $ticket->file->fresh()?->upload_id);

        $this->uploads()->partUrl($ticket->file, 3);
        $this->expectException(\InvalidArgumentException::class);
        $this->uploads()->partUrl($ticket->file, 4);
    }

    public function test_a_duplicate_is_pointed_out(): void
    {
        $hash = hash('sha256', 'x');
        $first = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000, sha256: $hash)->file;
        $first->forceFill(['status' => StoredFile::STATUS_READY])->save();

        $this->assertSame($first->uuid, $this->uploads()->begin('b.jpg', 'image/jpeg', 1000, sha256: strtoupper($hash))->duplicateOf);
        $this->assertNull($this->uploads()->begin('c.jpg', 'image/jpeg', 1000, sha256: hash('sha256', 'y'))->duplicateOf);
    }

    public function test_a_complete_upload_whose_content_matches_moves_to_processing(): void
    {
        $file = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000)->file;

        $this->assertSame(StoredFile::STATUS_PROCESSING, $this->uploads()->complete($file)->fresh()?->status);
        Bus::assertDispatched(ProcessFile::class, fn (ProcessFile $job): bool => $job->fileId === $file->id && ! $job->variantsOnly);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Range', 'bytes=0-65535'));
    }

    public function test_content_that_is_not_what_was_declared_fails_and_is_removed(): void
    {
        $file = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000)->file;
        $this->bytes = '<html><script>alert(1)</script></html>';

        $this->assertRejected(FileRejected::CONTENT_MISMATCH, fn () => $this->uploads()->complete($file));

        $this->assertSame(StoredFile::STATUS_FAILED, $file->fresh()?->status);
        $this->assertSame(FileRejected::CONTENT_MISMATCH, $file->fresh()?->failure_reason);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/storage/delete')
            && $request->data() === ['key' => "cms/private/uploads/{$file->uuid}"]);
    }

    public function test_a_size_other_than_declared_fails(): void
    {
        $file = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000)->file;
        $this->head = ['size' => 999999];

        $this->assertRejected(FileRejected::SIZE_MISMATCH, fn () => $this->uploads()->complete($file));
    }

    public function test_nothing_uploaded_fails(): void
    {
        $file = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000)->file;
        $this->head = null;

        $this->assertRejected(FileRejected::MISSING_OBJECT, fn () => $this->uploads()->complete($file));
    }

    public function test_two_completions_racing_start_one_job(): void
    {
        $file = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000)->file;
        StoredFile::query()->whereKey($file->id)->update(['status' => StoredFile::STATUS_PROCESSING]);

        $this->assertRejected(FileRejected::NOT_PENDING, fn () => $this->uploads()->complete($file));
        Bus::assertNotDispatched(ProcessFile::class);
    }

    public function test_the_job_is_told_which_bytes_were_checked(): void
    {
        $file = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000)->file;
        $this->uploads()->complete($file);

        Bus::assertDispatched(ProcessFile::class, fn (ProcessFile $job): bool => $job->expectedEtag === 'e');
    }

    public function test_an_upload_completes_once(): void
    {
        $file = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000)->file;
        $this->uploads()->complete($file);

        $this->assertRejected(FileRejected::NOT_PENDING, fn () => $this->uploads()->complete($file));
    }

    public function test_a_multipart_upload_is_assembled_before_it_is_checked(): void
    {
        $this->head = ['size' => 6000];
        $this->bytes = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n";
        $file = $this->uploads()->begin('scan.pdf', 'application/pdf', 6000)->file;

        $this->uploads()->complete($file, [['part_number' => 1, 'etag' => 'a'], ['part_number' => 2, 'etag' => 'b'], ['part_number' => 3, 'etag' => 'c']]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/multipart/complete')
            && $request->data()['upload_id'] === 'up-1' && count($request->data()['parts']) === 3);
        $this->assertNull($file->fresh()?->upload_id);
    }

    public function test_abandoned_uploads_are_pruned_and_recent_ones_kept(): void
    {
        $old = $this->uploads()->begin('old.pdf', 'application/pdf', 6000)->file;
        $old->forceFill(['created_at' => Carbon::now()->subHours(25)])->save();
        $recent = $this->uploads()->begin('new.jpg', 'image/jpeg', 1000)->file;

        $this->artisan('abeon:files:prune-pending')->assertSuccessful();

        $this->assertNull(StoredFile::query()->find($old->id));
        $this->assertNotNull(StoredFile::query()->find($recent->id));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/multipart/abort'));
    }

    public function test_the_trash_is_emptied_after_its_retention_with_every_object(): void
    {
        $file = $this->readyFile();
        FileVariant::query()->create(['file_id' => $file->id, 'preset' => 'thumb', 'object_key' => "cms/public/thumbnails/{$file->uuid}/thumb.webp", 'mime' => 'image/webp', 'size' => 10]);

        app(Files::class)->trash($file);
        $this->artisan('abeon:files:purge-trash')->assertSuccessful();
        $this->assertNotNull(StoredFile::withTrashed()->find($file->id), 'Inside the retention period it can still be restored.');

        StoredFile::withTrashed()->whereKey($file->id)->update(['deleted_at' => Carbon::now()->subDays(31)]);
        $this->artisan('abeon:files:purge-trash')->assertSuccessful();

        $this->assertNull(StoredFile::withTrashed()->find($file->id));
        Http::assertSent(fn (Request $request): bool => ($request->data()['key'] ?? null) === "cms/public/thumbnails/{$file->uuid}/thumb.webp");
        Http::assertSent(fn (Request $request): bool => ($request->data()['key'] ?? null) === "cms/public/files/{$file->uuid}/a.jpg");
    }

    public function test_a_public_address_exists_only_for_a_ready_public_file(): void
    {
        $file = $this->readyFile();

        $this->assertSame("http://storage.test/abeon-org-1/cms/public/files/{$file->uuid}/a.jpg", app(Files::class)->publicUrl($file));

        $file->status = StoredFile::STATUS_PROCESSING;
        $this->assertNull(app(Files::class)->publicUrl($file));
    }

    public function test_sniffed_families_believe_only_their_own_declared_types(): void
    {
        $policy = app(FilePolicy::class);

        $this->assertTrue($policy->contentMatches('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'));
        $this->assertTrue($policy->contentMatches('text/csv', 'text/plain'));
        $this->assertFalse($policy->contentMatches('application/pdf', 'application/zip'));
        $this->assertFalse($policy->contentMatches('image/jpeg', 'text/html'));
        $this->assertSame('image/jpeg', FilePolicy::normalise('Image/JPG; charset=binary'));
    }

    private function readyFile(): StoredFile
    {
        $file = $this->uploads()->begin('a.jpg', 'image/jpeg', 1000)->file;
        $file->forceFill(['status' => StoredFile::STATUS_READY, 'object_key' => "cms/public/files/{$file->uuid}/a.jpg"])->save();

        return $file;
    }

    private function uploads(): Uploads
    {
        return app(Uploads::class);
    }

    private function assertRejected(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (FileRejected $e) {
            $this->assertSame($reason, $e->reason);

            return;
        }

        $this->fail("Expected the module to refuse with {$reason}.");
    }
}
