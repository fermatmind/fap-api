<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    public const TRANSLATION_STATUS_SOURCE = 'source';

    public const TRANSLATION_STATUS_DRAFT = 'draft';

    public const TRANSLATION_STATUS_MACHINE_DRAFT = 'machine_draft';

    public const TRANSLATION_STATUS_HUMAN_REVIEW = 'human_review';

    public const TRANSLATION_STATUS_APPROVED = 'approved';

    public const TRANSLATION_STATUS_PUBLISHED = 'published';

    public const TRANSLATION_STATUS_STALE = 'stale';

    public const TRANSLATION_STATUS_ARCHIVED = 'archived';

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
        'translation_group_id',
        'source_locale',
        'translation_status',
        'source_content_id',
        'source_version_hash',
        'translated_from_version_hash',
        'working_revision_id',
        'published_revision_id',
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
        'source_content_id' => 'integer',
        'working_revision_id' => 'integer',
        'published_revision_id' => 'integer',
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

    protected static function booted(): void
    {
        self::saving(function (self $page): void {
            $page->translation_status = $page->translation_status ?: self::TRANSLATION_STATUS_SOURCE;

            if ($page->translation_status === self::TRANSLATION_STATUS_SOURCE) {
                $page->source_locale = $page->locale;
                $page->source_content_id = null;
                $page->translated_from_version_hash = null;
            } elseif (! filled($page->source_locale)) {
                $page->source_locale = $page->source_locale ?: 'zh-CN';
            }

            if (! filled($page->translation_group_id)) {
                $page->translation_group_id = filled($page->source_content_id)
                    ? 'content-page-'.$page->source_content_id
                    : (string) Str::uuid();
            }

            $page->source_version_hash = $page->computeSourceVersionHash();
        });
    }

    public function scopePublishedPublic($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_public', true);
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
            'path' => (string) $this->path,
            'locale' => (string) $this->locale,
            'kind' => (string) $this->kind,
            'page_type' => (string) ($this->page_type ?? ''),
            'title' => (string) $this->title,
            'kicker' => (string) ($this->kicker ?? ''),
            'summary' => (string) ($this->summary ?? ''),
            'content_md' => (string) ($this->content_md ?? ''),
            'content_html' => (string) ($this->content_html ?? ''),
            'seo_title' => (string) ($this->seo_title ?? ''),
            'seo_description' => (string) ($this->seo_description ?? ''),
            'meta_description' => (string) ($this->meta_description ?? ''),
            'canonical_path' => (string) ($this->canonical_path ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
