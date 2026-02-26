<?php

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Explicit tenant-scope bypass for system queries that must resolve
     * org-local + global records in one read path.
     *
     * @param  list<int>  $orgIds
     */
    public static function queryByOrgWhitelist(array $orgIds): Builder
    {
        $normalizedOrgIds = self::normalizeOrgWhitelist($orgIds);

        $query = static::query()->withoutGlobalScope(TenantScope::class);
        if ($normalizedOrgIds === []) {
            return $query->where('org_id', -1);
        }

        return $query->whereIn('org_id', $normalizedOrgIds);
    }

    public function slugs(): HasMany
    {
        return $this->hasMany(ScaleSlug::class, 'scale_code', 'code')
            ->where('org_id', (int) $this->org_id);
    }

    /**
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    private static function normalizeOrgWhitelist(array $orgIds): array
    {
        $set = [];
        foreach ($orgIds as $orgId) {
            $normalized = (int) $orgId;
            if ($normalized < 0) {
                continue;
            }
            $set[$normalized] = true;
        }

        return array_values(array_keys($set));
    }
}
