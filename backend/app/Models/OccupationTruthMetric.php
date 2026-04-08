<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OccupationTruthMetric extends CareerFoundationModel
{
    protected $table = 'occupation_truth_metrics';

    protected $casts = [
        'median_pay_usd_annual' => 'integer',
        'jobs_2024' => 'integer',
        'projected_jobs_2034' => 'integer',
        'employment_change' => 'integer',
        'outlook_pct_2024_2034' => 'float',
        'ai_exposure' => 'float',
        'effective_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }

    public function sourceTrace(): BelongsTo
    {
        return $this->belongsTo(SourceTrace::class, 'source_trace_id', 'id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(CareerImportRun::class, 'import_run_id', 'id');
    }
}
