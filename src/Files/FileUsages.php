<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The bookkeeping behind `UsesFiles`. Uuids the model names that are not files of this
 * application, or are in the trash, are not recorded: validating references is the caller's
 * job, and a usage of a file that does not exist would block nothing.
 */
final class FileUsages
{
    /**
     * @param  list<string>  $named
     */
    public static function sync(Model $model, array $named, ?string $label, ?string $adminUrl): void
    {
        $uuids = array_values(array_unique(array_filter(
            $named,
            static fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '',
        )));

        $ids = $uuids === [] ? [] : StoredFile::query()->whereIn('uuid', $uuids)->pluck('id')->all();
        [$type, $id] = self::entityOf($model);

        FileUsage::query()->where('entity_type', $type)->where('entity_id', $id)->whereNotIn('file_id', $ids)->delete();

        if ($ids === []) {
            return;
        }

        $now = Carbon::now();
        FileUsage::query()->upsert(
            array_map(static fn (int $fileId): array => [
                'file_id'     => $fileId,
                'entity_type' => $type,
                'entity_id'   => $id,
                'label'       => $label,
                'admin_url'   => $adminUrl,
                'created_at'  => $now,
                'updated_at'  => $now,
            ], array_map('intval', $ids)),
            ['file_id', 'entity_type', 'entity_id'],
            ['label', 'admin_url', 'updated_at'],
        );
    }

    public static function forget(Model $model): void
    {
        [$type, $id] = self::entityOf($model);

        FileUsage::query()->where('entity_type', $type)->where('entity_id', $id)->delete();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function entityOf(Model $model): array
    {
        return [$model->getMorphClass(), (string) $model->getKey()];
    }
}
