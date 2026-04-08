<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Occupation extends CareerFoundationModel
{
    protected $table = 'occupations';

    protected $casts = [
        'structural_stability' => 'float',
        'task_prototype_signature' => 'array',
        'market_semantics_gap' => 'float',
        'regulatory_divergence' => 'float',
        'toolchain_divergence' => 'float',
        'skill_gap_threshold' => 'float',
        'trust_inheritance_scope' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function family(): BelongsTo
    {
        return $this->belongsTo(OccupationFamily::class, 'family_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(OccupationAlias::class, 'occupation_id', 'id');
    }

    public function crosswalks(): HasMany
    {
        return $this->hasMany(OccupationCrosswalk::class, 'occupation_id', 'id');
    }

    public function truthMetrics(): HasMany
    {
        return $this->hasMany(OccupationTruthMetric::class, 'occupation_id', 'id');
    }

    public function skillGraphs(): HasMany
    {
        return $this->hasMany(OccupationSkillGraph::class, 'occupation_id', 'id');
    }

    public function trustManifests(): HasMany
    {
        return $this->hasMany(TrustManifest::class, 'occupation_id', 'id');
    }

    public function editorialPatches(): HasMany
    {
        return $this->hasMany(EditorialPatch::class, 'occupation_id', 'id');
    }

    public function indexStates(): HasMany
    {
        return $this->hasMany(IndexState::class, 'occupation_id', 'id');
    }

    public function recommendationSnapshots(): HasMany
    {
        return $this->hasMany(RecommendationSnapshot::class, 'occupation_id', 'id');
    }

    public function sourceTraces(): HasMany
    {
        return $this->hasManyThrough(
            SourceTrace::class,
            OccupationTruthMetric::class,
            'occupation_id',
            'id',
            'id',
            'source_trace_id'
        );
    }
}
