<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerCompileRun extends CareerFoundationModel
{
    protected $table = 'career_compile_runs';

    protected $casts = [
        'dry_run' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'subjects_seen' => 'integer',
        'snapshots_created' => 'integer',
        'snapshots_skipped' => 'integer',
        'snapshots_failed' => 'integer',
        'output_counts' => 'array',
        'error_summary' => 'array',
        'meta' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(CareerImportRun::class, 'import_run_id', 'id');
    }
}
