<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Eq60PsychometricsReport extends Model
{
    protected $table = 'eq60_psychometrics_reports';

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
        'metrics_json' => 'array',
        'sample_n' => 'integer',
        'generated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
