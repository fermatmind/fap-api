<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerShortlistItem extends CareerImmutableFoundationModel
{
    protected $table = 'career_shortlist_items';

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }

    public function contextSnapshot(): BelongsTo
    {
        return $this->belongsTo(ContextSnapshot::class, 'context_snapshot_id', 'id');
    }

    public function profileProjection(): BelongsTo
    {
        return $this->belongsTo(ProfileProjection::class, 'profile_projection_id', 'id');
    }

    public function recommendationSnapshot(): BelongsTo
    {
        return $this->belongsTo(RecommendationSnapshot::class, 'recommendation_snapshot_id', 'id');
    }
}
