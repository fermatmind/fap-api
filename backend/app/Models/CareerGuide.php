<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CareerGuide extends Model
{
    use HasFactory, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    protected $table = 'career_guides';

    protected $fillable = [
        'org_id',
        'guide_code',
        'slug',
        'locale',
        'title',
        'excerpt',
        'category_slug',
        'body_md',
        'body_html',
        'related_industry_slugs_json',
        'status',
        'lifecycle_state',
        'lifecycle_changed_at',
        'lifecycle_changed_by_admin_user_id',
        'lifecycle_note',
        'is_public',
        'is_indexable',
        'sort_order',
        'published_at',
        'scheduled_at',
        'schema_version',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'lifecycle_changed_by_admin_user_id' => 'integer',
        'related_industry_slugs_json' => 'array',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'lifecycle_changed_at' => 'datetime',
        'sort_order' => 'integer',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function seoMeta(): HasOne
    {
        return $this->hasOne(CareerGuideSeoMeta::class, 'career_guide_id', 'id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CareerGuideRevision::class, 'career_guide_id', 'id')
            ->orderByDesc('revision_no')
            ->orderByDesc('id');
    }

    public function relatedJobs(): BelongsToMany
    {
        return $this->belongsToMany(
            CareerJob::class,
            'career_guide_job_map',
            'career_guide_id',
            'career_job_id'
        )
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('career_jobs.id');
    }

    public function relatedArticles(): BelongsToMany
    {
        return $this->belongsToMany(
            Article::class,
            'career_guide_article_map',
            'career_guide_id',
            'article_id'
        )
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('articles.id');
    }

    public function relatedPersonalityProfiles(): BelongsToMany
    {
        return $this->belongsToMany(
            PersonalityProfile::class,
            'career_guide_personality_map',
            'career_guide_id',
            'personality_profile_id'
        )
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('personality_profiles.id');
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

    public function scopeForGuideCode($query, string $guideCode)
    {
        return $query->where('guide_code', strtolower(trim($guideCode)));
    }
}
