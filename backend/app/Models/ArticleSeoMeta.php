<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOrgScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleSeoMeta extends Model
{
    use HasFactory, HasOrgScope;

    protected $table = 'article_seo_meta';

    protected $fillable = [
        'org_id',
        'article_id',
        'locale',
        'seo_title',
        'seo_description',
        'canonical_url',
        'og_title',
        'og_description',
        'og_image_url',
        'robots',
        'schema_json',
        'is_indexable',
    ];

    protected $casts = [
        'org_id' => 'integer',
        'article_id' => 'integer',
        'schema_json' => 'array',
        'is_indexable' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $seoMeta): void {
            if ((int) $seoMeta->article_id <= 0) {
                return;
            }

            $article = Article::withoutGlobalScopes()->find((int) $seoMeta->article_id);

            if (! $article instanceof Article) {
                return;
            }

            $seoMeta->org_id = (int) $article->org_id;
            $seoMeta->locale = (string) $article->locale;
        });
        static::saved(static function (self $seoMeta): void {
            if (array_diff(array_keys($seoMeta->getDirty()), ['created_at', 'updated_at']) === []) {
                return;
            }
            $article = Article::withoutGlobalScopes()->where('org_id', 0)
                ->where('id', $seoMeta->article_id)->publiclyReadable()->first();
            if (! $article instanceof Article) {
                return;
            }
            event(new \App\Events\PublicAuthorityChanged(
                'article', (string) $article->id, (string) $article->locale,
                \App\Support\CanonicalTranslationPayloadHash::hash($seoMeta->getAttributes()),
                'authority_revision',
            ));
        });

    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id', 'id');
    }
}
