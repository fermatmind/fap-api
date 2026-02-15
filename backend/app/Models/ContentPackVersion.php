<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentPackVersion extends Model
{
    use HasUuids;

    protected $table = 'content_pack_versions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'region',
        'locale',
        'pack_id',
        'content_package_version',
        'dir_version_alias',
        'source_type',
        'source_ref',
        'sha256',
        'manifest_json',
        'extracted_rel_path',
        'created_by',
    ];

    protected $casts = [
        'manifest_json' => 'array',
    ];

    public function releasesFrom(): HasMany
    {
        return $this->hasMany(ContentPackRelease::class, 'from_version_id', 'id');
    }

    public function releasesTo(): HasMany
    {
        return $this->hasMany(ContentPackRelease::class, 'to_version_id', 'id');
    }
}
