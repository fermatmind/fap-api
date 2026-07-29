<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @review-surface article
 */
final class Seo13ArticleSchemaReleaseService
{
    public const TARGET_COUNT = 13;

    public const BIG_FIVE_AUTHORITY_WRITE_COUNT = 2;

    /**
     * @var list<array{
     *   article_id:int,
     *   slug:string,
     *   translation_group_id:string,
     *   published_revision_id:int,
     *   canonical_url:string
     * }>
     */
    private const TARGETS = [
        [
            'article_id' => 1,
            'slug' => 'big-five-growth-guide',
            'translation_group_id' => 'big5-v2-f29331ce54d2f28a7051702932c39aaf69d2bf61',
            'published_revision_id' => 446,
            'canonical_url' => 'https://fermatmind.com/zh/articles/big-five-growth-guide',
        ],
        [
            'article_id' => 2,
            'slug' => 'big-five-narrative-portrait',
            'translation_group_id' => 'big5-v2-8381cc150e7180b365a397ce3e3a25e2626b8970',
            'published_revision_id' => 445,
            'canonical_url' => 'https://fermatmind.com/zh/articles/big-five-narrative-portrait',
        ],
        [
            'article_id' => 5,
            'slug' => 'iq-test-growth-guide',
            'translation_group_id' => 'article-5',
            'published_revision_id' => 444,
            'canonical_url' => 'https://fermatmind.com/zh/articles/iq-test-growth-guide',
        ],
        [
            'article_id' => 6,
            'slug' => 'iq-test-narrative-portrait',
            'translation_group_id' => 'article-6',
            'published_revision_id' => 443,
            'canonical_url' => 'https://fermatmind.com/zh/articles/iq-test-narrative-portrait',
        ],
        [
            'article_id' => 7,
            'slug' => 'iq-test-tool-guide',
            'translation_group_id' => 'article-7',
            'published_revision_id' => 442,
            'canonical_url' => 'https://fermatmind.com/zh/articles/iq-test-tool-guide',
        ],
        [
            'article_id' => 9,
            'slug' => 'mbti-growth-guide',
            'translation_group_id' => 'article-9',
            'published_revision_id' => 441,
            'canonical_url' => 'https://fermatmind.com/zh/articles/mbti-growth-guide',
        ],
        [
            'article_id' => 10,
            'slug' => 'mbti-narrative-portrait',
            'translation_group_id' => 'article-10',
            'published_revision_id' => 440,
            'canonical_url' => 'https://fermatmind.com/zh/articles/mbti-narrative-portrait',
        ],
        [
            'article_id' => 11,
            'slug' => 'are-infj-men-rare-or-socially-silenced',
            'translation_group_id' => 'article-11',
            'published_revision_id' => 436,
            'canonical_url' => 'https://fermatmind.com/zh/articles/are-infj-men-rare-or-socially-silenced',
        ],
        [
            'article_id' => 12,
            'slug' => 'best-valentines-date-by-personality-and-relationship-science',
            'translation_group_id' => 'article-12',
            'published_revision_id' => 437,
            'canonical_url' => 'https://fermatmind.com/zh/articles/best-valentines-date-by-personality-and-relationship-science',
        ],
        [
            'article_id' => 13,
            'slug' => 'childhood-dream-job-still-shapes-career-choice',
            'translation_group_id' => 'article-13',
            'published_revision_id' => 439,
            'canonical_url' => 'https://fermatmind.com/zh/articles/childhood-dream-job-still-shapes-career-choice',
        ],
        [
            'article_id' => 14,
            'slug' => 'how-16-personality-types-talk-to-an-ai-coach',
            'translation_group_id' => 'article-14',
            'published_revision_id' => 438,
            'canonical_url' => 'https://fermatmind.com/zh/articles/how-16-personality-types-talk-to-an-ai-coach',
        ],
        [
            'article_id' => 15,
            'slug' => 'how-personality-shapes-attitude-toward-ai',
            'translation_group_id' => 'article-15',
            'published_revision_id' => 434,
            'canonical_url' => 'https://fermatmind.com/zh/articles/how-personality-shapes-attitude-toward-ai',
        ],
        [
            'article_id' => 16,
            'slug' => 'which-love-script-fits-you-best',
            'translation_group_id' => 'article-16',
            'published_revision_id' => 435,
            'canonical_url' => 'https://fermatmind.com/zh/articles/which-love-script-fits-you-best',
        ],
    ];

    public function __construct(
        private readonly ArticleSeoService $articleSeoService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function preflight(): array
    {
        return $this->snapshot(lockForUpdate: false);
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(
        string $expectedStateSha256,
        string $expectedContentSetSha256,
        string $expectedTargetSetSha256,
    ): array {
        foreach ([$expectedStateSha256, $expectedContentSetSha256, $expectedTargetSetSha256] as $hash) {
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException('schema_release_expected_hash_invalid');
            }
        }

        return DB::transaction(function () use (
            $expectedStateSha256,
            $expectedContentSetSha256,
            $expectedTargetSetSha256,
        ): array {
            $before = $this->snapshot(lockForUpdate: true);
            if (($before['ok'] ?? false) !== true || ($before['apply_supported'] ?? false) !== true) {
                throw new RuntimeException('schema_release_preflight_rejected');
            }
            foreach ([
                'state_sha256' => $expectedStateSha256,
                'content_set_sha256' => $expectedContentSetSha256,
                'target_set_sha256' => $expectedTargetSetSha256,
            ] as $field => $expected) {
                if (! hash_equals($expected, (string) ($before[$field] ?? ''))) {
                    throw new RuntimeException('schema_release_'.$field.'_drift');
                }
            }

            $schemaWriteCount = 0;
            $revisionAuthorityWriteCount = 0;
            foreach (self::TARGETS as $target) {
                $article = $this->article($target['article_id'], true);
                $revision = $this->revision($target['published_revision_id'], $target['article_id'], true);
                $seoMeta = $this->seoMeta($article, true);
                if (! $revision instanceof ArticleTranslationRevision || ! $seoMeta instanceof ArticleSeoMeta) {
                    throw new RuntimeException('schema_release_locked_authority_missing');
                }

                $faqItems = $this->visibleFaqItems((string) $revision->content_md);
                if ($this->isBigFiveTarget($target['article_id'])) {
                    $revision->forceFill([
                        'created_by' => (int) $revision->reviewed_by,
                        'authority_metadata_json' => $this->plannedBigFiveAuthorityMetadata($article, $revision),
                    ])->saveQuietly();
                    $revisionAuthorityWriteCount++;
                }
                $plannedSchema = $this->plannedSchemaJson($seoMeta, $revision, $faqItems);
                $seoMeta->forceFill(['schema_json' => $plannedSchema])->saveQuietly();
                $schemaWriteCount++;
            }

            $orgIds = collect((array) $before['rows'])->pluck('org_id')->unique()->values();
            if ($orgIds->count() !== 1
                || $schemaWriteCount !== self::TARGET_COUNT
                || $revisionAuthorityWriteCount !== self::BIG_FIVE_AUTHORITY_WRITE_COUNT) {
                throw new RuntimeException('schema_release_write_count_mismatch');
            }

            AuditLog::query()->withoutGlobalScopes()->create([
                'org_id' => (int) $orgIds->first(),
                'actor_admin_id' => null,
                'action' => 'seo13_article_schema_release',
                'target_type' => 'article_batch',
                'target_id' => 'seo13-20260726',
                'meta_json' => [
                    'target_count' => self::TARGET_COUNT,
                    'target_set_sha256' => $expectedTargetSetSha256,
                    'preflight_state_sha256' => $expectedStateSha256,
                    'content_set_sha256' => $expectedContentSetSha256,
                    'schema_write_count' => $schemaWriteCount,
                    'revision_authority_write_count' => $revisionAuthorityWriteCount,
                    'article_schema_enabled_count' => self::TARGET_COUNT,
                    'breadcrumb_schema_enabled_count' => self::TARGET_COUNT,
                    'faq_schema_enabled_count' => self::TARGET_COUNT,
                    'publication_write_count' => 0,
                    'hreflang_write_count' => 0,
                    'revalidation_count' => 0,
                    'sitemap_eligibility_write_count' => 0,
                    'llms_eligibility_write_count' => 0,
                    'search_submission_count' => 0,
                ],
                'ip' => null,
                'user_agent' => 'seo13-article-schema-release',
                'request_id' => '',
                'reason' => 'seo13_post_publish_visible_schema_release',
                'result' => 'success',
                'created_at' => now(),
            ]);

            $after = $this->snapshot(lockForUpdate: true);
            if (($after['ok'] ?? false) !== true
                || ($after['released_count'] ?? 0) !== self::TARGET_COUNT
                || ($after['held_count'] ?? 0) !== 0
                || ($after['readback_complete'] ?? false) !== true) {
                throw new RuntimeException('schema_release_readback_failed');
            }

            return [
                'before' => $before,
                'after' => $after,
                'writes' => [
                    'schema_write_count' => $schemaWriteCount,
                    'revision_authority_write_count' => $revisionAuthorityWriteCount,
                    'audit_write_count' => 1,
                ],
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(bool $lockForUpdate): array
    {
        $rows = [];
        $errors = [];
        $heldCount = 0;
        $releasedCount = 0;

        foreach (self::TARGETS as $target) {
            $article = $this->article($target['article_id'], $lockForUpdate);
            if (! $article instanceof Article) {
                $errors[] = $this->issue($target['article_id'], 'article_not_found');

                continue;
            }

            $revision = $this->revision($target['published_revision_id'], $target['article_id'], $lockForUpdate);
            $seoMeta = $this->seoMeta($article, $lockForUpdate);
            foreach ($this->identityErrors($article, $revision, $seoMeta, $target) as $code) {
                $errors[] = $this->issue($target['article_id'], $code);
            }

            $faqItems = $revision instanceof ArticleTranslationRevision
                ? $this->visibleFaqItems((string) $revision->content_md)
                : [];
            if (count($faqItems) < 4 || count($faqItems) > 8) {
                $errors[] = $this->issue($target['article_id'], 'visible_faq_count_out_of_bounds');
            }

            $plannedSchema = $seoMeta instanceof ArticleSeoMeta && $revision instanceof ArticleTranslationRevision
                ? $this->plannedSchemaJson($seoMeta, $revision, $faqItems)
                : [];
            $plannedAuthorityMetadata = $revision instanceof ArticleTranslationRevision
                && $this->isBigFiveTarget($target['article_id'])
                ? $this->plannedBigFiveAuthorityMetadata($article, $revision)
                : [];
            $currentAuthorityMetadata = $revision instanceof ArticleTranslationRevision
                && is_array($revision->authority_metadata_json)
                ? $revision->authority_metadata_json
                : [];
            $currentAuthorityActorId = $revision instanceof ArticleTranslationRevision
                ? (int) ($revision->created_by ?? 0)
                : 0;
            $plannedAuthorityActorId = $revision instanceof ArticleTranslationRevision
                ? (int) ($revision->reviewed_by ?? 0)
                : 0;
            $authorityState = ! $this->isBigFiveTarget($target['article_id'])
                ? 'not_applicable'
                : ($currentAuthorityMetadata === [] && $currentAuthorityActorId === 0
                    ? 'held'
                    : (hash_equals(
                        $this->deterministicHash($plannedAuthorityMetadata),
                        $this->deterministicHash($currentAuthorityMetadata),
                    ) && $currentAuthorityActorId === $plannedAuthorityActorId
                        ? 'complete'
                        : 'drifted'));
            if ($authorityState === 'drifted') {
                $errors[] = $this->issue($target['article_id'], 'big_five_authority_metadata_drift');
            }
            $currentSchema = $seoMeta instanceof ArticleSeoMeta && is_array($seoMeta->schema_json)
                ? $seoMeta->schema_json
                : [];
            $schemaState = $this->schemaState($currentSchema, $plannedSchema);
            if ($schemaState === 'held') {
                $heldCount++;
            } elseif ($schemaState === 'released') {
                $releasedCount++;
            } else {
                $errors[] = $this->issue($target['article_id'], 'schema_gate_partial_or_drifted');
            }

            $parity = $article instanceof Article
                && $revision instanceof ArticleTranslationRevision
                && $seoMeta instanceof ArticleSeoMeta
                ? $this->plannedParity(
                    $article,
                    $revision,
                    $seoMeta,
                    $plannedSchema,
                    $plannedAuthorityMetadata,
                    $faqItems,
                )
                : ['ok' => false, 'types' => [], 'faq_count' => 0];
            if (($parity['ok'] ?? false) !== true) {
                $errors[] = $this->issue($target['article_id'], 'planned_schema_parity_failed');
                foreach ((array) ($parity['failures'] ?? []) as $failure) {
                    if (is_string($failure) && preg_match('/^[a-z0-9_]{1,128}$/', $failure) === 1) {
                        $errors[] = $this->issue($target['article_id'], $failure);
                    }
                }
            }

            $rows[] = [
                'article_id' => (int) $article->id,
                'org_id' => (int) $article->org_id,
                'slug' => (string) $article->slug,
                'locale' => (string) $article->locale,
                'translation_group_id' => (string) $article->translation_group_id,
                'published_revision_id' => (int) ($article->published_revision_id ?? 0),
                'article_status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'canonical_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->canonical_url : null,
                'robots' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->robots : null,
                'schema_state' => $schemaState,
                'authority_state' => $authorityState,
                'authority_metadata_sha256' => $this->deterministicHash($currentAuthorityMetadata),
                'planned_authority_metadata_sha256' => $this->deterministicHash($plannedAuthorityMetadata),
                'visible_source_count' => count((array) data_get($plannedAuthorityMetadata, 'visible_provenance.sources', [])),
                'schema_json_sha256' => $this->deterministicHash($currentSchema),
                'planned_schema_json_sha256' => $this->deterministicHash($plannedSchema),
                'faq_count' => count($faqItems),
                'faq_sha256' => $this->deterministicHash($faqItems),
                'planned_json_ld_types' => (array) ($parity['types'] ?? []),
                'planned_json_ld_faq_count' => (int) ($parity['faq_count'] ?? 0),
                'planned_schema_parity_failures' => (array) ($parity['failures'] ?? []),
                'title_sha256' => $this->textHash((string) ($revision?->title ?? '')),
                'excerpt_sha256' => $this->textHash((string) ($revision?->excerpt ?? '')),
                'body_sha256' => $this->textHash((string) ($revision?->content_md ?? '')),
                'seo_title_sha256' => $this->textHash((string) ($revision?->seo_title ?? '')),
                'seo_description_sha256' => $this->textHash((string) ($revision?->seo_description ?? '')),
            ];
        }

        usort($rows, static fn (array $left, array $right): int => $left['article_id'] <=> $right['article_id']);
        usort($errors, static fn (array $left, array $right): int => [$left['article_id'], $left['code']] <=> [$right['article_id'], $right['code']]);

        $contentRows = array_map(static fn (array $row): array => [
            'article_id' => $row['article_id'],
            'published_revision_id' => $row['published_revision_id'],
            'title_sha256' => $row['title_sha256'],
            'excerpt_sha256' => $row['excerpt_sha256'],
            'body_sha256' => $row['body_sha256'],
            'seo_title_sha256' => $row['seo_title_sha256'],
            'seo_description_sha256' => $row['seo_description_sha256'],
            'faq_sha256' => $row['faq_sha256'],
        ], $rows);

        return [
            'ok' => $errors === []
                && count($rows) === self::TARGET_COUNT
                && (
                    $heldCount === self::TARGET_COUNT
                    || $releasedCount === self::TARGET_COUNT
                ),
            'target_count' => self::TARGET_COUNT,
            'held_count' => $heldCount,
            'released_count' => $releasedCount,
            'apply_supported' => $errors === [] && $heldCount === self::TARGET_COUNT,
            'readback_complete' => $errors === [] && $releasedCount === self::TARGET_COUNT,
            'target_set_sha256' => $this->deterministicHash(self::TARGETS),
            'content_set_sha256' => $this->deterministicHash($contentRows),
            'state_sha256' => $this->deterministicHash($rows),
            'rows' => $rows,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $target
     * @return list<string>
     */
    private function identityErrors(
        Article $article,
        ?ArticleTranslationRevision $revision,
        ?ArticleSeoMeta $seoMeta,
        array $target,
    ): array {
        $errors = [];
        if ((string) $article->slug !== (string) $target['slug']) {
            $errors[] = 'slug_lock_mismatch';
        }
        if ((string) $article->locale !== 'zh-CN') {
            $errors[] = 'locale_lock_mismatch';
        }
        if ((string) $article->translation_group_id !== (string) $target['translation_group_id']) {
            $errors[] = 'translation_group_lock_mismatch';
        }
        if ((string) $article->status !== 'published'
            || ! (bool) $article->is_public
            || ! (bool) $article->is_indexable) {
            $errors[] = 'public_indexable_state_mismatch';
        }
        if ((int) ($article->published_revision_id ?? 0) !== (int) $target['published_revision_id']
            || ! $revision instanceof ArticleTranslationRevision
            || (string) $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            $errors[] = 'published_revision_lock_mismatch';
        } elseif ((int) $revision->article_id !== (int) $article->id
            || (int) $revision->org_id !== (int) $article->org_id
            || (string) $revision->locale !== 'zh-CN'
            || (string) $revision->translation_group_id !== (string) $article->translation_group_id) {
            $errors[] = 'published_revision_identity_mismatch';
        } elseif ((string) $revision->title !== (string) $article->title
            || (string) $revision->excerpt !== (string) $article->excerpt
            || (string) $revision->content_md !== (string) $article->content_md) {
            $errors[] = 'published_projection_content_mismatch';
        }
        if (! $seoMeta instanceof ArticleSeoMeta) {
            $errors[] = 'seo_meta_missing';
        } elseif ((int) $seoMeta->org_id !== (int) $article->org_id
            || (string) $seoMeta->locale !== 'zh-CN'
            || (string) $seoMeta->canonical_url !== (string) $target['canonical_url']
            || (string) $seoMeta->robots !== 'index,follow'
            || ! (bool) $seoMeta->is_indexable) {
            $errors[] = 'seo_meta_identity_or_indexability_mismatch';
        } elseif ($revision instanceof ArticleTranslationRevision
            && (
                (string) $seoMeta->seo_title !== (string) $revision->seo_title
                || (string) $seoMeta->seo_description !== (string) $revision->seo_description
                || (string) $seoMeta->og_title !== (string) $revision->seo_title
                || (string) $seoMeta->og_description !== (string) $revision->seo_description
            )) {
            $errors[] = 'seo_meta_published_revision_mismatch';
        }

        return $errors;
    }

    /**
     * @param  list<array{question:string,answer:string}>  $faqItems
     * @return array<string,mixed>
     */
    private function plannedSchemaJson(
        ArticleSeoMeta $seoMeta,
        ArticleTranslationRevision $revision,
        array $faqItems,
    ): array {
        $schema = is_array($seoMeta->schema_json) ? $seoMeta->schema_json : [];
        $package = is_array($schema['editorial_package_v1'] ?? null)
            ? $schema['editorial_package_v1']
            : [];
        unset($package['schema_hold']);
        $package['article_schema_enabled'] = true;
        $package['breadcrumb_schema_enabled'] = true;
        $package['faq_schema_enabled'] = true;
        $package['answer_surface_policy'] = 'editor_supplied';
        $package['answer_surface_visibility'] = 'body_faq_section';
        $package['answer_surface_v1'] = ['faq_items' => $faqItems];
        $package['schema_release_v1'] = [
            'batch' => 'seo13-20260726',
            'source' => 'published_revision_visible_faq',
            'published_revision_id' => (int) $revision->id,
            'faq_sha256' => $this->deterministicHash($faqItems),
        ];
        $schema['editorial_package_v1'] = $package;

        return $schema;
    }

    /**
     * @param  array<string,mixed>  $currentSchema
     * @param  array<string,mixed>  $plannedSchema
     */
    private function schemaState(array $currentSchema, array $plannedSchema): string
    {
        $package = is_array($currentSchema['editorial_package_v1'] ?? null)
            ? $currentSchema['editorial_package_v1']
            : [];
        $gateValues = [
            $package['article_schema_enabled'] ?? null,
            $package['breadcrumb_schema_enabled'] ?? null,
            $package['faq_schema_enabled'] ?? null,
        ];
        if ($gateValues === [true, true, true]
            && hash_equals($this->deterministicHash($plannedSchema), $this->deterministicHash($currentSchema))) {
            return 'released';
        }
        if (($package['faq_schema_enabled'] ?? null) !== true
            && (bool) data_get($package, 'hreflang_gate_v1.enabled', false) !== true) {
            return 'held';
        }

        return 'partial_or_drifted';
    }

    /**
     * @param  array<string,mixed>  $plannedSchema
     * @param  list<array{question:string,answer:string}>  $faqItems
     * @return array{ok:bool,types:list<string>,faq_count:int,failures:list<string>}
     */
    private function plannedParity(
        Article $article,
        ArticleTranslationRevision $revision,
        ArticleSeoMeta $seoMeta,
        array $plannedSchema,
        array $plannedAuthorityMetadata,
        array $faqItems,
    ): array {
        $plannedSeoMeta = clone $seoMeta;
        $plannedSeoMeta->schema_json = $plannedSchema;
        $plannedRevision = clone $revision;
        if ($this->isBigFiveTarget((int) $article->id)) {
            $plannedRevision->created_by = (int) $revision->reviewed_by;
            $plannedRevision->authority_metadata_json = $plannedAuthorityMetadata;
        }
        $plannedArticle = clone $article;
        $plannedArticle->setRelation('seoMeta', $plannedSeoMeta);
        $plannedArticle->setRelation('publishedRevision', $plannedRevision);

        $seoPayload = $this->articleSeoService->buildSeoPayload($plannedArticle, $plannedRevision);
        $jsonLd = $this->articleSeoService->generateJsonLd($plannedArticle, $plannedRevision);
        $types = $this->jsonLdTypes($jsonLd);
        $faq = $this->faqPage($jsonLd);
        $projectedFaqItems = $this->faqItemsFromJsonLd($faq);
        $articleFragment = data_get($seoPayload, 'article_authority_v1.structured_data_fragments.article');
        $breadcrumbFragment = data_get($seoPayload, 'article_authority_v1.structured_data_fragments.breadcrumb_list');
        $hreflangEnabled = (bool) data_get($plannedSchema, 'editorial_package_v1.hreflang_gate_v1.enabled', false);
        $failures = [];
        $canonicalMatches = (string) ($seoPayload['canonical'] ?? '') === (string) $seoMeta->canonical_url;
        $articleFragmentEnabled = is_array($articleFragment) && ($articleFragment['@type'] ?? null) === 'Article';
        $breadcrumbFragmentEnabled = is_array($breadcrumbFragment) && ($breadcrumbFragment['@type'] ?? null) === 'BreadcrumbList';
        $articleTypePresent = in_array('Article', $types, true);
        $faqTypePresent = in_array('FAQPage', $types, true);
        $faqItemsMatch = $projectedFaqItems === $faqItems;

        foreach ([
            'planned_parity_canonical_mismatch' => ! $canonicalMatches,
            'planned_parity_article_fragment_missing' => ! $articleFragmentEnabled,
            'planned_parity_breadcrumb_fragment_missing' => ! $breadcrumbFragmentEnabled,
            'planned_parity_article_type_missing' => ! $articleTypePresent,
            'planned_parity_faq_type_missing' => ! $faqTypePresent,
            'planned_parity_faq_items_mismatch' => ! $faqItemsMatch,
            'planned_parity_hreflang_enabled' => $hreflangEnabled,
        ] as $code => $failed) {
            if ($failed) {
                $failures[] = $code;
            }
        }

        foreach ((array) data_get($seoPayload, 'big_five_structured_data_v1.eligibility.blocked_reasons', []) as $blockedReason) {
            if (is_string($blockedReason) && preg_match('/^[a-z0-9_]{1,96}$/', $blockedReason) === 1) {
                $failures[] = 'planned_parity_'.$blockedReason;
            }
        }
        $failures = array_values(array_unique($failures));

        return [
            'ok' => $failures === [],
            'types' => $types,
            'faq_count' => count($projectedFaqItems),
            'failures' => $failures,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function plannedBigFiveAuthorityMetadata(
        Article $article,
        ArticleTranslationRevision $revision,
    ): array {
        $actorId = (int) ($revision->reviewed_by ?? 0);
        $reviewedAt = $revision->reviewed_at?->clone()->utc()->toIso8601String();
        $authorLabel = trim((string) $article->author_name);
        $reviewerLabel = trim((string) $article->reviewer_name);
        $sources = $this->visibleSources((string) $revision->content_md, (int) $article->id);

        if ($actorId <= 0 || $reviewedAt === null || $authorLabel === '' || $reviewerLabel === '' || $sources === []) {
            return [];
        }

        return [
            'visible_provenance' => [
                'author' => [
                    'identity' => 'admin_user:'.$actorId,
                    'label' => $authorLabel,
                    'role' => 'editorial_author',
                    'authority_ref' => 'revision-author:'.$revision->id.':operator:'.$actorId,
                ],
                'reviewer' => [
                    'identity' => 'admin_user:'.$actorId,
                    'label' => $reviewerLabel,
                    'role' => 'editorial_reviewer',
                    'reviewed_at' => $reviewedAt,
                    'review_state' => ArticleTranslationRevision::STATUS_PUBLISHED,
                    'authority_ref' => 'review-ledger:article:'.$article->id.':revision:'.$revision->id,
                ],
                'sources' => $sources,
            ],
            'authority_lineage_v1' => [
                'batch' => 'seo13-20260726',
                'source' => 'published_revision_visible_references_and_review_fields',
                'published_revision_id' => (int) $revision->id,
                'source_set_sha256' => $this->deterministicHash($sources),
            ],
        ];
    }

    /**
     * @return list<array{source_id:string,label:string,category:string,authority_ref:string}>
     */
    private function visibleSources(string $markdown, int $articleId): array
    {
        $lines = preg_split('/\R/u', str_replace("\r", '', $markdown)) ?: [];
        $inside = false;
        $sources = [];
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '## 参考来源') {
                $inside = true;

                continue;
            }
            if (! $inside) {
                continue;
            }
            if (str_starts_with($trimmed, '## ')) {
                break;
            }
            if (! str_starts_with($trimmed, '- ')) {
                continue;
            }
            $label = $this->visibleText(substr($trimmed, 2));
            if ($label === '') {
                continue;
            }
            $identifier = substr(hash('sha256', $label), 0, 20);
            $sources[] = [
                'source_id' => 'academic:seo13:'.$articleId.':'.$identifier,
                'label' => $label,
                'category' => 'academic_evidence',
                'authority_ref' => 'source-ledger:academic:seo13:'.$articleId.':'.$identifier,
            ];
            if (count($sources) >= 8) {
                break;
            }
        }

        return $sources;
    }

    /**
     * @return list<array{question:string,answer:string}>
     */
    private function visibleFaqItems(string $markdown): array
    {
        $lines = preg_split('/\R/u', str_replace("\r", '', $markdown)) ?: [];
        $inside = false;
        $question = null;
        $answerLines = [];
        $items = [];

        $flush = function () use (&$question, &$answerLines, &$items): void {
            if (! is_string($question)) {
                $answerLines = [];

                return;
            }
            $answer = $this->visibleText(implode("\n", $answerLines));
            $normalizedQuestion = $this->visibleText($question);
            if ($normalizedQuestion !== '' && $answer !== '') {
                $items[] = ['question' => $normalizedQuestion, 'answer' => $answer];
            }
            $question = null;
            $answerLines = [];
        };

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '## 常见问题') {
                $inside = true;

                continue;
            }
            if (! $inside) {
                continue;
            }
            if (str_starts_with($trimmed, '## ')) {
                $flush();
                break;
            }
            if (str_starts_with($trimmed, '### ')) {
                $flush();
                $question = trim(substr($trimmed, 4));

                continue;
            }
            if ($question !== null) {
                $answerLines[] = (string) $line;
            }
        }
        if ($inside) {
            $flush();
        }

        return array_slice($items, 0, 8);
    }

    private function visibleText(string $markdown): string
    {
        $text = preg_replace('/!\[([^\]]*)\]\([^)]+\)/u', '$1', $markdown) ?? $markdown;
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/<[^>]+>/u', ' ', $text) ?? $text;
        $text = preg_replace('/^\s*(?:[-*+]|\d+[.)])\s+/mu', '', $text) ?? $text;
        $text = str_replace(['`', '*', '_', '~'], '', $text);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);

        return trim($text);
    }

    /**
     * @param  array<string,mixed>|null  $faqPage
     * @return list<array{question:string,answer:string}>
     */
    private function faqItemsFromJsonLd(?array $faqPage): array
    {
        $items = [];
        foreach ((array) ($faqPage['mainEntity'] ?? []) as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $question = trim((string) ($entity['name'] ?? ''));
            $answer = trim((string) data_get($entity, 'acceptedAnswer.text', ''));
            if ($question !== '' && $answer !== '') {
                $items[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $jsonLd
     * @return array<string,mixed>|null
     */
    private function faqPage(array $jsonLd): ?array
    {
        if (($jsonLd['@type'] ?? null) === 'FAQPage') {
            return $jsonLd;
        }
        foreach (['@graph', 'hasPart'] as $key) {
            foreach ((array) ($jsonLd[$key] ?? []) as $part) {
                if (is_array($part)) {
                    $found = $this->faqPage($part);
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $jsonLd
     * @return list<string>
     */
    private function jsonLdTypes(array $jsonLd): array
    {
        $types = [];
        $walk = function (mixed $value) use (&$walk, &$types): void {
            if (! is_array($value)) {
                return;
            }
            $type = $value['@type'] ?? null;
            foreach (is_array($type) ? $type : [$type] as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $types[] = trim($candidate);
                }
            }
            foreach ($value as $nested) {
                if (is_array($nested)) {
                    $walk($nested);
                }
            }
        };
        $walk($jsonLd);
        $types = array_values(array_unique($types));
        sort($types);

        return $types;
    }

    private function article(int $articleId, bool $lockForUpdate): ?Article
    {
        $query = Article::query()->withoutGlobalScopes()->whereKey($articleId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isBigFiveTarget(int $articleId): bool
    {
        return in_array($articleId, [1, 2], true);
    }

    private function revision(int $revisionId, int $articleId, bool $lockForUpdate): ?ArticleTranslationRevision
    {
        $query = ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->whereKey($revisionId)
            ->where('article_id', $articleId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function seoMeta(Article $article, bool $lockForUpdate): ?ArticleSeoMeta
    {
        $query = ArticleSeoMeta::query()
            ->withoutGlobalScopes()
            ->where('article_id', (int) $article->id)
            ->where('org_id', (int) $article->org_id)
            ->where('locale', (string) $article->locale);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return array{article_id:int,code:string}
     */
    private function issue(int $articleId, string $code): array
    {
        return ['article_id' => $articleId, 'code' => $code];
    }

    private function textHash(string $value): string
    {
        return hash('sha256', $value);
    }

    private function deterministicHash(mixed $value): string
    {
        return hash('sha256', (string) json_encode(
            $this->normalizeForHash($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ));
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeForHash($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForHash($item);
        }

        return $value;
    }
}
