<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Article extends Model
{
    use HasFactory, HasOrgScope;

    public const TRANSLATION_STATUS_SOURCE = 'source';

    public const TRANSLATION_STATUS_MACHINE_DRAFT = 'machine_draft';

    public const TRANSLATION_STATUS_HUMAN_REVIEW = 'human_review';

    public const TRANSLATION_STATUS_APPROVED = 'approved';

    public const TRANSLATION_STATUS_PUBLISHED = 'published';

    public const TRANSLATION_STATUS_STALE = 'stale';

    public const TRANSLATION_STATUS_ARCHIVED = 'archived';

    protected $table = 'articles';

    protected $fillable = [
        'org_id',
        'category_id',
        'author_admin_user_id',
        'author_name',
        'reviewer_name',
        'reading_minutes',
        'slug',
        'locale',
        'translation_group_id',
        'source_locale',
        'translation_status',
        'translated_from_article_id',
        'source_article_id',
        'source_version_hash',
        'translated_from_version_hash',
        'working_revision_id',
        'published_revision_id',
        'title',
        'excerpt',
        'content_md',
        'content_html',
        'cover_image_url',
        'cover_image_alt',
        'cover_image_width',
        'cover_image_height',
        'cover_image_variants',
        'related_test_slug',
        'voice',
        'voice_order',
        'status',
        'lifecycle_state',
        'lifecycle_changed_at',
        'lifecycle_changed_by_admin_user_id',
        'lifecycle_note',
        'is_public',
        'is_indexable',
        'published_at',
        'scheduled_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'category_id' => 'integer',
        'author_admin_user_id' => 'integer',
        'reading_minutes' => 'integer',
        'cover_image_width' => 'integer',
        'cover_image_height' => 'integer',
        'cover_image_variants' => 'array',
        'voice_order' => 'integer',
        'translated_from_article_id' => 'integer',
        'source_article_id' => 'integer',
        'working_revision_id' => 'integer',
        'published_revision_id' => 'integer',
        'lifecycle_changed_by_admin_user_id' => 'integer',
        'is_public' => 'boolean',
        'is_indexable' => 'boolean',
        'lifecycle_changed_at' => 'datetime',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Article $article): void {
            $article->translation_status = $article->translation_status ?: self::TRANSLATION_STATUS_SOURCE;

            if ($article->translation_status === self::TRANSLATION_STATUS_SOURCE) {
                $article->source_locale = $article->locale;
                $article->translated_from_article_id = null;
                $article->source_article_id = null;
                $article->translated_from_version_hash = null;
            } elseif (! filled($article->source_locale)) {
                $article->source_locale = $article->sourceCanonical?->source_locale
                    ?: $article->translatedFrom?->source_locale
                    ?: $article->locale;
            }

            if ($article->translation_status !== self::TRANSLATION_STATUS_SOURCE
                && ! filled($article->source_article_id)
                && filled($article->translated_from_article_id)) {
                $article->source_article_id = $article->translated_from_article_id;
            }

            if (! filled($article->translation_group_id)) {
                $article->translation_group_id = $article->sourceCanonical?->translation_group_id
                    ?: $article->translatedFrom?->translation_group_id
                    ?: (string) Str::uuid();
            }

            $article->source_version_hash = $article->computeSourceVersionHash();
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id', 'id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            ArticleTag::class,
            'article_tag_map',
            'article_id',
            'tag_id'
        );
    }

    public function scopePublished($query)
    {
        return $query
            ->where('status', 'published')
            ->where('is_public', true);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class, 'article_id', 'id');
    }

    public function translationRevisions(): HasMany
    {
        return $this->hasMany(ArticleTranslationRevision::class, 'article_id', 'id');
    }

    public function sourceCanonical(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_article_id', 'id');
    }

    public function workingRevision(): BelongsTo
    {
        return $this->belongsTo(ArticleTranslationRevision::class, 'working_revision_id', 'id');
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(ArticleTranslationRevision::class, 'published_revision_id', 'id');
    }

    public function translatedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'translated_from_article_id', 'id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(self::class, 'translated_from_article_id', 'id');
    }

    public function canonicalTranslations(): HasMany
    {
        return $this->hasMany(self::class, 'source_article_id', 'id');
    }

    public function seoMeta(): HasOne
    {
        return $this->hasOne(ArticleSeoMeta::class, 'article_id', 'id');
    }

    public static function findBySlug(string $slug, string $locale = 'en'): ?self
    {
        return static::query()
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first();
    }

    public function isSourceArticle(): bool
    {
        return $this->translation_status === self::TRANSLATION_STATUS_SOURCE
            && $this->translated_from_article_id === null
            && $this->source_article_id === null
            && (string) $this->locale === (string) $this->source_locale;
    }

    public function isTranslationArticle(): bool
    {
        return ! $this->isSourceArticle();
    }

    public function isTranslationStale(?self $source = null): bool
    {
        if ($this->isSourceArticle()) {
            return false;
        }

        $workingRevision = $this->workingRevision;
        if ($workingRevision instanceof ArticleTranslationRevision) {
            $sourceHash = $this->currentSourceVersionHash();

            return filled($sourceHash)
                && filled($workingRevision->translated_from_version_hash)
                && ! hash_equals((string) $sourceHash, (string) $workingRevision->translated_from_version_hash);
        }

        $source ??= $this->sourceArticle();
        if (! $source instanceof self) {
            return false;
        }

        return filled($source->source_version_hash)
            && filled($this->translated_from_version_hash)
            && ! hash_equals((string) $source->source_version_hash, (string) $this->translated_from_version_hash);
    }

    public function sourceArticle(): ?self
    {
        if ($this->isSourceArticle()) {
            return $this;
        }

        return $this->sourceCanonical ?: $this->translatedFrom;
    }

    public function currentSourceVersionHash(): ?string
    {
        $source = $this->sourceArticle();

        if (! $source instanceof self) {
            return null;
        }

        $source->loadMissing('workingRevision');
        if ($source->workingRevision instanceof ArticleTranslationRevision
            && filled($source->workingRevision->source_version_hash)) {
            return (string) $source->workingRevision->source_version_hash;
        }

        return $source->source_version_hash;
    }

    public function computeSourceVersionHash(): string
    {
        return self::sourceVersionHashFromPayload([
            'locale' => $this->locale,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'content_md' => $this->content_md,
            'content_html' => $this->content_html,
            'cover_image_alt' => $this->cover_image_alt,
            'related_test_slug' => $this->related_test_slug,
            'voice' => $this->voice,
            'voice_order' => $this->voice_order,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sourceVersionHashFromPayload(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<int, string>
     */
    public static function translationStatuses(): array
    {
        return [
            self::TRANSLATION_STATUS_SOURCE,
            self::TRANSLATION_STATUS_MACHINE_DRAFT,
            self::TRANSLATION_STATUS_HUMAN_REVIEW,
            self::TRANSLATION_STATUS_APPROVED,
            self::TRANSLATION_STATUS_PUBLISHED,
            self::TRANSLATION_STATUS_STALE,
            self::TRANSLATION_STATUS_ARCHIVED,
        ];
    }
}
