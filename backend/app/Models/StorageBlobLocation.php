<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageBlobLocation extends Model
{
    protected $table = 'storage_blob_locations';

    protected $fillable = [
        'blob_hash',
        'disk',
        'storage_path',
        'location_kind',
        'size_bytes',
        'checksum',
        'etag',
        'storage_class',
        'verified_at',
        'meta_json',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'verified_at' => 'datetime',
        'meta_json' => 'array',
    ];
}
