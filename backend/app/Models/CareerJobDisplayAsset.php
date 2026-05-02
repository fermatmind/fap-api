<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerJobDisplayAsset extends CareerFoundationModel
{
    protected $table = 'career_job_display_assets';

    protected $casts = [
        'component_order_json' => 'array',
        'page_payload_json' => 'array',
        'seo_payload_json' => 'array',
        'sources_json' => 'array',
        'structured_data_json' => 'array',
        'implementation_contract_json' => 'array',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'id');
    }
}
