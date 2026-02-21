<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScaleNormsVersion extends Model
{
    protected $table = 'scale_norms_versions';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'scale_code',
        'norm_id',
        'region',
        'locale',
        'version',
        'group_id',
        'gender',
        'age_min',
        'age_max',
        'source_id',
        'source_type',
        'status',
        'is_active',
        'published_at',
        'checksum',
        'meta_json',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'meta_json' => 'array',
        'age_min' => 'integer',
        'age_max' => 'integer',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function stats()
    {
        return $this->hasMany(ScaleNormStat::class, 'norm_version_id', 'id');
    }
}
