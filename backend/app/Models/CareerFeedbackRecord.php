<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerFeedbackRecord extends CareerImmutableFoundationModel
{
    protected $table = 'career_feedback_records';

    protected $casts = [
        'burnout_checkin' => 'integer',
        'career_satisfaction' => 'integer',
        'switch_urgency' => 'integer',
        'created_at' => 'datetime',
    ];

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

