<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationSnapshot extends CareerImmutableFoundationModel
{
    protected $table = 'recommendation_snapshots';

    protected $casts = [
        'snapshot_payload' => 'array',
        'compiled_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function profileProjection(): BelongsTo
    {
        return $this->belongsTo(ProfileProjection::class, 'profile_projection_id', 'id');
    }

    public function contextSnapshot(): BelongsTo
    {
        return $this->belongsTo(ContextSnapshot::class, 'context_snapshot_id', 'id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }

    public function trustManifest(): BelongsTo
    {
        return $this->belongsTo(TrustManifest::class, 'trust_manifest_id', 'id');
    }

    public function indexState(): BelongsTo
    {
        return $this->belongsTo(IndexState::class, 'index_state_id', 'id');
    }

    public function truthMetric(): BelongsTo
    {
        return $this->belongsTo(OccupationTruthMetric::class, 'truth_metric_id', 'id');
    }

    public function compileRun(): BelongsTo
    {
        return $this->belongsTo(CareerCompileRun::class, 'compile_run_id', 'id');
    }

    public function transitionPaths(): HasMany
    {
        return $this->hasMany(TransitionPath::class, 'recommendation_snapshot_id', 'id');
    }

    public function feedbackRecords(): HasMany
    {
        return $this->hasMany(CareerFeedbackRecord::class, 'recommendation_snapshot_id', 'id');
    }
}
