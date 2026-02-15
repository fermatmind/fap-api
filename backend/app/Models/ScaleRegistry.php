<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScaleRegistry extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'scales_registry';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'org_id',
        'primary_slug',
        'slugs_json',
        'driver_type',
        'assessment_driver',
        'default_pack_id',
        'default_region',
        'default_locale',
        'default_dir_version',
        'capabilities_json',
        'view_policy_json',
        'commercial_json',
        'seo_schema_json',
        'seo_i18n_json',
        'content_i18n_json',
        'report_summary_i18n_json',
        'is_public',
        'is_active',
        'is_indexable',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'slugs_json' => 'array',
        'capabilities_json' => 'array',
        'view_policy_json' => 'array',
        'commercial_json' => 'array',
        'seo_schema_json' => 'array',
        'seo_i18n_json' => 'array',
        'content_i18n_json' => 'array',
        'report_summary_i18n_json' => 'array',
        'is_public' => 'boolean',
        'is_active' => 'boolean',
        'is_indexable' => 'boolean',
    ];

    public static function bypassTenantScope(): bool
    {
        return true;
    }

    public function slugs(): HasMany
    {
        return $this->hasMany(ScaleSlug::class, 'scale_code', 'code')
            ->where('org_id', (int) $this->org_id);
    }
}
