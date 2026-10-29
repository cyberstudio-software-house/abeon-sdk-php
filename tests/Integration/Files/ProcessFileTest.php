<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Files;

use Abeon\SDK\Files\FileReady;
use Abeon\SDK\Files\FileRejected;
use Abeon\SDK\Files\Files;
use Abeon\SDK\Files\Processing\ProcessFile;
use Abeon\SDK\Files\Processing\Toolbox;
use Abeon\SDK\Files\StoredFile;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

/**
 * Processing with the real tools (libvips, poppler) and a faked storage that keeps every
 * object in memory. Skipped where the tools are not installed, as the storage tests are
 * skipped without MinIO.
 */
#[Group('processing')]
final class ProcessFileTest extends FilesTestCase
{
    /** @var array<string, string> key => bytes */
    private array $objects = [];

    private string $fixtures = '';

    protected function setUp(): void
    {
        parent::setUp();

        if ((new Process(["which", "vips", "pdftoppm", "prlimit"]))->run() !== 0) {
            $this->markTestSkipped('libvips, poppler or prlimit is not installed');
        }

        $this->fixtures = sys_get_temp_dir().'/abeon-fixtures-'.bin2hex(random_bytes(4));
        mkdir($this->fixtures);

        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/storage/sign')) {
                return Http::response(['data' => [
                    'url'        => 'http://storage.test/abeon-org-1/'.$request->data()['key'].'?X-Amz-Signature=x',
                    'expires_at' => '2026-10-29T10:00:00+00:00',
                    'headers'    => $request->data()['operation'] === 'put' ? ['Content-Type' => $request->data()['content_type']] : [],
                ]]);
            }

            if (str_ends_with($path, '/storage/head')) {
                $bytes = $this->objects[$request->data()['key']] ?? null;

                return $bytes === null
                    ? Http::response(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], 404)
                    : Http::response(['data' => ['size' => strlen($bytes), 'etag' => md5($bytes), 'content_type' => '', 'last_modified' => '']]);
            }

            if (str_ends_with($path, '/storage/delete')) {
                unset($this->objects[$request->data()['key']]);

                return Http::response(null, 204);
            }

            $key = Str::after($path, '/abeon-org-1/');

            if ($request->method() === 'PUT') {
                $this->objects[$key] = $request->body();

                return Http::response('', 200);
            }

            return isset($this->objects[$key]) ? Http::response($this->objects[$key], 200) : Http::response('', 404);
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtures.'/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->fixtures);

        parent::tearDown();
    }

    public function test_a_photo_loses_its_metadata_is_turned_upright_and_gets_its_variants(): void
    {
        Event::fake([FileReady::class]);
        $photo = $this->jpegWithGpsAndOrientation(400, 300);
        $this->assertStringContainsString("Exif\0\0", $photo, 'The fixture carries EXIF to begin with.');

        $file = $this->staged($photo, 'image/jpeg', 'Wakacje 2026.jpg');
        $this->process($file);
        $file->refresh();

        $this->assertSame(StoredFile::STATUS_READY, $file->status);
        $this->assertSame("cms/public/files/{$file->uuid}/wakacje-2026.jpg", $file->object_key);
        $this->assertSame([300, 400], [$file->width, $file->height], 'Orientation 6 is applied, not merely kept.');
        $this->assertSame(hash('sha256', $photo), $file->sha256);
        $this->assertArrayNotHasKey("cms/private/uploads/{$file->uuid}", $this->objects, 'The staged upload is gone.');

        $original = $this->objects[$file->object_key];
        $this->assertStringNotContainsString("Exif\0\0", $original);
        $this->assertStringStartsWith("\xFF\xD8", $original);

        $variants = $file->variants()->orderBy('preset')->orderBy('mime')->get()
            ->map(fn ($v): string => "{$v->preset} {$v->mime} {$v->width}x{$v->height}")->all();
        $this->assertSame([
            'lg image/jpeg 300x400', 'lg image/webp 300x400',
            'md image/jpeg 300x400', 'md image/webp 300x400',
            'og image/jpeg 300x158', 'og image/webp 300x158',
            'thumb image/jpeg 300x400', 'thumb image/webp 300x400',
        ], $variants);

        foreach ($file->variants as $variant) {
            $this->assertStringNotContainsString("Exif\0\0", $this->objects[$variant->object_key]);
            $this->assertStringStartsWith("cms/public/thumbnails/{$file->uuid}/", $variant->object_key);
        }

        Event::assertDispatched(FileReady::class, fn (FileReady $event): bool => $event->file->is($file));
    }

    public function test_a_large_image_is_scaled_down_and_cropped_around_its_focal_point(): void
    {
        $gradient = $this->gradientPng(2000, 1000);

        $left = $this->staged($gradient, 'image/png', 'left.png', focalX: 0.0);
        $right = $this->staged($gradient, 'image/png', 'right.png', focalX: 1.0);
        $this->process($left);
        $this->process($right);

        $thumb = $left->variants()->where('preset', 'thumb')->where('mime', 'image/png')->firstOrFail();
        $this->assertSame([320, 160], [$thumb->width, $thumb->height]);

        $og = fn (StoredFile $file): string => $this->objects[$file->variants()->where('preset', 'og')->where('mime', 'image/png')->firstOrFail()->object_key];
        $this->assertSame([1200, 630], $this->imageSize($og($left)));
        $this->assertLessThan($this->firstPixel($og($right)), $this->firstPixel($og($left)) + 5,
            'The crop of a right-hand focal point starts further right, where the gradient is brighter.');
    }

    public function test_an_image_over_the_pixel_limit_fails_without_retrying(): void
    {
        config(['abeon.files.processing.max_pixels' => 1000]);
        $this->app->forgetInstance(\Abeon\SDK\Files\Processing\ImageProcessor::class);

        $file = $this->staged($this->gradientPng(100, 100), 'image/png', 'big.png');
        $this->process($file);

        $this->assertSame(StoredFile::STATUS_FAILED, $file->fresh()?->status);
        $this->assertSame(FileRejected::TOO_MANY_PIXELS, $file->fresh()?->failure_reason);
    }

    public function test_a_pdf_keeps_its_bytes_and_gets_a_first_page_preview(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 100]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

        $file = $this->staged($pdf, 'application/pdf', 'Regulamin.pdf', visibility: 'private');
        $this->process($file);
        $file->refresh();

        $this->assertSame(StoredFile::STATUS_READY, $file->status);
        $this->assertSame(1, $file->pages);
        $this->assertSame($pdf, $this->objects[(string) $file->object_key]);
        $this->assertStringStartsWith("cms/private/files/{$file->uuid}/", (string) $file->object_key);
        $this->assertSame(0, $file->variants()->where('preset', 'og')->count(), 'A cropped preview of a document helps nobody.');
        $this->assertSame(320, $file->variants()->where('preset', 'thumb')->where('mime', 'image/webp')->value('width'));
    }

    public function test_moving_the_focal_point_remakes_the_variants_from_the_final_original(): void
    {
        $file = $this->staged($this->gradientPng(2000, 1000), 'image/png', 'hero.png');
        $this->process($file);
        $file->refresh();
        $before = $this->objects[$file->variants()->where('preset', 'og')->where('mime', 'image/png')->value('object_key')];

        Bus::fake([ProcessFile::class]);
        app(Files::class)->setFocalPoint($file, 1.0, 0.5);
        Bus::assertDispatched(ProcessFile::class, fn (ProcessFile $job): bool => $job->variantsOnly && $job->fileId === $file->id);

        app()->call([new ProcessFile($file->id, variantsOnly: true), 'handle']);

        $after = $this->objects[$file->variants()->where('preset', 'og')->where('mime', 'image/png')->value('object_key')];
        $this->assertNotSame($before, $after);
        $this->assertSame(StoredFile::STATUS_READY, $file->fresh()?->status);
        $this->assertSame(8, $file->variants()->count());
    }

    public function test_bytes_written_over_the_staging_key_after_the_check_are_not_processed(): void
    {
        $photo = $this->gradientPng(100, 100);
        $file = $this->staged($photo, 'image/png', 'a.png');
        $checked = md5($photo);
        $this->objects["cms/private/uploads/{$file->uuid}"] = str_pad('<html><script>alert(1)</script></html>', strlen($photo));

        app()->call([new ProcessFile($file->id, expectedEtag: $checked), 'handle']);

        $this->assertSame(StoredFile::STATUS_FAILED, $file->fresh()?->status);
        $this->assertSame(FileRejected::CONTENT_MISMATCH, $file->fresh()?->failure_reason);
        $this->assertSame([], array_values(array_filter(array_keys($this->objects), static fn (string $k): bool => str_contains($k, '/public/'))));
    }

    public function test_a_file_the_parser_cannot_read_fails_at_once_instead_of_being_retried(): void
    {
        $broken = substr($this->jpegWithGpsAndOrientation(400, 300), 0, 200);
        $file = $this->staged($broken, 'image/jpeg', 'broken.jpg');

        $this->process($file);

        $this->assertSame(StoredFile::STATUS_FAILED, $file->fresh()?->status);
        $this->assertSame(FileRejected::PROCESSING_FAILED, $file->fresh()?->failure_reason);
    }

    public function test_a_parser_sees_nothing_of_the_application_s_environment(): void
    {
        putenv('ABEON_SERVICE_JWT_PRIVATE_KEY=very-secret');
        $_ENV['DB_PASSWORD'] = 'also-secret';
        $_SERVER['APP_KEY'] = 'base64:secret';

        try {
            $env = app(Toolbox::class)->run($this->fixtures, 'env');
        } finally {
            putenv('ABEON_SERVICE_JWT_PRIVATE_KEY');
            unset($_ENV['DB_PASSWORD'], $_SERVER['APP_KEY']);
        }

        $this->assertStringNotContainsString('secret', $env);
        $this->assertStringContainsString('VIPS_BLOCK_UNTRUSTED=1', $env);
        $this->assertStringContainsString('PATH=', $env);
        $this->assertStringContainsString('HOME='.$this->fixtures, $env);
    }

    private function process(StoredFile $file): void
    {
        app()->call([new ProcessFile($file->id), 'handle']);
    }

    private function staged(string $bytes, string $mime, string $name, ?float $focalX = null, string $visibility = 'public'): StoredFile
    {
        $uuid = (string) Str::uuid7();
        $this->objects["cms/private/uploads/{$uuid}"] = $bytes;

        return StoredFile::query()->create([
            'uuid'          => $uuid,
            'visibility'    => $visibility,
            'object_key'    => "cms/private/uploads/{$uuid}",
            'original_name' => $name,
            'slug'          => \Abeon\SDK\Files\Uploads::slug($name),
            'display_name'  => $name,
            'mime'          => $mime,
            'size'          => strlen($bytes),
            'focal_x'       => $focalX,
            'focal_y'       => $focalX === null ? null : 0.5,
            'status'        => StoredFile::STATUS_PROCESSING,
        ]);
    }

    private function vips(string ...$arguments): void
    {
        $process = new Process(['vips', ...$arguments], $this->fixtures);
        $process->mustRun();
    }

    private function gradientPng(int $width, int $height): string
    {
        $this->vips('xyz', 'xyz.v', (string) $width, (string) $height);
        $this->vips('extract_band', 'xyz.v', 'x.v', '0');
        $this->vips('linear', 'x.v', 'scaled.v', (string) (255 / $width), '0', '--uchar');
        $this->vips('copy', 'scaled.v', 'gradient.png');

        return (string) file_get_contents($this->fixtures.'/gradient.png');
    }

    /**
     * A JPEG with an APP1 segment holding Orientation 6 and a GPS block, built by hand so the
     * fixture says exactly what it carries.
     */
    private function jpegWithGpsAndOrientation(int $width, int $height): string
    {
        $this->vips('black', 'black.v', (string) $width, (string) $height);
        $this->vips('copy', 'black.v', 'black.jpg');
        $jpeg = (string) file_get_contents($this->fixtures.'/black.jpg');

        $entry = static fn (int $tag, int $type, int $count, string $value): string => pack('nnN', $tag, $type, $count).str_pad($value, 4, "\0");
        $ifd0 = pack('n', 2)
            .$entry(0x0112, 3, 1, pack('n', 6))
            .$entry(0x8825, 4, 1, pack('N', 38))
            .pack('N', 0);
        $gps = pack('n', 1).$entry(0x0001, 2, 2, "N\0").pack('N', 0);
        $tiff = "MM\0*".pack('N', 8).$ifd0.$gps;
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function imageSize(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info);

        return [$info[0], $info[1]];
    }

    private function firstPixel(string $png): int
    {
        file_put_contents($this->fixtures.'/probe.png', $png);
        $process = new Process(['vips', 'getpoint', 'probe.png', '0', '0'], $this->fixtures);
        $process->mustRun();

        return (int) round((float) trim($process->getOutput()));
    }
}
