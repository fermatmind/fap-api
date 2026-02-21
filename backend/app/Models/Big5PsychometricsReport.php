<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Big5PsychometricsReport extends Model
{
    protected $table = 'big5_psychometrics_reports';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'scale_code',
        'locale',
        'region',
        'norms_version',
        'time_window',
        'sample_n',
        'metrics_json',
        'generated_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'sample_n' => 'integer',
        'metrics_json' => 'array',
        'generated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
