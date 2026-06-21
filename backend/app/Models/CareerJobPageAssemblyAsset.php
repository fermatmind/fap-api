<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerJobPageAssemblyAsset extends CareerFoundationModel
{
    public const ASSET_VERSION_V1 = 'career_page_assembly_v1';

    public const STATUS_STAGING_PREVIEW = 'staging_preview';

    protected $table = 'career_job_page_assembly_assets';

    protected $casts = [
        'preview_allowlisted' => 'boolean',
        'asset_payload_json' => 'array',
        'block_refs_json' => 'array',
        'audit_fields_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }
}
