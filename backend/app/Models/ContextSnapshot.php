<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContextSnapshot extends CareerImmutableFoundationModel
{
    protected $table = 'context_snapshots';

    protected $casts = [
        'captured_at' => 'datetime',
        'burnout_level' => 'float',
        'switch_urgency' => 'float',
        'risk_tolerance' => 'float',
        'family_constraint_level' => 'float',
        'manager_track_preference' => 'float',
        'time_horizon_months' => 'integer',
        'context_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function currentOccupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'current_occupation_id', 'id');
    }

    public function profileProjections(): HasMany
    {
        return $this->hasMany(ProfileProjection::class, 'context_snapshot_id', 'id');
    }

    public function recommendationSnapshots(): HasMany
    {
        return $this->hasMany(RecommendationSnapshot::class, 'context_snapshot_id', 'id');
    }
}
