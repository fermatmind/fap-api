<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentReleaseSnapshot extends Model
{
    protected $table = 'content_release_snapshots';

    protected $fillable = [
        'pack_id',
        'pack_version',
        'from_content_pack_release_id',
        'to_content_pack_release_id',
        'activation_before_release_id',
        'activation_after_release_id',
        'reason',
        'created_by',
        'meta_json',
    ];

    protected $casts = [
        'meta_json' => 'array',
    ];
}
