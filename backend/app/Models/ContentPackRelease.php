<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'probe_ok',
        'probe_json',
        'probe_run_at',
    ];

    protected $casts = [
        'probe_ok' => 'boolean',
        'probe_json' => 'array',
        'probe_run_at' => 'datetime',
    ];

    public function fromVersion(): BelongsTo
    {
        return $this->belongsTo(ContentPackVersion::class, 'from_version_id', 'id');
    }

    public function toVersion(): BelongsTo
    {
        return $this->belongsTo(ContentPackVersion::class, 'to_version_id', 'id');
    }
}
