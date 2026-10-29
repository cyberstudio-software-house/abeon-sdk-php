<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Illuminate\Database\Eloquent\Model;

/**
 * For a model that refers to files: it says which, and the module keeps `file_usages` in step on
 * every save and removes the rows when the model is deleted. A file with usages cannot be trashed
 * (Files PRD §5.1), which replaces lists of ids kept as text in a column.
 *
 * The model implements `usedFileUuids()`; `fileUsageLabel()` and `fileUsageUrl()` are optional.
 * A soft-deleted model keeps its usages, because it can come back.
 *
 * @mixin Model
 */
trait UsesFiles
{
    /**
     * @return list<string>
     */
    abstract public function usedFileUuids(): array;

    public static function bootUsesFiles(): void
    {
        static::saved(static fn (self $model) => FileUsages::sync($model, $model->usedFileUuids(), $model->fileUsageLabel(), $model->fileUsageUrl()));
        static::deleted(static function (Model $model): void {
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            FileUsages::forget($model);
        });
    }

    public function fileUsageLabel(): ?string
    {
        return null;
    }

    public function fileUsageUrl(): ?string
    {
        return null;
    }
}
