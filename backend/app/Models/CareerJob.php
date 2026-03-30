<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasContentGovernance;
use App\Models\Concerns\HasIntentRegistry;
use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Career jobs are structured content objects rather than job postings.
 *
 * Expected JSON payload shapes:
 * - salary_json: currency/region/low/median/high/notes
 * - outlook_json: summary/horizon_years/notes
 * - skills_json: core[]/supporting[]
 * - work_contents_json: items[]
 * - growth_path_json: entry/mid/senior/notes
 * - fit_personality_codes_json, mbti_primary_codes_json, mbti_secondary_codes_json: MBTI code arrays
 * - riasec_profile_json: R/I/A/S/E/C numeric profile
 * - big5_targets_json: trait => high|balanced|low targets
 * - iq_eq_notes_json: iq/eq narrative notes
 * - market_demand_json: signal/notes
 */
class CareerJob extends Model
{
    use HasContentGovernance, HasFactory, HasIntentRegistry, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    protected $table = 'career_jobs';

    protected $fillable = [
        'org_id',
        'job_code',
        'slug',
        'locale',
        'title',
        'subtitle',
        'excerpt',
        'hero_kicker',
        'hero_quote',
        'cover_image_url',
        'industry_slug',
        'industry_label',
        'body_md',
        'body_html',
        'salary_json',
        'outlook_json',
        'skills_json',
        'work_contents_json',
        'growth_path_json',
        'fit_personality_codes_json',
        'mbti_primary_codes_json',
        'mbti_secondary_codes_json',
        'riasec_profile_json',
        'big5_targets_json',
        'iq_eq_notes_json',
        'market_demand_json',
        'status',
        'lifecycle_state',
        'lifecycle_changed_at',
        'lifecycle_changed_by_admin_user_id',
        'lifecycle_note',
        'is_public',
        'is_indexable',
        'published_at',
        'scheduled_at',
        'schema_version',
        'sort_order',
        'created_by_admin_user_id',
        'updated_by_admin_user_id',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'salary_json' => 'array',
        'outlook_json' => 'array',
        'skills_json' => 'array',
        'work_contents_json' => 'array',
        'growth_path_json' => 'array',
        'fit_personality_codes_json' => 'array',
        'mbti_primary_codes_json' => 'array',
        'mbti_secondary_codes_json' => 'array',
        'riasec_profile_json' => 'array',
        'big5_targets_json' => 'array',
        'iq_eq_notes_json' => 'array',
        'market_demand_json' => 'array',
        'lifecycle_changed_by_admin_user_id' => 'integer',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'lifecycle_changed_at' => 'datetime',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'sort_order' => 'integer',
        'created_by_admin_user_id' => 'integer',
        'updated_by_admin_user_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CareerJobSection::class, 'job_id', 'id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function seoMeta(): HasOne
    {
        return $this->hasOne(CareerJobSeoMeta::class, 'job_id', 'id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CareerJobRevision::class, 'job_id', 'id')
            ->orderByDesc('revision_no')
            ->orderByDesc('id');
    }

    public function scopePublishedPublic($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_public', true);
    }

    public function scopeIndexable($query)
    {
        return $query->where('is_indexable', true);
    }

    public function scopeForLocale($query, string $locale)
    {
        return $query->where('locale', trim($locale));
    }

    public function scopeForSlug($query, string $slug)
    {
        return $query->where('slug', strtolower(trim($slug)));
    }

    public function scopeForJobCode($query, string $jobCode)
    {
        return $query->where('job_code', strtolower(trim($jobCode)));
    }
}
