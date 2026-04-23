<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    public const TRANSLATION_STATUS_SOURCE = 'source';

    public const TRANSLATION_STATUS_DRAFT = 'draft';

    public const TRANSLATION_STATUS_MACHINE_DRAFT = 'machine_draft';

    public const TRANSLATION_STATUS_HUMAN_REVIEW = 'human_review';

    public const TRANSLATION_STATUS_APPROVED = 'approved';

    public const TRANSLATION_STATUS_PUBLISHED = 'published';

    public const TRANSLATION_STATUS_STALE = 'stale';

    public const TRANSLATION_STATUS_ARCHIVED = 'archived';

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
        'translation_group_id',
        'source_locale',
        'translation_status',
        'source_content_id',
        'source_version_hash',
        'translated_from_version_hash',
        'working_revision_id',
        'published_revision_id',
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
        'source_content_id' => 'integer',
        'working_revision_id' => 'integer',
        'published_revision_id' => 'integer',
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

    protected static function booted(): void
    {
        self::saving(function (self $article): void {
            $article->translation_status = $article->translation_status ?: self::TRANSLATION_STATUS_SOURCE;

            if ($article->translation_status === self::TRANSLATION_STATUS_SOURCE) {
                $article->source_locale = $article->locale;
                $article->source_content_id = null;
                $article->translated_from_version_hash = null;
            } elseif (! filled($article->source_locale)) {
                $article->source_locale = $article->source_locale ?: 'zh-CN';
            }

            if (! filled($article->translation_group_id)) {
                $article->translation_group_id = filled($article->source_content_id)
                    ? 'support-'.$article->source_content_id
                    : (string) Str::uuid();
            }

            $article->source_version_hash = $article->computeSourceVersionHash();
        });
    }

    public function scopePublished($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('review_state', self::REVIEW_APPROVED);
    }

    public function isSourceContent(): bool
    {
        return $this->translation_status === self::TRANSLATION_STATUS_SOURCE
            && $this->source_content_id === null
            && (string) $this->locale === (string) $this->source_locale;
    }

    public function isTranslationStale(?self $source = null): bool
    {
        if ($this->isSourceContent()) {
            return false;
        }

        $source ??= self::query()->withoutGlobalScopes()->find($this->source_content_id);
        if (! $source instanceof self) {
            return false;
        }

        return filled($source->source_version_hash)
            && filled($this->translated_from_version_hash)
            && ! hash_equals((string) $source->source_version_hash, (string) $this->translated_from_version_hash);
    }

    public function workingRevision(): BelongsTo
    {
        return $this->belongsTo(CmsTranslationRevision::class, 'working_revision_id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(CmsTranslationRevision::class, 'published_revision_id');
    }

    private function computeSourceVersionHash(): string
    {
        return hash('sha256', json_encode([
            'slug' => (string) $this->slug,
            'locale' => (string) $this->locale,
            'title' => (string) $this->title,
            'summary' => (string) ($this->summary ?? ''),
            'body_md' => (string) ($this->body_md ?? ''),
            'body_html' => (string) ($this->body_html ?? ''),
            'support_category' => (string) $this->support_category,
            'support_intent' => (string) $this->support_intent,
            'seo_title' => (string) ($this->seo_title ?? ''),
            'seo_description' => (string) ($this->seo_description ?? ''),
            'canonical_path' => (string) ($this->canonical_path ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
