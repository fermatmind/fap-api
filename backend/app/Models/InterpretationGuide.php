<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class InterpretationGuide extends Model
{
    use HasFactory, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const REVIEW_DRAFT = 'draft';

    public const REVIEW_CONTENT = 'content_review';

    public const REVIEW_SCIENCE_OR_PRODUCT = 'science_or_product_review';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_CHANGES_REQUESTED = 'changes_requested';

    public const TEST_FAMILIES = [
        'general',
        'mbti',
        'big_five',
        'enneagram',
        'riasec',
    ];

    public const RESULT_CONTEXTS = [
        'how_to_read',
        'score_meaning',
        'dimension_explanation',
        'type_profile',
        'free_vs_full',
        'report_section',
        'limitations',
    ];

    protected $table = 'interpretation_guides';

    protected $fillable = [
        'org_id',
        'slug',
        'title',
        'summary',
        'body_md',
        'body_html',
        'test_family',
        'result_context',
        'audience',
        'locale',
        'status',
        'review_state',
        'related_guide_ids',
        'related_methodology_page_ids',
        'last_reviewed_at',
        'published_at',
        'seo_title',
        'seo_description',
        'canonical_path',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'related_guide_ids' => 'array',
        'related_methodology_page_ids' => 'array',
        'last_reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }
}
