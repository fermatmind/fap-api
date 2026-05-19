<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ResearchReport extends Model
{
    use HasFactory, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const REVIEW_DRAFT = 'draft';

    public const REVIEW_RESEARCH = 'research_review';

    public const REVIEW_CLAIM = 'claim_review';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_CHANGES_REQUESTED = 'changes_requested';

    public const PAGE_ENTITY_TYPE = 'research_report';

    public const RESEARCH_TYPES = [
        'salary_turnover',
        'methodology',
        'psychometric_research',
        'market_research',
        'other',
    ];

    protected $table = 'research_reports';

    protected $fillable = [
        'org_id',
        'slug',
        'locale',
        'title',
        'executive_summary',
        'body_md',
        'research_type',
        'methodology',
        'sample_disclaimer',
        'claim_boundary',
        'author_name',
        'reviewer_name',
        'references',
        'downloadable_asset_placeholder',
        'status',
        'review_state',
        'is_public',
        'is_indexable',
        'last_reviewed_at',
        'published_at',
        'seo_title',
        'seo_description',
        'canonical_path',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'references' => 'array',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'last_reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    /**
     * @param  Builder<ResearchReport>  $query
     * @return Builder<ResearchReport>
     */
    public function scopePubliclyReadable(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('review_state', self::REVIEW_APPROVED)
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->where(static function (Builder $publishedAtQuery): void {
                $publishedAtQuery
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }
}
