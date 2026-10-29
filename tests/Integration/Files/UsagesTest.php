<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Files;

use Abeon\SDK\Files\FileRejected;
use Abeon\SDK\Files\Files;
use Abeon\SDK\Files\FileUsage;
use Abeon\SDK\Files\StoredFile;
use Abeon\SDK\Files\UsesFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "Where used" and the refusal it backs: a file something still uses cannot go to the trash, and
 * the bookkeeping follows the entity through every save and its deletion.
 */
final class UsagesTest extends FilesTestCase
{
    /** @var list<array{key: string, size: int, etag: string, last_modified: string}> */
    private array $listed = [];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pages', function ($table): void {
            $table->id();
            $table->string('title');
            $table->json('file_uuids');
            $table->timestamps();
            $table->softDeletes();
        });

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/storage/list')) {
                return Http::response(['data' => ['objects' => $this->listed, 'next_cursor' => null]]);
            }

            return Http::response(null, 204);
        });
    }

    public function test_saving_an_entity_records_exactly_the_files_it_names(): void
    {
        [$a, $b] = [$this->file(), $this->file()];

        $page = UsingPage::query()->create(['title' => 'Home', 'file_uuids' => [$a->uuid, $b->uuid, $a->uuid, (string) Str::uuid7()]]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], FileUsage::query()->pluck('file_id')->all());
        $usage = FileUsage::query()->where('file_id', $a->id)->firstOrFail();
        $this->assertSame([UsingPage::class, (string) $page->id, 'Home', "/pages/{$page->id}"], [$usage->entity_type, $usage->entity_id, $usage->label, $usage->admin_url]);

        $page->update(['title' => 'Start', 'file_uuids' => [$b->uuid]]);

        $this->assertSame([$b->id], FileUsage::query()->pluck('file_id')->all());
        $this->assertSame('Start', FileUsage::query()->value('label'));
    }

    public function test_a_file_in_use_is_not_trashed_and_the_refusal_says_where(): void
    {
        $file = $this->file();
        $page = UsingPage::query()->create(['title' => 'Home', 'file_uuids' => [$file->uuid]]);

        try {
            app(Files::class)->trash($file);
            $this->fail('A file in use went to the trash.');
        } catch (FileRejected $e) {
            $this->assertSame(FileRejected::IN_USE, $e->reason);
            $this->assertSame([['entity_type' => UsingPage::class, 'entity_id' => (string) $page->id, 'label' => 'Home', 'admin_url' => "/pages/{$page->id}"]], $e->usages);
        }

        $this->assertFalse($file->fresh()?->trashed());

        $page->forceDelete();
        app(Files::class)->trash($file);
        $this->assertTrue(StoredFile::withTrashed()->find($file->id)?->trashed());
    }

    public function test_an_entity_in_its_own_trash_keeps_its_files(): void
    {
        $file = $this->file();
        $page = UsingPage::query()->create(['title' => 'Home', 'file_uuids' => [$file->uuid]]);

        $page->delete();

        $this->assertSame(1, FileUsage::query()->count(), 'A page that can be restored still needs its images.');
    }

    public function test_an_erasure_purges_a_file_whatever_uses_it(): void
    {
        $file = $this->file();
        UsingPage::query()->create(['title' => 'Home', 'file_uuids' => [$file->uuid]]);

        app(Files::class)->purge($file);

        $this->assertNull(StoredFile::withTrashed()->find($file->id));
        $this->assertSame(0, FileUsage::query()->count());
    }

    public function test_reconcile_reports_missing_objects_and_deletes_only_old_orphans(): void
    {
        $present = $this->file();
        $missing = $this->file();
        $old = Carbon::now()->subDays(8)->toAtomString();
        $recent = Carbon::now()->subHour()->toAtomString();

        $this->listed = [
            ['key' => (string) $present->object_key, 'size' => 1, 'etag' => 'e', 'last_modified' => $old],
            ['key' => 'cms/public/files/old-orphan/a.jpg', 'size' => 1, 'etag' => 'e', 'last_modified' => $old],
            ['key' => 'cms/private/uploads/still-arriving', 'size' => 1, 'etag' => 'e', 'last_modified' => $recent],
        ];

        $report = app(Files::class)->reconcile('cms');

        $this->assertSame([(string) $missing->object_key], $report['missing']);
        $this->assertSame(['cms/public/files/old-orphan/a.jpg', 'cms/private/uploads/still-arriving'], $report['orphans']);
        $this->assertSame(1, $report['deleted']);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/storage/delete')
            && $request->data() === ['key' => 'cms/public/files/old-orphan/a.jpg']);
        Http::assertNotSent(fn (Request $request): bool => ($request->data()['key'] ?? null) === 'cms/private/uploads/still-arriving');

        $this->artisan('abeon:files:reconcile')->assertFailed();
    }

    private function file(): StoredFile
    {
        $uuid = (string) Str::uuid7();

        return StoredFile::query()->create([
            'uuid'          => $uuid,
            'visibility'    => 'public',
            'object_key'    => "cms/public/files/{$uuid}/a.jpg",
            'original_name' => 'a.jpg',
            'slug'          => 'a.jpg',
            'display_name'  => 'a.jpg',
            'mime'          => 'image/jpeg',
            'size'          => 1,
            'status'        => StoredFile::STATUS_READY,
        ]);
    }
}

/**
 * @property int $id
 * @property string $title
 * @property list<string> $file_uuids
 */
final class UsingPage extends Model
{
    use SoftDeletes;
    use UsesFiles;

    protected $table = 'pages';

    protected $guarded = ['id'];

    protected $casts = ['file_uuids' => 'array'];

    public function usedFileUuids(): array
    {
        return $this->file_uuids;
    }

    public function fileUsageLabel(): ?string
    {
        return $this->title;
    }

    public function fileUsageUrl(): ?string
    {
        return "/pages/{$this->id}";
    }
}
