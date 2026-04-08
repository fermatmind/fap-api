<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceTrace extends CareerFoundationModel
{
    protected $table = 'source_traces';

    protected $casts = [
        'fields_used' => 'array',
        'retrieved_at' => 'datetime',
        'evidence_strength' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function truthMetrics(): HasMany
    {
        return $this->hasMany(OccupationTruthMetric::class, 'source_trace_id', 'id');
    }

    public function importRun(): BelongsTo
    {
        return $this->belongsTo(CareerImportRun::class, 'import_run_id', 'id');
    }
}
