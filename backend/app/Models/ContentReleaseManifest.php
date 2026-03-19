<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentReleaseManifest extends Model
{
    protected $table = 'content_release_manifests';

    protected $fillable = [
        'content_pack_release_id',
        'manifest_hash',
        'schema_version',
        'storage_disk',
        'storage_path',
        'pack_id',
        'pack_version',
        'compiled_hash',
        'content_hash',
        'norms_version',
        'source_commit',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(ContentPackRelease::class, 'content_pack_release_id', 'id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ContentReleaseManifestFile::class, 'content_release_manifest_id', 'id');
    }
}
