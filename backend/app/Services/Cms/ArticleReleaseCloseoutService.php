<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Support\PublicMediaUrlGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ArticleReleaseCloseoutService
{
    /**
     * Closeout audits historical Media Library article references that are already
     * public-rendered. Keep this narrower than the general API media sanitizer.
     *
     * @var list<string>
     */
    private const CLOSEOUT_PUBLIC_MEDIA_HOSTS = [
        'api.fermatmind.com',
        'assets.fermatmind.com',
        'fermatmind.com',
        'www.fermatmind.com',
        'ops.fermatmind.com',
    ];

    public const COMPLETE_SEARCH_OBSERVATION_PENDING = 'ARTICLE_RELEASE_COMPLETE_SEARCH_OBSERVATION_PENDING';

    public const BLOCKED_DISCOVERABILITY_GAP = 'BLOCKED_DISCOVERABILITY_GAP';

    public const BLOCKED_SEARCH_QUEUE_GAP = 'BLOCKED_SEARCH_QUEUE_GAP';

    public const BLOCKED_PUBLIC_HTML_DRIFT = 'BLOCKED_PUBLIC_HTML_DRIFT';

    public const BLOCKED_OPERATOR_INPUT = 'BLOCKED_OPERATOR_INPUT';

    /**
     * @param  array<string,mixed>|null  $publicSmoke
     * @return array<string,mixed>
     */
    public function inspect(int $articleId, string $expectedSlug, ?array $publicSmoke = null): array
    {
        $errors = [];

        if ($articleId <= 0) {
            $errors[] = $this->issue('article_id', 'article_id_required', '--article-id is required.');
        }
        if ($expectedSlug === '') {
            $errors[] = $this->issue('expected_slug', 'expected_slug_required', '--expected-slug is required.');
        }

        $article = $articleId > 0 ? $this->article($articleId) : null;
        if (! $article instanceof Article) {
            $errors[] = $this->issue('article', 'article_not_found', 'Article was not found.');

            return $this->summary(
                articleId: $articleId,
                expectedSlug: $expectedSlug,
                canonicalPath: null,
                canonicalUrl: null,
                checks: [],
                errors: $errors,
                publicSmoke: $publicSmoke,
            );
        }

        $canonicalPath = $this->canonicalPathForArticle($article);
        $canonicalUrl = 'https://fermatmind.com'.$canonicalPath;
        $checks = [
            'article' => $this->articleCheck($article, $expectedSlug),
            'seo_meta' => $this->seoMetaCheck($article, $canonicalPath),
            'media' => $this->mediaCheck($article),
            'taxonomy' => $this->taxonomyCheck($article),
            'discoverability' => $this->discoverabilityCheck($article),
            'url_truth' => $this->urlTruthCheck($article, $canonicalUrl),
            'search_channel' => $this->searchChannelCheck($canonicalUrl),
            'schema_hreflang' => $this->schemaHreflangCheck($article),
            'public_html_smoke' => $this->publicSmokeCheck($publicSmoke),
            'gsc_manual' => $this->manualCheck('operator_record_required'),
            'observation' => $this->manualCheck('d1_d7_d14_queue_record_required'),
        ];

        return $this->summary(
            articleId: $articleId,
            expectedSlug: $expectedSlug,
            canonicalPath: $canonicalPath,
            canonicalUrl: $canonicalUrl,
            checks: $checks,
            errors: $errors,
            publicSmoke: $publicSmoke,
        );
    }

    private function article(int $articleId): ?Article
    {
        /** @var Article|null $article */
        $article = Article::query()
            ->withoutGlobalScopes()
            ->with([
                'category' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'tags' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'publishedRevision' => static fn ($relation) => $relation->withoutGlobalScopes(),
                'seoMeta' => static fn ($relation) => $relation->withoutGlobalScopes(),
            ])
            ->find($articleId);

        return $article;
    }

    /**
     * @return array<string,mixed>
     */
    private function articleCheck(Article $article, string $expectedSlug): array
    {
        $issues = [];
        $revision = $article->publishedRevision;

        if ((string) $article->slug !== $expectedSlug) {
            $issues[] = $this->issue('article.slug', 'expected_slug_mismatch', 'Article slug does not match expected lock.', [
                'expected' => $expectedSlug,
                'actual' => (string) $article->slug,
            ]);
        }
        if ((string) $article->status !== 'published') {
            $issues[] = $this->issue('article.status', 'article_not_published', 'Article must be published.');
        }
        if (! (bool) $article->is_public) {
            $issues[] = $this->issue('article.is_public', 'article_not_public', 'Article must be public.');
        }
        if (! (bool) $article->is_indexable) {
            $issues[] = $this->issue('article.is_indexable', 'article_not_indexable', 'Article must be indexable.');
        }
        if ((string) $article->lifecycle_state !== '' && in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            $issues[] = $this->issue('article.lifecycle_state', 'article_lifecycle_not_releasable', 'Article lifecycle state is not releasable.');
        }
        if (! $revision instanceof ArticleTranslationRevision) {
            $issues[] = $this->issue('article.published_revision_id', 'published_revision_missing', 'Article must have a published revision.');
        } elseif ((string) $revision->revision_status !== ArticleTranslationRevision::STATUS_PUBLISHED) {
            $issues[] = $this->issue('published_revision.revision_status', 'published_revision_status_invalid', 'Published revision must have published status.');
        }

        return [
            'ok' => $issues === [],
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'published_revision_id' => (int) $article->published_revision_id,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function seoMetaCheck(Article $article, string $canonicalPath): array
    {
        $issues = [];
        $seoMeta = $article->seoMeta;

        if (! $seoMeta instanceof ArticleSeoMeta) {
            return [
                'ok' => false,
                'exists' => false,
                'issues' => [
                    $this->issue('seo_meta', 'seo_meta_missing', 'Article SEO meta row is required.'),
                ],
            ];
        }

        if ((string) $seoMeta->seo_title === '') {
            $issues[] = $this->issue('seo_meta.seo_title', 'seo_title_missing', 'SEO title is required.');
        }
        if ((string) $seoMeta->seo_description === '') {
            $issues[] = $this->issue('seo_meta.seo_description', 'seo_description_missing', 'SEO description is required.');
        }
        if (! (bool) $seoMeta->is_indexable) {
            $issues[] = $this->issue('seo_meta.is_indexable', 'seo_meta_not_indexable', 'SEO meta must be indexable.');
        }
        if ((string) $seoMeta->robots !== 'index,follow') {
            $issues[] = $this->issue('seo_meta.robots', 'seo_meta_robots_not_index_follow', 'SEO meta robots must be index,follow.');
        }

        $actualCanonicalPath = $this->pathFromCanonical((string) $seoMeta->canonical_url);
        if ($actualCanonicalPath !== $canonicalPath) {
            $issues[] = $this->issue('seo_meta.canonical_url', 'canonical_path_mismatch', 'SEO canonical must match the article route.', [
                'expected_canonical_path' => $canonicalPath,
                'actual_canonical_path' => $actualCanonicalPath,
            ]);
        }

        return [
            'ok' => $issues === [],
            'exists' => true,
            'seo_title_present' => (string) $seoMeta->seo_title !== '',
            'seo_description_present' => (string) $seoMeta->seo_description !== '',
            'canonical_url' => (string) $seoMeta->canonical_url,
            'robots' => (string) $seoMeta->robots,
            'is_indexable' => (bool) $seoMeta->is_indexable,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mediaCheck(Article $article): array
    {
        $seoMeta = $article->seoMeta;
        $bodyUrls = $this->bodyVisualUrls((string) $article->content_md, (string) $article->content_html);
        $urls = [
            'cover_image_url' => (string) $article->cover_image_url,
            'seo_meta.og_image_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->og_image_url : '',
        ];

        foreach ($bodyUrls as $index => $url) {
            $urls['body_visual_urls.'.$index] = $url;
        }

        $issues = [];
        foreach ($urls as $field => $url) {
            if ($url === '') {
                if (in_array($field, ['cover_image_url', 'seo_meta.og_image_url'], true)) {
                    $issues[] = $this->issue($field, 'media_url_missing', 'Required article media URL is missing.');
                }

                continue;
            }

            if (! $this->isPublicMediaUrl($url)) {
                $issues[] = $this->issue($field, 'media_url_not_public_origin', 'Article media URL must use a public FermatMind origin.', [
                    'url' => $url,
                ]);
            }
        }

        return [
            'ok' => $issues === [],
            'cover_image_url' => (string) $article->cover_image_url,
            'og_image_url' => $seoMeta instanceof ArticleSeoMeta ? (string) $seoMeta->og_image_url : null,
            'body_visual_url_count' => count($bodyUrls),
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function taxonomyCheck(Article $article): array
    {
        $category = $article->category;
        $tags = $article->tags;
        $issues = [];
        $zh = str_starts_with((string) $article->locale, 'zh');

        if (! $category instanceof ArticleCategory) {
            $issues[] = $this->issue('article.category_id', 'category_missing', 'Article must have a reader-facing category.');
        } else {
            $categoryName = (string) $category->name;
            if (! (bool) $category->is_active) {
                $issues[] = $this->issue('category.is_active', 'category_inactive', 'Article category must be active.');
            }
            if ($zh && ! $this->isReaderFacingZhLabel($categoryName)) {
                $issues[] = $this->issue('category.name', 'category_not_reader_facing_zh', 'Chinese articles must use a Chinese-facing category label.', [
                    'category_name' => $categoryName,
                ]);
            }
        }

        if ($tags->count() === 0) {
            $issues[] = $this->issue('article.tags', 'tags_missing', 'Article should have reader-facing tags.');
        }

        $tagPayload = $tags
            ->map(static fn (ArticleTag $tag): array => [
                'id' => (int) $tag->id,
                'name' => (string) $tag->name,
                'slug' => (string) $tag->slug,
                'is_active' => (bool) $tag->is_active,
            ])
            ->values()
            ->all();

        return [
            'ok' => $issues === [],
            'category' => $category instanceof ArticleCategory ? [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'is_active' => (bool) $category->is_active,
            ] : null,
            'tags' => $tagPayload,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function discoverabilityCheck(Article $article): array
    {
        $issues = [];

        if (! (bool) $article->sitemap_eligible) {
            $issues[] = $this->issue('article.sitemap_eligible', 'sitemap_eligibility_missing', 'Article must be sitemap eligible.');
        }
        if (! (bool) $article->llms_eligible) {
            $issues[] = $this->issue('article.llms_eligible', 'llms_eligibility_missing', 'Article must be llms eligible.');
        }

        return [
            'ok' => $issues === [],
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'llms_full_source_eligible' => (bool) $article->llms_eligible && (bool) $article->is_indexable,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function urlTruthCheck(Article $article, string $canonicalUrl): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        if (! Schema::connection($connection)->hasTable('seo_urls')) {
            return [
                'ok' => false,
                'table_available' => false,
                'issues' => [
                    $this->issue('seo_urls', 'seo_urls_table_missing', 'URL Truth table is missing.'),
                ],
            ];
        }

        $row = DB::connection($connection)
            ->table('seo_urls')
            ->where('canonical_url_hash', hash('sha256', $canonicalUrl))
            ->where('locale', (string) $article->locale)
            ->first();

        if (! $row) {
            return [
                'ok' => false,
                'table_available' => true,
                'present' => false,
                'issues' => [
                    $this->issue('seo_urls', 'url_truth_missing', 'URL Truth row is missing for the article canonical URL.', [
                        'canonical_url' => $canonicalUrl,
                    ]),
                ],
            ];
        }

        $issues = [];
        if ((string) $row->canonical_url !== $canonicalUrl) {
            $issues[] = $this->issue('seo_urls.canonical_url', 'url_truth_canonical_mismatch', 'URL Truth canonical URL does not match.');
        }
        if ((string) $row->page_entity_type !== 'article') {
            $issues[] = $this->issue('seo_urls.page_entity_type', 'url_truth_entity_type_invalid', 'URL Truth page entity type must be article.');
        }
        if ((string) $row->indexability_state !== 'indexable') {
            $issues[] = $this->issue('seo_urls.indexability_state', 'url_truth_not_indexable', 'URL Truth indexability must be indexable.');
        }
        if ((bool) ($row->is_private_flow ?? false)) {
            $issues[] = $this->issue('seo_urls.is_private_flow', 'url_truth_private_flow', 'URL Truth row must not be private flow.');
        }

        return [
            'ok' => $issues === [],
            'table_available' => true,
            'present' => true,
            'canonical_url' => (string) $row->canonical_url,
            'page_entity_type' => (string) $row->page_entity_type,
            'source_authority' => (string) $row->source_authority,
            'indexability_state' => (string) $row->indexability_state,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function searchChannelCheck(string $canonicalUrl): array
    {
        $connection = (string) config('seo_intel.connection', 'seo_intel');
        if (! Schema::connection($connection)->hasTable('seo_search_channel_queue_items')) {
            return [
                'ok' => false,
                'table_available' => false,
                'channels' => [],
                'issues' => [
                    $this->issue('seo_search_channel_queue_items', 'search_channel_queue_table_missing', 'Search Channel queue table is missing.'),
                ],
            ];
        }

        $rows = DB::connection($connection)
            ->table('seo_search_channel_queue_items')
            ->where('canonical_url', $canonicalUrl)
            ->whereIn('channel', ['indexnow', 'baidu_push'])
            ->orderByDesc('id')
            ->get()
            ->groupBy(static fn (object $row): string => (string) $row->channel);

        $channels = [];
        $issues = [];
        foreach (['indexnow', 'baidu_push'] as $channel) {
            $row = $rows->get($channel)?->first();
            if (! $row) {
                $channels[$channel] = [
                    'present' => false,
                    'ok' => false,
                ];
                $issues[] = $this->issue('search_channel.'.$channel, 'search_channel_queue_missing', 'Search Channel queue item is missing.', [
                    'channel' => $channel,
                ]);

                continue;
            }

            $channelOk = in_array((string) $row->approval_state, ['approved'], true)
                && in_array((string) $row->execution_state, ['accepted', 'submitted', 'submit_succeeded'], true);

            $channels[$channel] = [
                'present' => true,
                'ok' => $channelOk,
                'queue_item_id' => (int) $row->id,
                'approval_state' => (string) $row->approval_state,
                'execution_state' => (string) $row->execution_state,
                'eligibility_state' => (string) $row->eligibility_state,
            ];

            if (! $channelOk) {
                $issues[] = $this->issue('search_channel.'.$channel, 'search_channel_queue_not_accepted', 'Search Channel queue item must be approved and accepted/submitted.', [
                    'channel' => $channel,
                    'queue_item_id' => (int) $row->id,
                    'approval_state' => (string) $row->approval_state,
                    'execution_state' => (string) $row->execution_state,
                ]);
            }
        }

        return [
            'ok' => $issues === [],
            'table_available' => true,
            'channels' => $channels,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function schemaHreflangCheck(Article $article): array
    {
        $seoMeta = $article->seoMeta;
        $issues = [];
        $schema = $seoMeta instanceof ArticleSeoMeta && is_array($seoMeta->schema_json) ? $seoMeta->schema_json : [];
        $package = is_array($schema['editorial_package_v1'] ?? null) ? $schema['editorial_package_v1'] : [];
        $hreflang = is_array($package['hreflang_gate_v1'] ?? null) ? $package['hreflang_gate_v1'] : null;

        $schemaHold = (bool) ($package['schema_hold'] ?? false);
        $hreflangHold = (bool) ($package['hreflang_hold'] ?? false);
        $articleSchemaEnabled = (bool) ($package['article_schema_enabled'] ?? false);
        $breadcrumbSchemaEnabled = (bool) ($package['breadcrumb_schema_enabled'] ?? false);
        $faqSchemaEnabled = (bool) ($package['faq_schema_enabled'] ?? false);
        $hasNoHreflangPolicy = is_array($hreflang)
            && ($hreflang['enabled'] ?? null) === false
            && ($hreflang['policy'] ?? null) === 'no_hreflang';

        if (! $schemaHold && ! $articleSchemaEnabled) {
            $issues[] = $this->issue('schema.article_schema_enabled', 'article_schema_not_enabled', 'Article schema gate is not enabled.');
        }
        if (! $schemaHold && ! $breadcrumbSchemaEnabled) {
            $issues[] = $this->issue('schema.breadcrumb_schema_enabled', 'breadcrumb_schema_not_enabled', 'Breadcrumb schema gate is not enabled.');
        }
        if ($faqSchemaEnabled) {
            $issues[] = $this->issue('schema.faq_schema_enabled', 'faq_schema_not_held', 'FAQ schema should remain held unless explicitly reviewed.');
        }
        if (! $hreflangHold && ! $hasNoHreflangPolicy) {
            $issues[] = $this->issue('hreflang_gate_v1', 'hreflang_policy_missing', 'Hreflang gate must be enabled reciprocally or record no_hreflang policy.');
        }

        return [
            'ok' => $issues === [],
            'schema_state' => $schemaHold ? 'held' : 'enabled_required',
            'hreflang_state' => $hreflangHold ? 'held' : 'policy_required',
            'schema_hold' => $schemaHold,
            'hreflang_hold' => $hreflangHold,
            'article_schema_enabled' => $articleSchemaEnabled,
            'breadcrumb_schema_enabled' => $breadcrumbSchemaEnabled,
            'faq_schema_enabled' => $faqSchemaEnabled,
            'hreflang_no_hreflang_policy_recorded' => $hasNoHreflangPolicy,
            'hreflang_gate_v1' => $hreflang,
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $publicSmoke
     * @return array<string,mixed>
     */
    private function publicSmokeCheck(?array $publicSmoke): array
    {
        if ($publicSmoke === null) {
            return [
                'ok' => null,
                'state' => 'not_provided',
                'issues' => [],
            ];
        }

        $ok = (bool) ($publicSmoke['ok'] ?? false);

        return [
            'ok' => $ok,
            'state' => $ok ? 'passed' : 'failed',
            'issues' => $ok ? [] : [
                $this->issue('public_html_smoke', 'public_html_smoke_failed', 'Public HTML smoke evidence reported failure.', [
                    'decision' => $publicSmoke['decision'] ?? null,
                ]),
            ],
            'evidence' => $publicSmoke,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function manualCheck(string $state): array
    {
        return [
            'ok' => null,
            'state' => $state,
            'issues' => [],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $publicSmoke
     * @param  list<array<string,mixed>>  $errors
     * @param  array<string,mixed>  $checks
     * @return array<string,mixed>
     */
    private function summary(
        int $articleId,
        string $expectedSlug,
        ?string $canonicalPath,
        ?string $canonicalUrl,
        array $checks,
        array $errors,
        ?array $publicSmoke,
    ): array {
        $issues = $errors;
        foreach ($checks as $check) {
            foreach ((array) ($check['issues'] ?? []) as $issue) {
                if (is_array($issue)) {
                    $issues[] = $issue;
                }
            }
        }

        $decision = $this->decision($checks, $issues, $publicSmoke);

        return [
            'ok' => $decision === self::COMPLETE_SEARCH_OBSERVATION_PENDING,
            'decision' => $decision,
            'read_only' => true,
            'external_search_submission_attempted' => false,
            'cms_content_write_attempted' => false,
            'publish_attempted' => false,
            'schema_hreflang_write_attempted' => false,
            'sitemap_llms_mutation_attempted' => false,
            'article_id' => $articleId,
            'expected_slug' => $expectedSlug,
            'canonical_path' => $canonicalPath,
            'canonical_url' => $canonicalUrl,
            'checks' => $checks,
            'issues' => $issues,
            'remaining_operator_inputs' => [
                'public_html_smoke' => $publicSmoke === null ? 'not_provided_use_fap_web_smoke_verifier' : 'provided',
                'gsc_manual_request_indexing' => 'operator_record_required',
                'd1_d7_d14_observation' => 'schedule_or_record_after_release',
            ],
            'allowed_decisions' => [
                self::COMPLETE_SEARCH_OBSERVATION_PENDING,
                self::BLOCKED_DISCOVERABILITY_GAP,
                self::BLOCKED_SEARCH_QUEUE_GAP,
                self::BLOCKED_PUBLIC_HTML_DRIFT,
                self::BLOCKED_OPERATOR_INPUT,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $checks
     * @param  list<array<string,mixed>>  $issues
     * @param  array<string,mixed>|null  $publicSmoke
     */
    private function decision(array $checks, array $issues, ?array $publicSmoke): string
    {
        if (($checks['public_html_smoke']['ok'] ?? null) === false) {
            return self::BLOCKED_PUBLIC_HTML_DRIFT;
        }

        $codes = array_map(static fn (array $issue): string => (string) ($issue['code'] ?? ''), $issues);
        foreach ([
            'article_not_found',
            'article_id_required',
            'expected_slug_required',
            'article_not_published',
            'article_not_public',
            'article_not_indexable',
            'published_revision_missing',
            'published_revision_status_invalid',
            'seo_meta_missing',
            'seo_meta_not_indexable',
            'seo_meta_robots_not_index_follow',
            'canonical_path_mismatch',
            'sitemap_eligibility_missing',
            'llms_eligibility_missing',
            'seo_urls_table_missing',
            'url_truth_missing',
            'url_truth_canonical_mismatch',
            'url_truth_entity_type_invalid',
            'url_truth_not_indexable',
            'url_truth_private_flow',
        ] as $discoverabilityCode) {
            if (in_array($discoverabilityCode, $codes, true)) {
                return self::BLOCKED_DISCOVERABILITY_GAP;
            }
        }

        foreach ([
            'search_channel_queue_table_missing',
            'search_channel_queue_missing',
            'search_channel_queue_not_accepted',
        ] as $searchCode) {
            if (in_array($searchCode, $codes, true)) {
                return self::BLOCKED_SEARCH_QUEUE_GAP;
            }
        }

        if ($issues !== []) {
            return self::BLOCKED_OPERATOR_INPUT;
        }

        return self::COMPLETE_SEARCH_OBSERVATION_PENDING;
    }

    private function canonicalPathForArticle(Article $article): string
    {
        $locale = (string) $article->locale;
        $prefix = str_starts_with($locale, 'zh') ? '/zh/articles/' : '/en/articles/';

        return $prefix.(string) $article->slug;
    }

    private function pathFromCanonical(string $canonical): string
    {
        $path = parse_url($canonical, PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    private function isReaderFacingZhLabel(string $label): bool
    {
        if ($label === '' || $label === 'SEO Articles' || preg_match('/^[a-z0-9_ -]+$/i', $label) === 1 || str_contains($label, '_')) {
            return false;
        }

        return preg_match('/\p{Han}/u', $label) === 1;
    }

    private function isPublicMediaUrl(string $url): bool
    {
        if (PublicMediaUrlGuard::isAllowedPublicMediaUrl($url)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?? 443);

        if ($scheme !== 'https' || ! is_string($host) || $port !== 443) {
            return false;
        }

        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            return false;
        }

        return in_array(strtolower(trim($host, "[] \t\n\r\0\x0B.")), self::CLOSEOUT_PUBLIC_MEDIA_HOSTS, true);
    }

    /**
     * @return list<string>
     */
    private function bodyVisualUrls(string $markdown, string $html): array
    {
        $urls = [];
        if (preg_match_all('/!\[[^\]]*]\((https?:\/\/[^)\s]+)\)/', $markdown, $matches)) {
            foreach ($matches[1] as $url) {
                $urls[] = (string) $url;
            }
        }
        if (preg_match_all('/<img\b[^>]*\bsrc=["\'](https?:\/\/[^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                $urls[] = (string) $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message, array $context = []): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }
}
