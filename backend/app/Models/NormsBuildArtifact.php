<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NormsBuildArtifact extends Model
{
    protected $table = 'norms_build_artifacts';

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'scale_code',
        'norms_version',
        'source_id',
        'source_type',
        'pack_locale',
        'group_id',
        'sample_n_raw',
        'sample_n_kept',
        'filters_applied',
        'compute_spec_hash',
        'output_csv_sha256',
        'output_csv_path',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'sample_n_raw' => 'integer',
        'sample_n_kept' => 'integer',
        'filters_applied' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
