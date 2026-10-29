<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entity using one file, kept by `UsesFiles`. `label` and `admin_url` are what "where used"
 * shows: the entity's name and the panel page that edits it.
 *
 * @property int $id
 * @property int $file_id
 * @property string $entity_type
 * @property string $entity_id
 * @property string|null $label
 * @property string|null $admin_url
 */
class FileUsage extends Model
{
    protected $table = 'file_usages';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['file_id' => 'integer'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
