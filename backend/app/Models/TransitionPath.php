<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransitionPath extends CareerImmutableFoundationModel
{
    protected $table = 'transition_paths';

    protected $casts = [
        'path_payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function recommendationSnapshot(): BelongsTo
    {
        return $this->belongsTo(RecommendationSnapshot::class, 'recommendation_snapshot_id', 'id');
    }

    public function fromOccupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'from_occupation_id', 'id');
    }

    public function toOccupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'to_occupation_id', 'id');
    }
}
