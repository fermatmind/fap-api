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

    public function transitionPaths(): HasMany
    {
        return $this->hasMany(TransitionPath::class, 'recommendation_snapshot_id', 'id');
    }
}
