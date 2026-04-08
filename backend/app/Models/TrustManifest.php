<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrustManifest extends CareerImmutableFoundationModel
{
    protected $table = 'trust_manifests';

    protected $casts = [
        'locale_context' => 'array',
        'methodology' => 'array',
        'reviewed_at' => 'datetime',
        'ai_assistance' => 'array',
        'quality' => 'array',
        'last_substantive_update_at' => 'datetime',
        'next_review_due_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }
}
