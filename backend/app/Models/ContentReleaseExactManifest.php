<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentReleaseExactManifest extends Model
{
    protected $table = 'content_release_exact_manifests';

    protected $fillable = [
        'content_pack_release_id',
        'source_identity_hash',
        'manifest_hash',
        'exact_identity_hash',
        'schema_version',
        'source_kind',
        'source_disk',
        'source_storage_path',
        'pack_id',
        'pack_version',
        'compiled_hash',
        'content_hash',
        'norms_version',
        'source_commit',
        'file_count',
        'total_size_bytes',
        'payload_json',
        'sealed_at',
        'last_verified_at',
    ];

    protected $casts = [
        'file_count' => 'integer',
        'total_size_bytes' => 'integer',
        'payload_json' => 'array',
        'sealed_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(ContentPackRelease::class, 'content_pack_release_id', 'id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ContentReleaseExactManifestFile::class, 'content_release_exact_manifest_id', 'id');
    }
}
