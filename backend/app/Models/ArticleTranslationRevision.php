<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleTranslationRevision extends Model
{
    use HasFactory, HasOrgScope;

    public const STATUS_SOURCE = Article::TRANSLATION_STATUS_SOURCE;

    public const STATUS_MACHINE_DRAFT = Article::TRANSLATION_STATUS_MACHINE_DRAFT;

    public const STATUS_HUMAN_REVIEW = Article::TRANSLATION_STATUS_HUMAN_REVIEW;

    public const STATUS_APPROVED = Article::TRANSLATION_STATUS_APPROVED;

    public const STATUS_PUBLISHED = Article::TRANSLATION_STATUS_PUBLISHED;

    public const STATUS_STALE = Article::TRANSLATION_STATUS_STALE;

    public const STATUS_ARCHIVED = Article::TRANSLATION_STATUS_ARCHIVED;

    protected $table = 'article_translation_revisions';

    protected $fillable = [
        'org_id',
        'article_id',
        'source_article_id',
        'translation_group_id',
        'locale',
        'source_locale',
        'revision_number',
        'revision_status',
        'source_version_hash',
        'translated_from_version_hash',
        'supersedes_revision_id',
        'title',
        'excerpt',
        'content_md',
        'seo_title',
        'seo_description',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'approved_at',
        'published_at',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'article_id' => 'integer',
        'source_article_id' => 'integer',
        'revision_number' => 'integer',
        'supersedes_revision_id' => 'integer',
        'created_by' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id', 'id');
    }

    public function sourceArticle(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'source_article_id', 'id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_revision_id', 'id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_revision_id', 'id');
    }

    public function isSourceRevision(): bool
    {
        return $this->revision_status === self::STATUS_SOURCE
            && (int) $this->article_id === (int) $this->source_article_id;
    }

    public function isStale(?Article $sourceArticle = null): bool
    {
        if ($this->isSourceRevision()) {
            return false;
        }

        $sourceArticle ??= $this->sourceArticle;
        if (! $sourceArticle instanceof Article) {
            return false;
        }

        $sourceArticle->loadMissing('workingRevision');
        $sourceHash = $sourceArticle->workingRevision instanceof self && filled($sourceArticle->workingRevision->source_version_hash)
            ? (string) $sourceArticle->workingRevision->source_version_hash
            : $sourceArticle->source_version_hash;

        return filled($sourceHash)
            && filled($this->translated_from_version_hash)
            && ! hash_equals((string) $sourceHash, (string) $this->translated_from_version_hash);
    }

    public function isPublishableForArticle(?Article $article = null): bool
    {
        $status = (string) $this->revision_status;

        if (in_array($status, [self::STATUS_APPROVED, self::STATUS_PUBLISHED], true)) {
            return true;
        }

        if ($status !== self::STATUS_SOURCE) {
            return false;
        }

        $article ??= $this->article;

        return $article instanceof Article
            && $article->isSourceArticle()
            && (int) $this->article_id === (int) $article->id
            && (int) $this->source_article_id === (int) $article->id;
    }

    /**
     * @return array<int, string>
     */
    public static function statuses(): array
    {
        return Article::translationStatuses();
    }
}
