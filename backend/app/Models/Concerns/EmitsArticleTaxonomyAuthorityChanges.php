<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Events\PublicAuthorityChanged;
use App\Models\Article;
use App\Support\CanonicalTranslationPayloadHash;

/** Only Category/Tag edits affecting existing qualified org-0 Article readers. */
trait EmitsArticleTaxonomyAuthorityChanges
{
    private array $articleAuthorityTargets = [];

    protected static function bootEmitsArticleTaxonomyAuthorityChanges(): void
    {
        static::updating(static function (self $taxonomy): void {
            $taxonomy->articleAuthorityTargets = array_diff(array_keys($taxonomy->getDirty()), ['created_at', 'updated_at']) === []
                ? [] : $taxonomy->qualifiedArticleIds();
        });
        static::updated(static fn (self $taxonomy) => $taxonomy->dispatchArticleAuthorityChanges());
        static::deleting(static function (self $taxonomy): void {
            // Preserve dependent identities before foreign-key/pivot deletion.
            $taxonomy->articleAuthorityTargets = $taxonomy->qualifiedArticleIds();
        });
        static::deleted(static fn (self $taxonomy) => $taxonomy->dispatchArticleAuthorityChanges());
    }

    private function qualifiedArticleIds(): array
    {
        if ((int) $this->org_id !== 0 && (int) $this->getRawOriginal('org_id') !== 0) {
            return [];
        }

        return $this->articles()->withoutGlobalScopes()->where('articles.org_id', 0)
            ->publiclySitemapEligible()->where('articles.llms_eligible', true)->pluck('articles.id')->all();
    }

    private function dispatchArticleAuthorityChanges(): void
    {
        $token = CanonicalTranslationPayloadHash::hash(['taxonomy' => static::class,
            'attributes' => $this->getAttributes(), 'deleted' => ! $this->exists]);
        foreach (Article::withoutGlobalScopes()->where('org_id', 0)->whereIn('id', $this->articleAuthorityTargets)
            ->publiclySitemapEligible()->where('llms_eligible', true)->cursor() as $article) {
            event(new PublicAuthorityChanged('article', (string) $article->id, (string) $article->locale,
                $token, 'authority_revision'));
        }
        $this->articleAuthorityTargets = [];
    }
}
