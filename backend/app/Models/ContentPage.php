<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class ContentPage extends Model
{
    use HasFactory, HasOrgScope;

    public const KIND_COMPANY = 'company';

    public const KIND_POLICY = 'policy';

    public const KIND_HELP = 'help';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const PAGE_TYPES = [
        'methodology',
        'science',
        'boundary',
        'policy',
        'privacy',
        'terms',
        'refund',
        'company',
        'trust',
        'about',
        'support_static',
    ];

    public const REVIEW_STATES = [
        'draft',
        'owner_review',
        'legal_review',
        'science_review',
        'company_review',
        'approved',
        'changes_requested',
    ];

    protected $table = 'content_pages';

    protected $fillable = [
        'org_id',
        'slug',
        'path',
        'kind',
        'page_type',
        'title',
        'kicker',
        'summary',
        'template',
        'animation_profile',
        'locale',
        'published_at',
        'source_updated_at',
        'effective_at',
        'source_doc',
        'is_public',
        'is_indexable',
        'review_state',
        'owner',
        'legal_review_required',
        'science_review_required',
        'last_reviewed_at',
        'headings_json',
        'content_md',
        'content_html',
        'seo_title',
        'meta_description',
        'seo_description',
        'canonical_path',
        'status',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'published_at' => 'date',
        'source_updated_at' => 'date',
        'effective_at' => 'date',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'legal_review_required' => 'boolean',
        'science_review_required' => 'boolean',
        'last_reviewed_at' => 'datetime',
        'headings_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function allowOrgZeroContext(): bool
    {
        return true;
    }

    public function scopePublishedPublic($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_public', true);
    }
}
