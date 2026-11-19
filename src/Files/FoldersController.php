<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Folders of the library: a tree, and a folder must be empty to be deleted, so removing one never
 * moves or loses a file silently.
 */
final class FoldersController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => DB::table('folders')->orderBy('name')->get(['id', 'parent_id', 'name'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'integer', 'exists:folders,id'],
        ]);

        $id = DB::table('folders')->insertGetId([
            'name'       => trim($validated['name']),
            'parent_id'  => $validated['parent_id'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => DB::table('folders')->find($id, ['id', 'parent_id', 'name'])], 201);
    }

    public function update(Request $request, int $folder): JsonResponse
    {
        abort_unless(DB::table('folders')->where('id', $folder)->exists(), 404);

        $validated = $request->validate([
            'name'      => ['sometimes', 'required', 'string', 'max:120'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:folders,id', Rule::notIn([$folder])],
        ]);

        if (isset($validated['parent_id']) && in_array($folder, $this->ancestorsOf((int) $validated['parent_id']), true)) {
            abort(response()->json(['message' => 'A folder cannot move inside itself.', 'errors' => ['parent_id' => ['A folder cannot move inside itself.']]], 422));
        }

        DB::table('folders')->where('id', $folder)->update(array_intersect_key($validated, array_flip(['name', 'parent_id'])) + ['updated_at' => now()]);

        return response()->json(['data' => DB::table('folders')->find($folder, ['id', 'parent_id', 'name'])]);
    }

    public function destroy(int $folder): Response
    {
        abort_unless(DB::table('folders')->where('id', $folder)->exists(), 404);

        if (DB::table('folders')->where('parent_id', $folder)->exists() || StoredFile::withTrashed()->where('folder_id', $folder)->exists()) {
            abort(response()->json(['message' => 'The folder is not empty.', 'reason' => 'not_empty'], 409));
        }

        DB::table('folders')->where('id', $folder)->delete();

        return response()->noContent();
    }

    public function tags(): JsonResponse
    {
        return response()->json(['data' => DB::table('tags')->orderBy('name')->pluck('name')]);
    }

    /**
     * @return list<int>
     */
    private function ancestorsOf(int $id): array
    {
        $chain = [];

        while ($id !== 0 && ! in_array($id, $chain, true)) {
            $chain[] = $id;
            $id = (int) DB::table('folders')->where('id', $id)->value('parent_id');
        }

        return $chain;
    }
}
