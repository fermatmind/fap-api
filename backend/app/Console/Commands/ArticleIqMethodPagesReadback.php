<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PageBlock;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Services\Cms\ArticleBodyHeadingGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

final class ArticleIqMethodPagesReadback extends Command
{
    private const EXPECTED_SCHEMA_VERSION = 'fermatmind.iq_method_pages.cms_readback.v1';

    private const SOURCE_MANIFEST_SCHEMA_VERSION = 'fermatmind.iq_method_pages.cms_dry_run_manifest.v1';

    private const SOURCE_PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-DRY-RUN-01';

    private const READBACK_PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-READBACK-01';

    private const DEFAULT_STATE = [
        'status' => 'draft_review_only',
        'is_public' => false,
        'is_indexable' => false,
        'robots' => 'noindex,follow',
        'sitemap_eligible' => false,
        'llms_eligible' => false,
    ];

    private const PRIVATE_ROUTE_PATTERNS = [
        'attempt_route' => '~/(?:zh/|en/)?attempt(?:/|[?#\s)"\']|$)~i',
        'result_route' => '~/(?:zh/|en/)?(?:result|results)(?:/|[?#\s)"\']|$)~i',
        'order_route' => '~/(?:zh/|en/)?(?:orders|order)(?:/|[?#\s)"\']|$)~i',
        'payment_route' => '~/(?:zh/|en/)?(?:pay|payment)(?:/|[?#\s)"\']|$)~i',
        'share_route' => '~/(?:zh/|en/)?share(?:/|[?#\s)"\']|$)~i',
        'history_route' => '~/(?:zh/|en/)?history(?:/|[?#\s)"\']|$)~i',
        'recovery_route' => '~/(?:zh/|en/)?(?:recover|restore)(?:/|[?#\s)"\']|$)~i',
        'scoring_secret' => '/\b(?:answer_key|correct_answer|scoring_rule|score_formula)\b/i',
    ];

    protected $signature = 'articles:iq-method-pages-readback
        {--package= : Path to fap-web root, generated/iq-method-pages-zh-cn-v0.2, or its cms-dry-run directory}
        {--artifact-dir= : Optional directory for a readback JSON evidence artifact}
        {--json : Emit a JSON summary}';

    protected $description = 'Read back the zh-CN IQ method pages CMS draft import and verify it still matches the noindex dry-run manifest.';

    public function __construct(private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $summary = $this->readback();
            $this->writeArtifactIfRequested($summary);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary('runtime_error', $exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary('unexpected_error', $exception->getMessage());
        }

        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function readback(): array
    {
        $issues = [];
        $paths = $this->resolvePackagePaths((string) $this->option('package'), $issues);
        $manifest = [];
        $topicMapping = [];
        $landingMapping = [];
        $articles = [];
        $topicReadback = null;
        $landingReadback = null;

        if ($paths !== null) {
            $manifest = $this->readJson($paths['dry_run_root'].'/cms_import_manifest.json', 'cms_import_manifest.json', $issues);
            $topicMapping = $this->readJson($paths['dry_run_root'].'/topic_iq_articles_mapping.json', 'topic_iq_articles_mapping.json', $issues);
            $landingMapping = $this->readJson($paths['dry_run_root'].'/landing_page_blocks_mapping.json', 'landing_page_blocks_mapping.json', $issues);

            $this->validateManifestEnvelope($manifest, $issues);
            $articles = $this->readbackArticles($paths, $manifest, $issues);
            $topicReadback = $this->readbackTopic($topicMapping, $articles, $issues);
            $landingReadback = $this->readbackLanding($landingMapping, $articles, $issues);
        }

        return [
            'schema_version' => self::EXPECTED_SCHEMA_VERSION,
            'ok' => $issues === [],
            'status' => $issues === [] ? 'pass' : 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->toIso8601String(),
            'pr_id' => self::READBACK_PR_ID,
            'source_pr_id' => (string) ($manifest['pr_id'] ?? ''),
            'source_manifest' => [
                'schema_version' => (string) ($manifest['schema_version'] ?? ''),
                'package_root' => $paths['package_root'] ?? null,
                'dry_run_root' => $paths['dry_run_root'] ?? null,
            ],
            'expected_article_count' => 7,
            'article_readbacks' => $articles,
            'topic_readback' => $topicReadback,
            'landing_readback' => $landingReadback,
            'mismatch_count' => count($issues),
            'issues' => $issues,
            'side_effects' => [
                'db_write' => false,
                'cms_update' => false,
                'publish' => false,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'deploy' => false,
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array{source_base:string,package_root:string,dry_run_root:string}|null
     */
    private function resolvePackagePaths(string $package, array &$issues): ?array
    {
        $path = trim($package);
        if ($path === '') {
            $issues[] = $this->issue('package', 'missing_package', '--package is required.');

            return null;
        }

        $root = realpath($path);
        if (! is_string($root) || ! is_dir($root)) {
            $issues[] = $this->issue('package', 'package_directory_not_found', 'Package directory not found.');

            return null;
        }

        $candidates = [
            [
                'source_base' => $root,
                'package_root' => $root.'/generated/iq-method-pages-zh-cn-v0.2',
                'dry_run_root' => $root.'/generated/iq-method-pages-zh-cn-v0.2/cms-dry-run',
            ],
            [
                'source_base' => dirname(dirname($root)),
                'package_root' => $root,
                'dry_run_root' => $root.'/cms-dry-run',
            ],
            [
                'source_base' => dirname(dirname(dirname($root))),
                'package_root' => dirname($root),
                'dry_run_root' => $root,
            ],
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate['dry_run_root'].'/cms_import_manifest.json')) {
                return $candidate;
            }
        }

        $issues[] = $this->issue('cms_import_manifest.json', 'manifest_not_found', 'cms_import_manifest.json must exist under cms-dry-run.');

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function readJson(string $path, string $field, array &$issues): array
    {
        if (! is_file($path)) {
            $issues[] = $this->issue($field, 'json_file_not_found', $field.' was not found.');

            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $issues[] = $this->issue($field, 'invalid_json', $exception->getMessage());

            return [];
        }

        if (! is_array($decoded)) {
            $issues[] = $this->issue($field, 'invalid_json', $field.' must decode to an object.');

            return [];
        }

        return $decoded;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function validateManifestEnvelope(array $manifest, array &$issues): void
    {
        if ((string) ($manifest['schema_version'] ?? '') !== self::SOURCE_MANIFEST_SCHEMA_VERSION) {
            $issues[] = $this->issue('manifest.schema_version', 'unexpected_schema_version', 'Unexpected IQ method pages dry-run manifest schema.');
        }
        if ((string) ($manifest['pr_id'] ?? '') !== self::SOURCE_PR_ID) {
            $issues[] = $this->issue('manifest.pr_id', 'unexpected_source_pr_id', 'Manifest source PR id mismatch.');
        }
        if (count((array) ($manifest['article_imports'] ?? [])) !== 7) {
            $issues[] = $this->issue('manifest.article_imports', 'article_count_mismatch', 'Exactly seven IQ method article imports are required.');
        }
        foreach (self::DEFAULT_STATE as $field => $expected) {
            if (data_get($manifest, 'required_default_publish_state.'.$field) !== $expected) {
                $issues[] = $this->issue('manifest.required_default_publish_state.'.$field, 'default_state_mismatch', $field.' must remain draft/noindex.');
            }
        }
    }

    /**
     * @param  array{source_base:string,package_root:string,dry_run_root:string}  $paths
     * @param  list<array<string,mixed>>  $issues
     * @return list<array<string,mixed>>
     */
    private function readbackArticles(array $paths, array $manifest, array &$issues): array
    {
        $readbacks = [];

        foreach ((array) ($manifest['article_imports'] ?? []) as $index => $import) {
            if (! is_array($import)) {
                $issues[] = $this->issue('manifest.article_imports.'.$index, 'invalid_article_import', 'Article import must be an object.');

                continue;
            }

            $expected = $this->expectedArticle($paths, $import, $issues);
            $slug = (string) ($expected['slug'] ?? $import['slug'] ?? '');
            $article = Article::query()
                ->withoutGlobalScopes()
                ->with(['seoMeta', 'workingRevision'])
                ->where('org_id', 0)
                ->where('locale', 'zh-CN')
                ->where('slug', $slug)
                ->first();

            if (! $article instanceof Article) {
                $issues[] = $this->issue($slug, 'article_missing', 'CMS Article draft was not found.');
                $readbacks[] = [
                    'slug' => $slug,
                    'ok' => false,
                    'status' => 'missing',
                ];

                continue;
            }

            $articleIssuesBefore = count($issues);
            $this->assertSameValue($issues, $slug.'.title', (string) $expected['title'], (string) $article->title);
            $this->assertSameValue($issues, $slug.'.excerpt', (string) $expected['excerpt'], (string) $article->excerpt);
            $this->assertSameValue($issues, $slug.'.content_md', (string) $expected['content_md'], (string) $article->content_md);
            $this->assertSameValue($issues, $slug.'.related_test_slug', 'iq-test-intelligence-quotient-assessment', (string) $article->related_test_slug);
            $this->assertSameValue($issues, $slug.'.translation_group_id', 'iq-method-pages-zh-cn-v0-2-'.$slug, (string) $article->translation_group_id);
            $this->assertDraftState($issues, $slug.'.article', [
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
            ]);
            if ($article->published_at !== null || $article->published_revision_id !== null) {
                $issues[] = $this->issue($slug.'.article.publication', 'published_marker_present', 'Draft readback must not have publication markers.');
            }

            $revision = $article->workingRevision;
            if (! $revision instanceof ArticleTranslationRevision) {
                $issues[] = $this->issue($slug.'.working_revision', 'working_revision_missing', 'Working revision was not found.');
            } else {
                $this->assertSameValue($issues, $slug.'.working_revision.status', ArticleTranslationRevision::STATUS_HUMAN_REVIEW, (string) $revision->revision_status);
                $this->assertSameValue($issues, $slug.'.working_revision.title', (string) $expected['title'], (string) $revision->title);
                $this->assertSameValue($issues, $slug.'.working_revision.content_md', (string) $expected['content_md'], (string) $revision->content_md);
                $this->assertSameValue($issues, $slug.'.working_revision.seo_title', (string) $expected['seo_title'], (string) $revision->seo_title);
                $this->assertSameValue($issues, $slug.'.working_revision.seo_description', (string) $expected['seo_description'], (string) $revision->seo_description);
            }

            $seo = $article->seoMeta;
            if (! $seo instanceof ArticleSeoMeta) {
                $issues[] = $this->issue($slug.'.seo_meta', 'seo_meta_missing', 'Article SEO meta was not found.');
            } else {
                $this->assertSameValue($issues, $slug.'.seo_meta.seo_title', (string) $expected['seo_title'], (string) $seo->seo_title);
                $this->assertSameValue($issues, $slug.'.seo_meta.seo_description', (string) $expected['seo_description'], (string) $seo->seo_description);
                $this->assertSameValue($issues, $slug.'.seo_meta.canonical_url', (string) $expected['canonical_url'], (string) $seo->canonical_url);
                $this->assertSameValue($issues, $slug.'.seo_meta.robots', self::DEFAULT_STATE['robots'], (string) $seo->robots);
                $this->assertSameValue($issues, $slug.'.seo_meta.is_indexable', false, (bool) $seo->is_indexable);
            }

            $this->assertNoPrivateTokens($issues, $slug.'.public_article_surface', json_encode([
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content_md' => $article->content_md,
                'seo' => $seo instanceof ArticleSeoMeta ? [
                    'seo_title' => $seo->seo_title,
                    'seo_description' => $seo->seo_description,
                    'canonical_url' => $seo->canonical_url,
                ] : [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            $readbacks[] = [
                'slug' => $slug,
                'article_id' => (int) $article->id,
                'working_revision_id' => $revision instanceof ArticleTranslationRevision ? (int) $revision->id : null,
                'ok' => count($issues) === $articleIssuesBefore,
                'status' => (string) $article->status,
                'is_public' => (bool) $article->is_public,
                'is_indexable' => (bool) $article->is_indexable,
                'robots' => $seo instanceof ArticleSeoMeta ? (string) $seo->robots : null,
                'sitemap_eligible' => (bool) $article->sitemap_eligible,
                'llms_eligible' => (bool) $article->llms_eligible,
                'canonical_url' => $seo instanceof ArticleSeoMeta ? (string) $seo->canonical_url : null,
            ];
        }

        return $readbacks;
    }

    /**
     * @param  array{source_base:string,package_root:string,dry_run_root:string}  $paths
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function expectedArticle(array $paths, array $import, array &$issues): array
    {
        $slug = Str::slug((string) ($import['slug'] ?? ''));
        $cms = $this->readSourceJson($paths['source_base'], data_get($import, 'source_files.article_cms_json'), $slug.'.article.cms.json', $issues);
        $seo = $this->readSourceJson($paths['source_base'], data_get($import, 'source_files.seo_json'), $slug.'.seo.json', $issues);
        $articleMd = $this->readSourceText($paths['source_base'], data_get($import, 'source_files.article_md'), $slug.'.article.md', $issues);
        $contentMd = trim((string) ($cms['content_md'] ?? $articleMd));

        return [
            'slug' => Str::slug((string) ($cms['slug'] ?? $slug)),
            'title' => trim((string) ($cms['title'] ?? $import['title'] ?? '')),
            'excerpt' => trim((string) ($cms['excerpt'] ?? '')),
            'content_md' => $this->articleBodyHeadingGuard->downgradeMarkdownH1ToH2($contentMd),
            'canonical_url' => trim((string) ($seo['canonical_url'] ?? $cms['canonical_url'] ?? $import['canonical_url'] ?? '')),
            'seo_title' => trim((string) ($seo['seo_title'] ?? $cms['title'] ?? $import['title'] ?? '')),
            'seo_description' => trim((string) ($seo['seo_description'] ?? $cms['excerpt'] ?? '')),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @return array<string,mixed>
     */
    private function readSourceJson(string $sourceBase, mixed $relativePath, string $field, array &$issues): array
    {
        $path = $this->sourcePath($sourceBase, $relativePath, $field, $issues);

        return $path !== null ? $this->readJson($path, $field, $issues) : [];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function readSourceText(string $sourceBase, mixed $relativePath, string $field, array &$issues): string
    {
        $path = $this->sourcePath($sourceBase, $relativePath, $field, $issues);

        return $path !== null ? trim((string) file_get_contents($path)) : '';
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function sourcePath(string $sourceBase, mixed $relativePath, string $field, array &$issues): ?string
    {
        $relative = trim((string) $relativePath);
        if ($relative === '') {
            $issues[] = $this->issue($field, 'missing_source_path', 'Required source file path is missing.');

            return null;
        }

        $path = realpath($sourceBase.'/'.ltrim($relative, '/'));
        if (! is_string($path) || ! is_file($path)) {
            $issues[] = $this->issue($field, 'source_file_not_found', 'Referenced source file was not found.');

            return null;
        }

        return $path;
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @param  list<array<string,mixed>>  $articles
     * @return array<string,mixed>
     */
    private function readbackTopic(array $topicMapping, array $articles, array &$issues): array
    {
        $expectedSlugs = collect($articles)->pluck('slug')->filter()->values()->all();
        $profile = TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'iq-eq')
            ->first();

        if (! $profile instanceof TopicProfile) {
            $issues[] = $this->issue('topic.iq-eq', 'topic_missing', 'IQ/EQ topic profile was not found.');

            return [
                'ok' => false,
                'slug' => 'iq-eq',
                'status' => 'missing',
            ];
        }

        $this->assertSameValue($issues, 'topic.iq-eq.status', TopicProfile::STATUS_DRAFT, (string) $profile->status);
        $this->assertSameValue($issues, 'topic.iq-eq.is_public', false, (bool) $profile->is_public);
        $this->assertSameValue($issues, 'topic.iq-eq.is_indexable', false, (bool) $profile->is_indexable);
        $this->assertSameValue($issues, 'topic.mapping.group_key', 'iq_articles', (string) data_get($topicMapping, 'entry_groups.0.group_key'));
        $this->assertSameValue($issues, 'topic.mapping.label', 'IQ 文章', (string) data_get($topicMapping, 'entry_groups.0.label'));

        $entries = TopicProfileEntry::query()
            ->where('profile_id', (int) $profile->id)
            ->where('group_key', 'iq_articles')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $actualSlugs = $entries->pluck('target_key')->values()->all();
        if ($actualSlugs !== $expectedSlugs) {
            $issues[] = $this->issue('topic.iq_articles.items', 'topic_items_mismatch', 'Topic IQ article entries do not match the manifest order.');
        }

        foreach ($entries as $entry) {
            $this->assertNoPrivateTokens($issues, 'topic.iq_articles.'.$entry->target_key, json_encode([
                'target_url_override' => $entry->target_url_override,
                'payload_json' => $entry->payload_json,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }

        return [
            'ok' => $actualSlugs === $expectedSlugs,
            'topic_profile_id' => (int) $profile->id,
            'slug' => 'iq-eq',
            'status' => (string) $profile->status,
            'is_public' => (bool) $profile->is_public,
            'is_indexable' => (bool) $profile->is_indexable,
            'group_key' => 'iq_articles',
            'expected_items_count' => count($expectedSlugs),
            'actual_items_count' => $entries->count(),
            'slugs' => $actualSlugs,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @param  list<array<string,mixed>>  $articles
     * @return array<string,mixed>
     */
    private function readbackLanding(array $landingMapping, array $articles, array &$issues): array
    {
        $expectedSlugs = collect($articles)->pluck('slug')->filter()->values()->all();
        $surface = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('surface_key', 'test:iq-test-intelligence-quotient-assessment')
            ->where('locale', 'zh-CN')
            ->first();

        if (! $surface instanceof LandingSurface) {
            $issues[] = $this->issue('landing_surface.iq', 'landing_surface_missing', 'IQ landing surface was not found.');

            return [
                'ok' => false,
                'surface_key' => 'test:iq-test-intelligence-quotient-assessment',
                'status' => 'missing',
            ];
        }

        $this->assertSameValue($issues, 'landing_surface.iq.status', LandingSurface::STATUS_DRAFT, (string) $surface->status);
        $this->assertSameValue($issues, 'landing_surface.iq.is_public', false, (bool) $surface->is_public);
        $this->assertSameValue($issues, 'landing_surface.iq.is_indexable', false, (bool) $surface->is_indexable);
        $this->assertSameValue($issues, 'landing.mapping.frontend_hardcode_allowed', false, (bool) data_get($landingMapping, 'guardrails.frontend_hardcode_allowed'));

        $block = PageBlock::query()
            ->where('landing_surface_id', (int) $surface->id)
            ->where('block_key', 'iq_methodology_boundary_links')
            ->first();

        if (! $block instanceof PageBlock) {
            $issues[] = $this->issue('landing_block.iq_methodology_boundary_links', 'landing_block_missing', 'IQ methodology boundary links block was not found.');

            return [
                'ok' => false,
                'surface_key' => 'test:iq-test-intelligence-quotient-assessment',
                'block_key' => 'iq_methodology_boundary_links',
                'status' => (string) $surface->status,
            ];
        }

        $items = collect((array) data_get($block->payload_json, 'items', []));
        $actualSlugs = $items->pluck('slug')->values()->all();
        if ($actualSlugs !== $expectedSlugs) {
            $issues[] = $this->issue('landing_block.iq_methodology_boundary_links.items', 'landing_items_mismatch', 'Landing block article links do not match the manifest order.');
        }
        $this->assertSameValue($issues, 'landing_block.frontend_hardcode_allowed', false, (bool) data_get($block->payload_json, 'frontend_hardcode_allowed'));
        $this->assertSameValue($issues, 'landing_block.publish_or_indexing_change_allowed', false, (bool) data_get($block->payload_json, 'publish_or_indexing_change_allowed'));
        $this->assertNoPrivateTokens($issues, 'landing_block.iq_methodology_boundary_links.payload', json_encode($block->payload_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        return [
            'ok' => $actualSlugs === $expectedSlugs,
            'landing_surface_id' => (int) $surface->id,
            'block_id' => (int) $block->id,
            'surface_key' => 'test:iq-test-intelligence-quotient-assessment',
            'block_key' => 'iq_methodology_boundary_links',
            'status' => (string) $surface->status,
            'is_public' => (bool) $surface->is_public,
            'is_indexable' => (bool) $surface->is_indexable,
            'expected_items_count' => count($expectedSlugs),
            'actual_items_count' => $items->count(),
            'slugs' => $actualSlugs,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     * @param  array<string,mixed>  $actual
     */
    private function assertDraftState(array &$issues, string $field, array $actual): void
    {
        foreach (self::DEFAULT_STATE as $stateField => $expected) {
            if ($stateField === 'robots') {
                continue;
            }

            $this->assertSameValue($issues, $field.'.'.$stateField, $expected, $actual[$stateField] ?? null);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function assertSameValue(array &$issues, string $field, mixed $expected, mixed $actual): void
    {
        if ($actual !== $expected) {
            $issues[] = $this->issue($field, 'value_mismatch', sprintf(
                'Expected %s but read back %s.',
                json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ));
        }
    }

    /**
     * @param  list<array<string,mixed>>  $issues
     */
    private function assertNoPrivateTokens(array &$issues, string $field, string $text): void
    {
        foreach (self::PRIVATE_ROUTE_PATTERNS as $code => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $issues[] = $this->issue($field, $code, 'Public readback surface contains a private route or scoring token.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeArtifactIfRequested(array $summary): void
    {
        $artifactDir = trim((string) $this->option('artifact-dir'));
        if ($artifactDir === '') {
            return;
        }

        if (! is_dir($artifactDir) && ! mkdir($artifactDir, 0777, true) && ! is_dir($artifactDir)) {
            throw new RuntimeException('Unable to create artifact directory.');
        }

        file_put_contents(
            rtrim($artifactDir, '/').'/iq-method-pages-cms-readback.v1.json',
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'blocked'));
        $this->line('dry_run=1');
        $this->line('execute=0');
        $this->line('expected_article_count='.(string) ($summary['expected_article_count'] ?? 0));
        $this->line('mismatch_count='.(string) ($summary['mismatch_count'] ?? 0));

        foreach ((array) ($summary['issues'] ?? []) as $issue) {
            if (is_array($issue)) {
                $this->line('readback_issue='.$this->issueLine($issue));
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $code, string $message): array
    {
        return [
            'schema_version' => self::EXPECTED_SCHEMA_VERSION,
            'ok' => false,
            'status' => 'blocked',
            'dry_run' => true,
            'execute' => false,
            'generated_at' => now()->toIso8601String(),
            'pr_id' => self::READBACK_PR_ID,
            'mismatch_count' => 1,
            'issues' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'side_effects' => [
                'db_write' => false,
                'cms_update' => false,
                'publish' => false,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'deploy' => false,
            ],
        ];
    }

    /**
     * @return array<string,string>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string,mixed>  $issue
     */
    private function issueLine(array $issue): string
    {
        return implode(':', [
            (string) ($issue['field'] ?? 'unknown'),
            (string) ($issue['code'] ?? 'unknown'),
            (string) ($issue['message'] ?? ''),
        ]);
    }
}
