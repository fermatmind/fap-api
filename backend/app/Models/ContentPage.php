<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use App\Services\Cms\ContentPagePublishGate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public const CLAIM_GATE_STATUSES = [
        'not_reviewed',
        'passed',
        'failed',
        'not_applicable',
    ];

    public const SCIENCE_CONTROLLED_SLUGS = [
        'science',
        'method-boundaries',
        'item-design-notes',
        'reliability-validity',
        'data-privacy',
        'common-misconceptions',
    ];

    public const SCIENCE_CONTROLLED_PAGE_TYPES = [
        'science',
        'methodology',
        'boundary',
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
        'support_contact',
        'policy_version',
        'reviewer',
        'faq_items',
        'schema_enabled',
        'publish_allowed',
        'operator_approval_required',
        'operator_approved_at',
        'claim_gate_status',
        'forbidden_claims',
        'faq_schema_eligible',
        'schema_eligibility_reviewed_at',
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
        'faq_items' => 'array',
        'schema_enabled' => 'boolean',
        'publish_allowed' => 'boolean',
        'operator_approval_required' => 'boolean',
        'operator_approved_at' => 'datetime',
        'forbidden_claims' => 'array',
        'faq_schema_eligible' => 'boolean',
        'schema_eligibility_reviewed_at' => 'datetime',
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

            app(ContentPagePublishGate::class)->assertPasses($page);

            $page->source_version_hash = $page->computeSourceVersionHash();
        });
    }

    public function scopePublishedPublic($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->where('is_public', true);
    }

    public function scopePubliclyReadable($query)
    {
        return $query
            ->publishedPublic()
            ->where(static function ($publishedAtQuery): void {
                $publishedAtQuery
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->where(static function ($query): void {
                $query
                    ->where(static function ($standardPage): void {
                        $standardPage
                            ->whereNotIn('slug', self::SCIENCE_CONTROLLED_SLUGS)
                            ->where(static function ($pageTypeQuery): void {
                                $pageTypeQuery
                                    ->whereNull('page_type')
                                    ->orWhereNotIn('page_type', self::SCIENCE_CONTROLLED_PAGE_TYPES);
                            })
                            ->where(static function ($reviewQuery): void {
                                $reviewQuery
                                    ->whereNull('science_review_required')
                                    ->orWhere('science_review_required', false);
                            });
                    })
                    ->orWhere(static function ($controlledPage): void {
                        $controlledPage
                            ->where(static function ($scienceQuery): void {
                                $scienceQuery
                                    ->whereIn('slug', self::SCIENCE_CONTROLLED_SLUGS)
                                    ->orWhereIn('page_type', self::SCIENCE_CONTROLLED_PAGE_TYPES)
                                    ->orWhere('science_review_required', true);
                            })
                            ->where('publish_allowed', true)
                            ->where('review_state', 'approved')
                            ->where(static function ($legalQuery): void {
                                $legalQuery
                                    ->whereNull('legal_review_required')
                                    ->orWhere('legal_review_required', false);
                            })
                            ->where(static function ($scienceReviewQuery): void {
                                $scienceReviewQuery
                                    ->whereNull('science_review_required')
                                    ->orWhere('science_review_required', false);
                            })
                            ->where('claim_gate_status', 'passed')
                            ->where(static function ($claimsQuery): void {
                                $claimsQuery
                                    ->whereNull('forbidden_claims')
                                    ->orWhere('forbidden_claims', '[]')
                                    ->orWhereJsonLength('forbidden_claims', 0);
                            })
                            ->where(static function ($operatorQuery): void {
                                $operatorQuery
                                    ->where('operator_approval_required', false)
                                    ->orWhereNotNull('operator_approved_at');
                            })
                            ->where(static function ($schemaQuery): void {
                                $schemaQuery
                                    ->where('schema_enabled', false)
                                    ->orWhere(static function ($schemaReviewQuery): void {
                                        $schemaReviewQuery
                                            ->where('faq_schema_eligible', true)
                                            ->whereNotNull('schema_eligibility_reviewed_at');
                                    });
                            });
                    });
            });
    }

    public function scopePubliclyIndexable($query)
    {
        return $query
            ->publiclyReadable()
            ->where('is_indexable', true);
    }

    public function isScienceControlledPage(): bool
    {
        return in_array((string) $this->slug, self::SCIENCE_CONTROLLED_SLUGS, true)
            || in_array((string) $this->page_type, self::SCIENCE_CONTROLLED_PAGE_TYPES, true)
            || (bool) $this->science_review_required;
    }

    public function passesPublicReadinessGate(): bool
    {
        if ((string) $this->status !== self::STATUS_PUBLISHED || ! (bool) $this->is_public) {
            return false;
        }

        if ($this->published_at !== null && $this->published_at->isFuture()) {
            return false;
        }

        if (! $this->isScienceControlledPage()) {
            return true;
        }

        $forbiddenClaims = is_array($this->forbidden_claims) ? array_values($this->forbidden_claims) : [];

        return (bool) $this->publish_allowed
            && (string) $this->review_state === 'approved'
            && ! (bool) $this->legal_review_required
            && ! (bool) $this->science_review_required
            && (string) $this->claim_gate_status === 'passed'
            && $forbiddenClaims === []
            && (! (bool) $this->operator_approval_required || $this->operator_approved_at !== null)
            && (! (bool) $this->schema_enabled || ((bool) $this->faq_schema_eligible && $this->schema_eligibility_reviewed_at !== null));
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
            'support_contact' => (string) ($this->support_contact ?? ''),
            'policy_version' => (string) ($this->policy_version ?? ''),
            'reviewer' => (string) ($this->reviewer ?? ''),
            'faq_items' => $this->faq_items ?? [],
            'schema_enabled' => (bool) ($this->schema_enabled ?? false),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }
}
