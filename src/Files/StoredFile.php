<?php

declare(strict_types=1);

namespace Abeon\SDK\Files;

use Abeon\SDK\Storage\ObjectPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One file of this application (ADR-0037). The application refers to it by `uuid`; the object
 * key is where the bytes are, and it changes once, when processing moves an upload from its
 * private staging key to where it will live.
 *
 * @property int $id
 * @property string $uuid
 * @property string $visibility
 * @property string|null $object_key
 * @property string|null $upload_id
 * @property string $original_name
 * @property string $slug
 * @property string $display_name
 * @property string|null $description
 * @property string $mime
 * @property int $size
 * @property string|null $sha256
 * @property int|null $width
 * @property int|null $height
 * @property int|null $pages
 * @property float|null $focal_x
 * @property float|null $focal_y
 * @property int|null $folder_id
 * @property string $status
 * @property string|null $failure_reason
 * @property int|null $uploaded_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class StoredFile extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $table = 'files';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size'        => 'integer',
            'width'       => 'integer',
            'height'      => 'integer',
            'pages'       => 'integer',
            'focal_x'     => 'float',
            'focal_y'     => 'float',
            'folder_id'   => 'integer',
            'uploaded_by' => 'integer',
        ];
    }

    /**
     * @return HasMany<FileVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(FileVariant::class, 'file_id');
    }

    public function path(): ?ObjectPath
    {
        return $this->object_key === null ? null : ObjectPath::fromKey($this->object_key);
    }

    public function stagingPath(string $service): ObjectPath
    {
        return ObjectPath::privatePath($service, "uploads/{$this->uuid}");
    }

    public function isPublic(): bool
    {
        return $this->visibility === ObjectPath::VISIBILITY_PUBLIC;
    }
}
