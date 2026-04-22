<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class SupportArticle extends Model
{
    use HasFactory, HasOrgScope;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const REVIEW_DRAFT = 'draft';

    public const REVIEW_SUPPORT = 'support_review';

    public const REVIEW_PRODUCT_OR_POLICY = 'product_or_policy_review';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_CHANGES_REQUESTED = 'changes_requested';

    public const CATEGORIES = [
        'orders',
        'reports',
        'payments',
        'refunds',
        'email',
        'account',
        'privacy_data',
        'troubleshooting',
    ];

    public const INTENTS = [
        'lookup_order',
        'recover_report',
        'understand_refund',
        'manage_email_preferences',
        'unsubscribe_email',
        'request_data',
        'delete_data_request_info',
        'contact_support',
    ];

    protected $table = 'support_articles';

    protected $fillable = [
        'org_id',
        'slug',
        'title',
        'summary',
        'body_md',
        'body_html',
        'support_category',
        'support_intent',
        'locale',
        'status',
        'review_state',
        'primary_cta_label',
        'primary_cta_url',
        'related_support_article_ids',
        'related_content_page_ids',
        'last_reviewed_at',
        'published_at',
        'seo_title',
        'seo_description',
        'canonical_path',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'related_support_article_ids' => 'array',
        'related_content_page_ids' => 'array',
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
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('review_state', self::REVIEW_APPROVED);
    }
}
