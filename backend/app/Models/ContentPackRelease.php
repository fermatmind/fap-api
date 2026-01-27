<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class ContentPackRelease extends Model
{
    use HasUuids;

    protected $table = 'content_pack_releases';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'action',
        'region',
        'locale',
        'dir_alias',
        'from_version_id',
        'to_version_id',
        'from_pack_id',
        'to_pack_id',
        'status',
        'message',
        'created_by',
    ];
}
