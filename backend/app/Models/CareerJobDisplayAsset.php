<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerJobDisplayAsset extends CareerFoundationModel
{
    /**
     * Compatibility columns are deliberately absent. They remain in the schema
     * until the delayed contract migration, but current application code must
     * neither read nor serialize them.
     *
     * @var list<string>
     */
    public const RUNTIME_COLUMNS = [
        'id',
        'occupation_id',
        'canonical_slug',
        'surface_version',
        'asset_type',
        'asset_role',
        'status',
        'component_order_json',
        'page_payload_json',
        'seo_payload_json',
        'sources_json',
        'structured_data_json',
        'implementation_contract_json',
        'metadata_json',
        'import_run_id',
        'created_at',
        'updated_at',
    ];

    protected $table = 'career_job_display_assets';

    protected $hidden = ['asset_version', 'template_version'];

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

    /** @param Builder<self> $query */
    public function scopeRuntimeColumns(Builder $query): void
    {
        $query->select(self::RUNTIME_COLUMNS);
    }
}
