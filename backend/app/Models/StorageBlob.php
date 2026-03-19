<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageBlob extends Model
{
    protected $table = 'storage_blobs';

    protected $primaryKey = 'hash';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'hash',
        'disk',
        'storage_path',
        'size_bytes',
        'content_type',
        'encoding',
        'ref_count',
        'first_seen_at',
        'last_verified_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'ref_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];
}
