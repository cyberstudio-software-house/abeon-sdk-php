<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $file_id
 * @property string $preset
 * @property string $object_key
 * @property string $mime
 * @property int|null $width
 * @property int|null $height
 * @property int $size
 */
class FileVariant extends Model
{
    protected $table = 'file_variants';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['file_id' => 'integer', 'width' => 'integer', 'height' => 'integer', 'size' => 'integer'];
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
