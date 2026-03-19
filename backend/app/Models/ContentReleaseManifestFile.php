<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentReleaseManifestFile extends Model
{
    protected $table = 'content_release_manifest_files';

    protected $fillable = [
        'content_release_manifest_id',
        'logical_path',
        'blob_hash',
        'size_bytes',
        'role',
        'content_type',
        'encoding',
        'checksum',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(ContentReleaseManifest::class, 'content_release_manifest_id', 'id');
    }
}
