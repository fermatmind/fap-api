<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Support;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\Cms\ArticleMaterialDecisionService;
use Illuminate\Support\Facades\DB;

final class ArticleSeoMetaWorkspace
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function save(Article $article, array $payload): void
    {
        $seoPayload = $this->normalize($payload);
        $hasSeoValues = array_filter($seoPayload, static fn (mixed $value): bool => filled($value)) !== [];

        if (! $hasSeoValues && ! $article->seoMeta instanceof ArticleSeoMeta) {
            return;
        }

        // Ops resolves the editable article independently of the selected tenant.
        // Keep the SEO lookup bound to that article's exact ownership and locale.
        DB::transaction(function () use ($article, $seoPayload): void {
            $locked = Article::withoutGlobalScopes()->where('org_id', $article->org_id)
                ->lockForUpdate()->findOrFail($article->id);
            ArticleSeoMeta::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'org_id' => (int) $locked->org_id,
                    'article_id' => (int) $locked->id,
                    'locale' => (string) $locked->locale,
                ],
                array_merge($seoPayload, [
                    'is_indexable' => (bool) $locked->is_indexable,
                ])
            );
            app(ArticleMaterialDecisionService::class)->recordCurrentPublished($locked);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        return collect([
            'seo_title',
            'seo_description',
            'canonical_url',
            'og_title',
            'og_description',
            'og_image_url',
            'robots',
        ])
            ->filter(static fn (string $field): bool => array_key_exists($field, $payload))
            ->mapWithKeys(function (string $field) use ($payload): array {
                $value = $payload[$field];
                if (is_string($value)) {
                    $value = trim($value);
                }

                return [$field => filled($value) ? $value : null];
            })
            ->all();
    }
}
