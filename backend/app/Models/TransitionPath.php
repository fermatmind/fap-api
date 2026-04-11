<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Career\Transition\TransitionPathPayload;
use App\Domain\Career\Transition\TransitionPathType;
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

    public function transitionPathType(): ?TransitionPathType
    {
        return TransitionPathType::tryNormalize($this->path_type);
    }

    public function normalizedPathPayload(): TransitionPathPayload
    {
        return TransitionPathPayload::from($this->path_payload);
    }

    public function hasValidPathPayloadShape(): bool
    {
        return $this->path_payload === null || is_array($this->path_payload);
    }
}
