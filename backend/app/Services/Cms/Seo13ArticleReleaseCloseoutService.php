<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use Illuminate\Support\Facades\DB;

/**
 * @review-surface article
 */
final class Seo13ArticleReleaseCloseoutService
{
    public const TARGET_COUNT = 13;

    public const COMPLETE_MONITORING_PENDING = 'SEO13_RELEASE_CLOSEOUT_COMPLETE_MONITORING_PENDING';

    /** @var array<int,int> */
    private const OLD_PUBLISHED_REVISIONS = [
        1 => 341,
        2 => 347,
        5 => 5,
        6 => 6,
        7 => 7,
        9 => 9,
        10 => 10,
        11 => 30,
        12 => 31,
        13 => 32,
        14 => 33,
        15 => 34,
        16 => 35,
    ];

    public function __construct(
        private readonly Seo13ArticleSchemaReleaseService $schemaReleaseService,
        private readonly Seo13ArticleDiscoverabilityCacheRefreshService $discoverabilityService,
        private readonly ArticleEditorialCompletenessGate $editorialCompletenessGate,
        private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(): array
    {
        $schema = $this->schemaReleaseService->preflight();
        $discoverability = $this->discoverabilityService->preflight();
        $errors = [];
        $rows = [];

        if (($schema['ok'] ?? false) !== true
            || ($schema['readback_complete'] ?? false) !== true
            || (int) ($schema['released_count'] ?? 0) !== self::TARGET_COUNT) {
            $errors[] = $this->issue(null, 'schema_release_incomplete');
        }
        if (($discoverability['ok'] ?? false) !== true
            || ($discoverability['readback_complete'] ?? false) !== true) {
            $errors[] = $this->issue(null, 'discoverability_source_projection_incomplete');
        }

        foreach ((array) ($schema['rows'] ?? []) as $authorityRow) {
            if (! is_array($authorityRow)) {
                $errors[] = $this->issue(null, 'authority_row_invalid');

                continue;
            }

            $articleId = (int) ($authorityRow['article_id'] ?? 0);
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with([
                    'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                    'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
                ])
                ->find($articleId);
            $oldRevisionId = self::OLD_PUBLISHED_REVISIONS[$articleId] ?? 0;
            $oldRevision = $oldRevisionId > 0
                ? ArticleTranslationRevision::query()->withoutGlobalScopes()->find($oldRevisionId)
                : null;

            if (! $article instanceof Article
                || ! $article->publishedRevision instanceof ArticleTranslationRevision
                || ! $article->seoMeta instanceof ArticleSeoMeta) {
                $errors[] = $this->issue($articleId, 'article_projection_missing');

                continue;
            }

            $revision = $article->publishedRevision;
            $seoMeta = $article->seoMeta;
            $completeness = $this->editorialCompletenessGate->inspect(
                (string) $article->locale,
                (string) $revision->content_md,
                [
                    'published_revision.title' => (string) $revision->title,
                    'published_revision.excerpt' => (string) $revision->excerpt,
                    'published_revision.content_md' => (string) $revision->content_md,
                    'published_revision.seo_title' => (string) $revision->seo_title,
                    'published_revision.seo_description' => (string) $revision->seo_description,
                ],
            );
            $structure = $this->structureCheck((string) $revision->content_md);
            $privateUrlAbsent = ! $this->hasPrivateUrlReference(implode("\n", [
                (string) $revision->title,
                (string) $revision->excerpt,
                (string) $revision->content_md,
                (string) $revision->seo_title,
                (string) $revision->seo_description,
            ]));
            $oldRevisionTraceable = $oldRevision instanceof ArticleTranslationRevision
                && (int) $oldRevision->article_id === $articleId
                && (string) $oldRevision->revision_status === ArticleTranslationRevision::STATUS_STALE;
            $hreflangHeld = data_get(
                is_array($seoMeta->schema_json) ? $seoMeta->schema_json : [],
                'editorial_package_v1.hreflang_gate_v1.enabled',
                false,
            ) !== true;

            foreach ([
                'editorial_completeness_failed' => ($completeness['ok'] ?? false) !== true,
                'required_visible_sections_missing' => ($structure['ok'] ?? false) !== true,
                'private_url_reference_present' => ! $privateUrlAbsent,
                'old_published_revision_not_stale_or_traceable' => ! $oldRevisionTraceable,
                'hreflang_not_held' => ! $hreflangHeld,
            ] as $code => $failed) {
                if ($failed) {
                    $errors[] = $this->issue($articleId, $code);
                }
            }

            $rows[] = [
                'article_id' => $articleId,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'published_revision_id' => (int) $revision->id,
                'old_published_revision_id' => $oldRevisionId,
                'old_revision_traceable_and_stale' => $oldRevisionTraceable,
                'canonical_url' => (string) $seoMeta->canonical_url,
                'robots' => (string) $seoMeta->robots,
                'is_indexable' => (bool) $article->is_indexable && (bool) $seoMeta->is_indexable,
                'schema_state' => (string) ($authorityRow['schema_state'] ?? ''),
                'json_ld_types' => (array) ($authorityRow['planned_json_ld_types'] ?? []),
                'faq_count' => (int) ($authorityRow['planned_json_ld_faq_count'] ?? 0),
                'hreflang_state' => $hreflangHeld ? 'held' : 'released',
                'hreflang_closeout_reason' => $hreflangHeld ? 'no_verified_reciprocal_counterpart' : null,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'editorial_completeness_ok' => (bool) ($completeness['ok'] ?? false),
                'han_character_count' => (int) ($completeness['actual_han_characters'] ?? 0),
                'quick_answer_present' => (bool) $structure['quick_answer_present'],
                'faq_present' => (bool) $structure['faq_present'],
                'references_present' => (bool) $structure['references_present'],
                'markdown_h1_count' => (int) $structure['markdown_h1_count'],
                'private_url_absent' => $privateUrlAbsent,
                'title_sha256' => (string) ($authorityRow['title_sha256'] ?? ''),
                'excerpt_sha256' => (string) ($authorityRow['excerpt_sha256'] ?? ''),
                'body_sha256' => (string) ($authorityRow['body_sha256'] ?? ''),
                'public_body_sha256' => hash(
                    'sha256',
                    $this->articleBodyHeadingGuard->downgradeMarkdownH1ToH2((string) $revision->content_md),
                ),
                'seo_title_sha256' => (string) ($authorityRow['seo_title_sha256'] ?? ''),
                'seo_description_sha256' => (string) ($authorityRow['seo_description_sha256'] ?? ''),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['article_id'] <=> $right['article_id']);
        if (count($rows) !== self::TARGET_COUNT) {
            $errors[] = $this->issue(null, 'target_count_mismatch');
        }

        $searchHold = $this->searchHoldCheck(array_column($rows, 'canonical_url'));
        $cannibalization = $this->cannibalizationCheck($rows);
        $errors = array_merge($errors, $searchHold['errors'], $cannibalization['errors']);
        usort($errors, static fn (array $left, array $right): int => [
            $left['article_id'] ?? 0,
            $left['code'],
        ] <=> [
            $right['article_id'] ?? 0,
            $right['code'],
        ]);

        $ok = $errors === [];

        return [
            'ok' => $ok,
            'decision' => $ok ? self::COMPLETE_MONITORING_PENDING : 'SEO13_RELEASE_CLOSEOUT_BLOCKED',
            'batch' => 'seo13-20260726',
            'target_count' => self::TARGET_COUNT,
            'state_sha256' => (string) ($schema['state_sha256'] ?? ''),
            'content_set_sha256' => (string) ($schema['content_set_sha256'] ?? ''),
            'target_set_sha256' => (string) ($schema['target_set_sha256'] ?? ''),
            'schema_released_count' => (int) ($schema['released_count'] ?? 0),
            'hreflang_held_count' => count(array_filter(
                $rows,
                static fn (array $row): bool => $row['hreflang_state'] === 'held',
            )),
            'search_hold' => $searchHold,
            'cannibalization' => $cannibalization,
            'monitoring_windows' => ['D1', 'D7', 'D14', 'D28'],
            'rows' => $rows,
            'errors' => $errors,
            'production_write_execution' => false,
            'cms_authority_write_count' => 0,
            'database_authority_write_count' => 0,
            'publication_write_count' => 0,
            'schema_write_count' => 0,
            'hreflang_write_count' => 0,
            'revalidation_count' => 0,
            'sitemap_eligibility_write_count' => 0,
            'llms_eligibility_write_count' => 0,
            'search_submission_count' => 0,
            'gsc_request_count' => 0,
            'url_inspection_count' => 0,
            'queue_dispatch_count' => 0,
            'deploy_count' => 0,
        ];
    }

    /**
     * @return array{ok:bool,quick_answer_present:bool,faq_present:bool,references_present:bool,markdown_h1_count:int}
     */
    private function structureCheck(string $markdown): array
    {
        $quickAnswer = preg_match('/^##\s+快速答案\s*$/mu', $markdown) === 1;
        $faq = preg_match('/^##\s+常见问题\s*$/mu', $markdown) === 1;
        $references = preg_match('/^##\s+参考来源\s*$/mu', $markdown) === 1;
        $h1Count = preg_match_all('/^#(?!#)\s+\S.+$/mu', $markdown, $matches) ?: 0;

        return [
            'ok' => $quickAnswer && $faq && $references && $h1Count === 1,
            'quick_answer_present' => $quickAnswer,
            'faq_present' => $faq,
            'references_present' => $references,
            'markdown_h1_count' => $h1Count,
        ];
    }

    private function hasPrivateUrlReference(string $text): bool
    {
        return preg_match(
            '~(?:https?://[^\s)]+)?/(?:attempts?|reports?|results?|orders?|payments?|checkout)(?:[/#?]|\b)|(?:attempt_id|order_id|report_id|token|signature)=~iu',
            $text,
        ) === 1;
    }

    /**
     * @param  list<string>  $canonicals
     * @return array<string,mixed>
     */
    private function searchHoldCheck(array $canonicals): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        $tables = [
            'seo_search_channel_queue_items',
            'seo_indexnow_submissions',
            'seo_baidu_push_logs',
        ];
        $errors = [];
        $counts = [];
        $hashes = array_map(static fn (string $url): string => hash('sha256', $url), $canonicals);

        foreach ($tables as $table) {
            if (! \App\Support\SchemaBaseline::tableExists($table, $connection)) {
                $errors[] = $this->issue(null, $table.'_missing');
                $counts[$table] = null;

                continue;
            }

            $query = DB::connection($connection)->table($table);
            $counts[$table] = $table === 'seo_search_channel_queue_items'
                ? $query->whereIn('canonical_url', $canonicals)->count()
                : $query->whereIn('canonical_url_hash', $hashes)->count();
            if ($counts[$table] !== 0) {
                $errors[] = $this->issue(null, $table.'_not_zero');
            }
        }

        return [
            'ok' => $errors === [],
            'queue_item_count' => $counts['seo_search_channel_queue_items'] ?? null,
            'indexnow_submission_count' => $counts['seo_indexnow_submissions'] ?? null,
            'baidu_submission_count' => $counts['seo_baidu_push_logs'] ?? null,
            'gsc_request_count' => 0,
            'url_inspection_count' => 0,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $targetRows
     * @return array<string,mixed>
     */
    private function cannibalizationCheck(array $targetRows): array
    {
        $targetIds = array_map(static fn (array $row): int => (int) $row['article_id'], $targetRows);
        $targetIdLookup = array_fill_keys($targetIds, true);
        $articles = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'publishedRevision' => static fn ($query) => $query->withoutGlobalScopes(),
                'seoMeta' => static fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('status', 'published')
            ->where('is_public', true)
            ->where('is_indexable', true)
            ->where('locale', 'zh-CN')
            ->get();
        $errors = [];
        $exactTitleGroups = [];
        $exactDescriptionGroups = [];
        $canonicalGroups = [];
        $nearPairs = [];
        $normalizedRows = [];

        foreach ($articles as $article) {
            if (! $article->publishedRevision instanceof ArticleTranslationRevision
                || ! $article->seoMeta instanceof ArticleSeoMeta) {
                continue;
            }
            $id = (int) $article->id;
            $title = $this->normalizeText((string) $article->publishedRevision->title);
            $description = $this->normalizeText((string) $article->publishedRevision->seo_description);
            $canonical = mb_strtolower(trim((string) $article->seoMeta->canonical_url), 'UTF-8');
            $exactTitleGroups[$title][] = $id;
            $exactDescriptionGroups[$description][] = $id;
            $canonicalGroups[$canonical][] = $id;
            $normalizedRows[] = ['id' => $id, 'title' => $title];
        }

        foreach ([
            'exact_title_duplicate' => $exactTitleGroups,
            'exact_seo_description_duplicate' => $exactDescriptionGroups,
            'canonical_conflict' => $canonicalGroups,
        ] as $code => $groups) {
            foreach ($groups as $ids) {
                $ids = array_values(array_unique($ids));
                if (count($ids) > 1 && array_intersect($ids, $targetIds) !== []) {
                    $errors[] = $this->issue(null, $code, ['article_ids' => $ids]);
                }
            }
        }

        $rowCount = count($normalizedRows);
        for ($left = 0; $left < $rowCount; $left++) {
            for ($right = $left + 1; $right < $rowCount; $right++) {
                $leftRow = $normalizedRows[$left];
                $rightRow = $normalizedRows[$right];
                if (! isset($targetIdLookup[$leftRow['id']]) && ! isset($targetIdLookup[$rightRow['id']])) {
                    continue;
                }
                $similarity = $this->bigramDice($leftRow['title'], $rightRow['title']);
                if ($similarity >= 0.90 && $leftRow['title'] !== $rightRow['title']) {
                    $nearPairs[] = [
                        'article_ids' => [$leftRow['id'], $rightRow['id']],
                        'similarity_basis_points' => (int) round($similarity * 10000),
                    ];
                }
            }
        }
        if ($nearPairs !== []) {
            $errors[] = $this->issue(null, 'near_duplicate_title', ['pairs' => $nearPairs]);
        }

        return [
            'ok' => $errors === [],
            'published_zh_article_count' => count($normalizedRows),
            'exact_title_duplicate_count' => $this->issueCount($errors, 'exact_title_duplicate'),
            'exact_seo_description_duplicate_count' => $this->issueCount($errors, 'exact_seo_description_duplicate'),
            'canonical_conflict_count' => $this->issueCount($errors, 'canonical_conflict'),
            'near_duplicate_title_pair_count' => count($nearPairs),
            'errors' => $errors,
        ];
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/[\p{P}\p{Z}\s]+/u', '', $value) ?? $value;
    }

    private function bigramDice(string $left, string $right): float
    {
        $leftBigrams = $this->bigrams($left);
        $rightBigrams = $this->bigrams($right);
        if ($leftBigrams === [] || $rightBigrams === []) {
            return $left === $right ? 1.0 : 0.0;
        }
        $rightCounts = array_count_values($rightBigrams);
        $intersection = 0;
        foreach ($leftBigrams as $bigram) {
            if (($rightCounts[$bigram] ?? 0) > 0) {
                $intersection++;
                $rightCounts[$bigram]--;
            }
        }

        return (2 * $intersection) / (count($leftBigrams) + count($rightBigrams));
    }

    /** @return list<string> */
    private function bigrams(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $bigrams = [];
        for ($index = 0; $index < count($characters) - 1; $index++) {
            $bigrams[] = $characters[$index].$characters[$index + 1];
        }

        return $bigrams;
    }

    /** @param list<array<string,mixed>> $issues */
    private function issueCount(array $issues, string $code): int
    {
        return count(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['code'] ?? null) === $code,
        ));
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function issue(?int $articleId, string $code, array $extra = []): array
    {
        return array_merge([
            'article_id' => $articleId,
            'code' => $code,
        ], $extra);
    }
}
