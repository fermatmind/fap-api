<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources\ArticleResource\Support;

use App\Models\Article;
use App\Models\ArticleSeoMeta;

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

        ArticleSeoMeta::query()->updateOrCreate(
            [
                'org_id' => (int) $article->org_id,
                'article_id' => (int) $article->id,
                'locale' => (string) $article->locale,
            ],
            array_merge($seoPayload, [
                'is_indexable' => (bool) $article->is_indexable,
            ])
        );
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
            ->mapWithKeys(function (string $field) use ($payload): array {
                $value = $payload[$field] ?? null;
                if (is_string($value)) {
                    $value = trim($value);
                }

                return [$field => filled($value) ? $value : null];
            })
            ->all();
    }
}
