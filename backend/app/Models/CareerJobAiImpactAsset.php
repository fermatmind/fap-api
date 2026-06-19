<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerJobAiImpactAsset extends CareerFoundationModel
{
    public const ASSET_VERSION_V5 = 'career_risk_future_ai_impact_v5';

    public const STATUS_STAGING_PREVIEW = 'staging_preview';

    public const STATUS_EDITORIAL_REVIEW = 'editorial_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PRODUCTION_IMPORTED = 'production_imported';

    protected $table = 'career_job_ai_impact_assets';

    protected $casts = [
        'preview_allowlisted' => 'boolean',
        'asset_payload_json' => 'array',
        'sources_json' => 'array',
        'evidence_used_json' => 'array',
        'derived_from_synthesis_json' => 'array',
        'audit_fields_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }
}
