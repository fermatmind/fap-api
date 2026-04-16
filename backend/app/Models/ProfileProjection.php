<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProfileProjection extends CareerImmutableFoundationModel
{
    protected $table = 'profile_projections';

    protected $casts = [
        'psychometric_axis_coverage' => 'float',
        'projection_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function contextSnapshot(): BelongsTo
    {
        return $this->belongsTo(ContextSnapshot::class, 'context_snapshot_id', 'id');
    }

    public function parentLineages(): HasMany
    {
        return $this->hasMany(ProjectionLineage::class, 'parent_projection_id', 'id');
    }

    public function childLineage(): HasOne
    {
        return $this->hasOne(ProjectionLineage::class, 'child_projection_id', 'id');
    }

    public function recommendationSnapshots(): HasMany
    {
        return $this->hasMany(RecommendationSnapshot::class, 'profile_projection_id', 'id');
    }

    public function feedbackRecords(): HasMany
    {
        return $this->hasMany(CareerFeedbackRecord::class, 'profile_projection_id', 'id');
    }

    public function compileRun(): BelongsTo
    {
        return $this->belongsTo(CareerCompileRun::class, 'compile_run_id', 'id');
    }
}
