<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\Article;
use App\Models\CareerGuide;
use App\Services\Cms\CareerGuideSeoService;
use App\Services\Cms\CareerGuideService;
use DomainException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

final class TraditionalCareerGuideSource
{
    public const GUIDE_FIELDS = [
        'guide_code', 'slug', 'locale', 'title', 'excerpt', 'category_slug',
        'body_md', 'body_html', 'is_public', 'is_indexable',
    ];

    public const SEO_FIELDS = [
        'seo_title', 'seo_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description',
    ];

    public function __construct(
        private readonly CareerGuideService $guides,
        private readonly CareerGuideSeoService $seo,
    ) {}

    public function read(string $slug, array $expected, bool $lock): array
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('traditional_career_guide_transaction_required');
        }

        $query = CareerGuide::query()->withoutGlobalScopes()->where([
            'org_id' => 0, 'locale' => 'zh-CN', 'slug' => $slug,
        ])->publishedPublic();
        $guide = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($guide === null || ! in_array($guide->lifecycle_state, [null, 'active'], true)
            || $guide->published_at?->isFuture()
            || ($guide->scheduled_at !== null && Date::parse($guide->scheduled_at)->isFuture())) {
            throw new DomainException('traditional_career_guide_source_missing');
        }
        $seoQuery = $guide->seoMeta();
        $guide->setRelation('seoMeta', ($lock ? $seoQuery->lockForUpdate() : $seoQuery)->first());
        $source = $this->guides->detailPayload($guide);
        $sourceSeo = $this->seo->detailSeoMetaPayload($guide);
        $actual = [
            'sourceId' => $source['id'],
            'guideCode' => $source['guide_code'],
            'publishedAt' => $source['published_at'],
            'updatedAt' => $source['updated_at'],
            'authoredHashes' => self::fieldHashes(self::GUIDE_FIELDS, $source),
            'seoHashes' => self::fieldHashes(self::SEO_FIELDS, $sourceSeo ?? []),
        ];
        if ($actual !== $expected['guide']) {
            throw new DomainException('traditional_career_guide_source_drift');
        }

        $articles = [];
        foreach ($expected['articles'] as $article) {
            $query = Article::query()->withoutGlobalScopes()->where([
                'id' => $article['id'], 'org_id' => 0, 'locale' => 'zh-TW', 'slug' => $article['slug'],
            ])->publiclyReadable();
            $record = ($lock ? $query->lockForUpdate() : $query)->first();
            if ($record === null || $record->published_at?->isFuture()
                || (int) $record->source_article_id !== $article['sourceId']
                || (int) $record->published_revision_id !== $article['publishedRevision']) {
                throw new DomainException('traditional_career_guide_article_drift');
            }
            $revisionQuery = $record->publishedRevision()->withoutGlobalScopes();
            $revision = ($lock ? $revisionQuery->lockForUpdate() : $revisionQuery)->first();
            if (! $revision?->isPubliclyReadableForArticle($record)) {
                throw new DomainException('traditional_career_guide_article_drift');
            }
            $articles[] = $article;
        }

        return ['guide' => $actual, 'articles' => $articles];
    }

    private static function fieldHashes(array $fields, array $source): array
    {
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = hash('sha256', json_encode($source[$field] ?? null,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS));
        }

        return $result;
    }
}
