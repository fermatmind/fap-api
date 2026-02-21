<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScaleNormStat extends Model
{
    protected $table = 'scale_norm_stats';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'norm_version_id',
        'metric_level',
        'metric_code',
        'mean',
        'sd',
        'sample_n',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'mean' => 'float',
        'sd' => 'float',
        'sample_n' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function normsVersion()
    {
        return $this->belongsTo(ScaleNormsVersion::class, 'norm_version_id', 'id');
    }
}
