<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class CareerImportRun extends CareerFoundationModel
{
    protected $table = 'career_import_runs';

    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'rows_seen' => 'integer',
        'rows_accepted' => 'integer',
        'rows_skipped' => 'integer',
        'rows_failed' => 'integer',
        'output_counts' => 'array',
        'error_summary' => 'array',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function compileRuns(): HasMany
    {
        return $this->hasMany(CareerCompileRun::class, 'import_run_id', 'id');
    }
}
