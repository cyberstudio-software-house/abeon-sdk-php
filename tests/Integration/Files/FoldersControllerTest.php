<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Files;

use Abeon\SDK\Files\FoldersController;
use Abeon\SDK\Files\StoredFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class FoldersControllerTest extends FilesTestCase
{
    private const JSON = ['Accept' => 'application/json'];

    protected function defineRoutes($router): void
    {
        Route::get('folders', [FoldersController::class, 'index']);
        Route::get('tags', [FoldersController::class, 'tags']);
        Route::post('folders', [FoldersController::class, 'store']);
        Route::patch('folders/{folder}', [FoldersController::class, 'update'])->whereNumber('folder');
        Route::delete('folders/{folder}', [FoldersController::class, 'destroy'])->whereNumber('folder');
    }

    public function test_folders_form_a_tree_that_cannot_loop_and_only_empty_ones_go(): void
    {
        $parent = (int) $this->post('/folders', ['name' => ' Marketing '], self::JSON)->assertCreated()->assertJsonPath('data.name', 'Marketing')->json('data.id');
        $child = (int) $this->post('/folders', ['name' => 'Kampanie', 'parent_id' => $parent], self::JSON)->assertCreated()->json('data.id');

        $this->patch("/folders/{$parent}", ['parent_id' => $child], self::JSON)->assertStatus(422);
        $this->patch("/folders/{$parent}", ['parent_id' => $parent], self::JSON)->assertStatus(422);
        $this->patch("/folders/{$child}", ['name' => 'Akcje'], self::JSON)->assertOk()->assertJsonPath('data.name', 'Akcje');
        $this->delete("/folders/{$parent}", [], self::JSON)->assertStatus(409);

        $uuid = (string) Str::uuid7();
        StoredFile::query()->create([
            'uuid' => $uuid, 'visibility' => 'public', 'object_key' => "cms/public/files/{$uuid}/a.jpg",
            'original_name' => 'a.jpg', 'slug' => 'a.jpg', 'display_name' => 'a.jpg', 'mime' => 'image/jpeg',
            'size' => 1000, 'folder_id' => $child, 'status' => StoredFile::STATUS_READY,
        ]);
        $this->delete("/folders/{$child}", [], self::JSON)->assertStatus(409);

        StoredFile::query()->forceDelete();
        $this->delete("/folders/{$child}", [], self::JSON)->assertNoContent();
        $this->delete("/folders/{$parent}", [], self::JSON)->assertNoContent();
        $this->delete("/folders/{$parent}", [], self::JSON)->assertNotFound();
        $this->getJson('/folders')->assertExactJson(['data' => []]);
    }

    public function test_tags_are_listed_by_name(): void
    {
        DB::table('tags')->insert([['name' => 'zima'], ['name' => 'lato']]);

        $this->getJson('/tags')->assertExactJson(['data' => ['lato', 'zima']]);
    }
}
