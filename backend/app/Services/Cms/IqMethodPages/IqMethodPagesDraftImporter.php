<?php

declare(strict_types=1);

namespace App\Services\Cms\IqMethodPages;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTag;
use App\Models\ArticleTranslationRevision;
use App\Models\LandingSurface;
use App\Models\PageBlock;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class IqMethodPagesDraftImporter
{
    private const EXPECTED_SCHEMA_VERSION = 'fermatmind.iq_method_pages.cms_dry_run_manifest.v1';

    private const EXPECTED_PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-DRY-RUN-01';

    private const EXPECTED_IMPORT_PR_ID = 'IQ-METHOD-PAGES-ZH-CN-CMS-IMPORT-01';

    private const CONTENT_TRACK = 'iq_method_pages_zh_cn_v0_2';

    private const DEFAULT_STATE = [
        'status' => 'draft_review_only',
        'is_public' => false,
        'is_indexable' => false,
        'robots' => 'noindex,follow',
        'sitemap_eligible' => false,
        'llms_eligible' => false,
    ];

    private const FORBIDDEN_TEXT_PATTERNS = [
        'answer_key' => '/\banswer_key\b/i',
        'correct_answer' => '/\bcorrect_answer\b/i',
        'pdf_certificate' => '/\bPDF\s+certificate\b/i',
        'private_result_route' => '~(?<![A-Za-z0-9_-])/(?:zh/|en/)?(?:result|results|orders|order|share|pay|payment|history|recover|restore)(?:/|[?#\s)"\']|$)~i',
        'sensitive_query_key' => '/(?:[?&]|^)(?:result_id|order_id|payment_id|token|score|user_id|report_id)=/i',
    ];

    public function __construct(
        private readonly ArticleTranslationRevisionWorkspace $revisionWorkspace,
        private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function planFromDirectory(array $options): array
    {
        return $this->buildPlan($options);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function importFromDirectory(array $options): array
    {
        $plan = $this->buildPlan($options);
        if (($plan['ok'] ?? false) !== true) {
            return $plan;
        }

        $articles = [];
        $topic = null;
        $landing = null;
        $items = is_array($plan['package_items'] ?? null) ? $plan['package_items'] : [];

        DB::transaction(function () use ($items, $plan, &$articles, &$topic, &$landing): void {
            foreach ($items as $item) {
                if (is_array($item)) {
                    $articles[] = $this->writeArticleDraft($item);
                }
            }

            $topic = $this->writeTopicDraftLinks($plan, $articles);
            $landing = $this->writeLandingDraftBlock($plan);
        });

        return [
            ...$plan,
            'dry_run' => false,
            'action' => 'imported_draft_only',
            'would_write' => true,
            'articles' => $articles,
            'topic_mapping' => $topic,
            'landing_page_blocks' => $landing,
            'package_items' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildPlan(array $options): array
    {
        $errors = [];
        $warnings = [];
        $paths = $this->resolvePackagePaths((string) ($options['package'] ?? ''), $errors);
        $manifest = [];
        $topicMapping = [];
        $landingMapping = [];
        $seoGate = [];
        $claimAudit = [];
        $items = [];

        if ($paths !== null) {
            $manifest = $this->readJson($paths['dry_run_root'].'/cms_import_manifest.json', 'cms_import_manifest.json', $errors);
            $topicMapping = $this->readJson($paths['dry_run_root'].'/topic_iq_articles_mapping.json', 'topic_iq_articles_mapping.json', $errors);
            $landingMapping = $this->readJson($paths['dry_run_root'].'/landing_page_blocks_mapping.json', 'landing_page_blocks_mapping.json', $errors);
            $seoGate = $this->readJson($paths['dry_run_root'].'/seo_geo_gate.json', 'seo_geo_gate.json', $errors);
            $claimAudit = $this->readJson($paths['dry_run_root'].'/claim_audit_summary.json', 'claim_audit_summary.json', $errors);

            $this->validateManifestEnvelope($manifest, $seoGate, $claimAudit, $errors);
            $items = $this->buildItems($paths, $manifest, $seoGate, $claimAudit, $errors, $warnings);
            $this->validateTopicMapping($topicMapping, $items, $errors);
            $this->validateLandingMapping($landingMapping, $items, $errors);
        }

        $ok = $errors === [];
        $plannedArticles = $ok ? array_map(fn (array $item): array => $this->plannedArticle($item), $items) : [];

        return [
            'ok' => $ok,
            'dry_run' => (bool) ($options['dry_run'] ?? true),
            'action' => $ok ? $this->summaryAction($plannedArticles) : 'will_skip',
            'would_write' => $ok,
            'pr_id' => self::EXPECTED_IMPORT_PR_ID,
            'source_pr_id' => (string) ($manifest['pr_id'] ?? ''),
            'package_root' => $paths['package_root'] ?? null,
            'dry_run_root' => $paths['dry_run_root'] ?? null,
            'source_base' => $paths['source_base'] ?? null,
            'default_state' => self::DEFAULT_STATE,
            'articles' => $plannedArticles,
            'topic_mapping' => $ok ? $this->plannedTopicMapping($topicMapping, $items) : null,
            'landing_page_blocks' => $ok ? $this->plannedLandingBlock($landingMapping) : null,
            'topic_mapping_payload' => $topicMapping,
            'landing_mapping_payload' => $landingMapping,
            'package_items' => $items,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return array{source_base:string,package_root:string,dry_run_root:string}|null
     */
    private function resolvePackagePaths(string $package, array &$errors): ?array
    {
        $path = trim($package);
        if ($path === '') {
            $errors[] = $this->issue('package', 'missing_package', '--package is required.');

            return null;
        }

        $root = realpath($path);
        if (! is_string($root) || ! is_dir($root)) {
            $errors[] = $this->issue('package', 'package_directory_not_found', 'Package directory not found.');

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

        $errors[] = $this->issue('cms_import_manifest.json', 'manifest_not_found', 'cms_import_manifest.json must exist under cms-dry-run.');

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>
     */
    private function readJson(string $path, string $field, array &$errors): array
    {
        if (! is_file($path)) {
            $errors[] = $this->issue($field, 'json_file_not_found', $field.' was not found.');

            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $errors[] = $this->issue($field, 'invalid_json', $exception->getMessage());

            return [];
        }

        if (! is_array($decoded)) {
            $errors[] = $this->issue($field, 'invalid_json', $field.' must decode to an object.');

            return [];
        }

        return $decoded;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateManifestEnvelope(array $manifest, array $seoGate, array $claimAudit, array &$errors): void
    {
        if ((string) ($manifest['schema_version'] ?? '') !== self::EXPECTED_SCHEMA_VERSION) {
            $errors[] = $this->issue('manifest.schema_version', 'unexpected_schema_version', 'Unexpected IQ method pages dry-run manifest schema.');
        }
        if ((string) ($manifest['pr_id'] ?? '') !== self::EXPECTED_PR_ID) {
            $errors[] = $this->issue('manifest.pr_id', 'unexpected_source_pr_id', 'Manifest source PR id mismatch.');
        }
        if ((string) ($manifest['mode'] ?? '') !== 'cms_dry_run_contract_only') {
            $errors[] = $this->issue('manifest.mode', 'unexpected_mode', 'Manifest must be a CMS dry-run contract.');
        }
        if (count((array) ($manifest['article_imports'] ?? [])) !== 7) {
            $errors[] = $this->issue('manifest.article_imports', 'article_count_mismatch', 'Exactly seven IQ method article imports are required.');
        }

        foreach (self::DEFAULT_STATE as $field => $expected) {
            $actual = data_get($manifest, 'required_default_publish_state.'.$field);
            if ($actual !== $expected) {
                $errors[] = $this->issue('manifest.required_default_publish_state.'.$field, 'default_state_mismatch', $field.' must remain draft/noindex.');
            }

            $seoActual = data_get($seoGate, 'default_gate.'.$field);
            if ($seoActual !== $expected) {
                $errors[] = $this->issue('seo_geo_gate.default_gate.'.$field, 'seo_gate_mismatch', $field.' must remain draft/noindex.');
            }
        }

        if ((string) ($claimAudit['gate_result'] ?? '') !== 'pass_with_human_review_required') {
            $errors[] = $this->issue('claim_audit.gate_result', 'claim_gate_not_passed', 'Automated claim gate must pass with human review required.');
        }
    }

    /**
     * @param  array{source_base:string,package_root:string,dry_run_root:string}  $paths
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return list<array<string,mixed>>
     */
    private function buildItems(array $paths, array $manifest, array $seoGate, array $claimAudit, array &$errors, array &$warnings): array
    {
        $seoBySlug = collect((array) ($seoGate['pages'] ?? []))->keyBy('slug');
        $claimBySlug = collect((array) ($claimAudit['pages'] ?? []))->keyBy('slug');
        $items = [];

        foreach ((array) ($manifest['article_imports'] ?? []) as $index => $import) {
            if (! is_array($import)) {
                $errors[] = $this->issue('manifest.article_imports.'.$index, 'invalid_article_import', 'Article import must be an object.');

                continue;
            }

            $slug = trim((string) ($import['slug'] ?? ''));
            $cmsPath = $this->sourcePath($paths['source_base'], data_get($import, 'source_files.article_cms_json'), $errors);
            $seoPath = $this->sourcePath($paths['source_base'], data_get($import, 'source_files.seo_json'), $errors);
            $bodyPath = $this->sourcePath($paths['source_base'], data_get($import, 'source_files.article_md'), $errors);
            $answerSurfacePath = $this->sourcePath($paths['source_base'], data_get($import, 'source_files.answer_surface_v1_json'), $errors);
            $internalLinksPath = $this->sourcePath($paths['source_base'], data_get($import, 'source_files.internal_links_json'), $errors);
            $mediaBriefPath = $this->sourcePath($paths['source_base'], data_get($import, 'source_files.media_brief_json'), $errors);

            $cms = $cmsPath !== null ? $this->readJson($cmsPath, $slug.'.article.cms.json', $errors) : [];
            $seo = $seoPath !== null ? $this->readJson($seoPath, $slug.'.seo.json', $errors) : [];
            $answerSurface = $answerSurfacePath !== null ? $this->readJson($answerSurfacePath, $slug.'.answer_surface_v1.json', $errors) : [];
            $internalLinks = $internalLinksPath !== null ? $this->readJson($internalLinksPath, $slug.'.internal_links.json', $errors) : [];
            $mediaBrief = $mediaBriefPath !== null ? $this->readJson($mediaBriefPath, $slug.'.media_brief.json', $errors) : [];
            $body = $bodyPath !== null && is_file($bodyPath) ? trim((string) file_get_contents($bodyPath)) : '';
            $contentMd = trim((string) ($cms['content_md'] ?? $body));

            $item = [
                'order' => (int) ($import['order'] ?? ($index + 1)),
                'locale' => (string) ($cms['locale'] ?? $import['locale'] ?? ''),
                'slug' => Str::slug((string) ($cms['slug'] ?? $slug)),
                'title' => trim((string) ($cms['title'] ?? $import['title'] ?? '')),
                'excerpt' => trim((string) ($cms['excerpt'] ?? '')),
                'content_md' => $this->articleBodyHeadingGuard->downgradeMarkdownH1ToH2($contentMd),
                'canonical_url' => trim((string) ($seo['canonical_url'] ?? $cms['canonical_url'] ?? $import['canonical_url'] ?? '')),
                'seo_title' => trim((string) ($seo['seo_title'] ?? $cms['title'] ?? $import['title'] ?? '')),
                'seo_description' => trim((string) ($seo['seo_description'] ?? $cms['excerpt'] ?? '')),
                'robots' => trim((string) ($seo['robots'] ?? $cms['robots'] ?? data_get($import, 'publish_state.robots') ?? '')),
                'category' => trim((string) (data_get($import, 'required_cms_fields.category') ?? $cms['category_suggestion'] ?? '测评方法与边界')),
                'tags' => array_values(array_filter(array_map(static fn (mixed $tag): string => trim((string) $tag), (array) ($cms['tags'] ?? data_get($import, 'required_cms_fields.tags') ?? [])))),
                'related_test_slug' => trim((string) ($cms['related_test_slug'] ?? data_get($import, 'required_cms_fields.related_test_slug') ?? '')),
                'schema_json' => is_array($cms['schema_json'] ?? null) ? $cms['schema_json'] : [],
                'answer_surface_v1' => $answerSurface,
                'internal_links' => $internalLinks,
                'media_brief' => $mediaBrief,
                'claim_audit' => $claimBySlug->get($slug, []),
                'seo_gate' => $seoBySlug->get($slug, []),
                'source_files' => $import['source_files'] ?? [],
            ];

            $this->validateItem($item, $errors, $warnings);
            $items[] = $item;
        }

        usort($items, static fn (array $a, array $b): int => ((int) $a['order']) <=> ((int) $b['order']));

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function sourcePath(string $sourceBase, mixed $relativePath, array &$errors): ?string
    {
        $relative = trim((string) $relativePath);
        if ($relative === '') {
            $errors[] = $this->issue('source_files', 'missing_source_path', 'Required source file path is missing.');

            return null;
        }

        $path = realpath($sourceBase.'/'.ltrim($relative, '/'));
        if (! is_string($path) || ! is_file($path)) {
            $errors[] = $this->issue($relative, 'source_file_not_found', 'Referenced source file was not found.');

            return null;
        }

        return $path;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     */
    private function validateItem(array $item, array &$errors, array &$warnings): void
    {
        $slug = (string) ($item['slug'] ?? '');
        foreach (['locale', 'slug', 'title', 'excerpt', 'content_md', 'canonical_url', 'seo_title', 'seo_description', 'robots', 'category', 'related_test_slug'] as $field) {
            if (trim((string) ($item[$field] ?? '')) === '') {
                $errors[] = $this->issue($slug.'.'.$field, 'missing_required_field', $field.' is required.');
            }
        }
        if ((string) ($item['locale'] ?? '') !== 'zh-CN') {
            $errors[] = $this->issue($slug.'.locale', 'unsupported_locale', 'IQ method pages import is zh-CN only.');
        }
        if (! str_starts_with((string) ($item['canonical_url'] ?? ''), 'https://fermatmind.com/zh/articles/'.$slug)) {
            $errors[] = $this->issue($slug.'.canonical_url', 'canonical_mismatch', 'Canonical URL must match the zh article slug.');
        }
        if ((string) ($item['robots'] ?? '') !== self::DEFAULT_STATE['robots']) {
            $errors[] = $this->issue($slug.'.robots', 'robots_mismatch', 'Robots must remain noindex,follow.');
        }
        if ((string) data_get($item, 'seo_gate.robots') !== self::DEFAULT_STATE['robots']) {
            $errors[] = $this->issue($slug.'.seo_gate.robots', 'robots_mismatch', 'SEO gate robots must remain noindex,follow.');
        }
        if (data_get($item, 'claim_audit.human_review_required') !== true) {
            $errors[] = $this->issue($slug.'.claim_audit.human_review_required', 'human_review_required_missing', 'Human claim review must remain required.');
        }
        if ((array) data_get($item, 'claim_audit.forbidden_terms_found', []) !== []) {
            $errors[] = $this->issue($slug.'.claim_audit.forbidden_terms_found', 'forbidden_terms_found', 'Claim audit must not contain forbidden terms.');
        }

        $activeText = json_encode([
            'title' => $item['title'] ?? '',
            'excerpt' => $item['excerpt'] ?? '',
            'content_md' => $item['content_md'] ?? '',
            'answer_surface_v1' => $item['answer_surface_v1'] ?? [],
            'internal_links' => $this->withoutPolicyKeys($item['internal_links'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        foreach (self::FORBIDDEN_TEXT_PATTERNS as $code => $pattern) {
            if (preg_match($pattern, $activeText) === 1) {
                $errors[] = $this->issue($slug.'.private_or_scoring_guard', $code, 'Public import surface contains a forbidden private/scoring/certificate token.');
            }
        }

        if (mb_strlen((string) ($item['seo_title'] ?? '')) > 80) {
            $warnings[] = $this->issue($slug.'.seo_title', 'seo_title_long', 'SEO title is longer than the preferred compact limit.');
        }
        if (mb_strlen((string) ($item['seo_description'] ?? '')) > 180) {
            $warnings[] = $this->issue($slug.'.seo_description', 'seo_description_long', 'SEO description is longer than the preferred compact limit.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateTopicMapping(array $topicMapping, array $items, array &$errors): void
    {
        if ((string) data_get($topicMapping, 'target_topic.slug') !== 'iq-eq') {
            $errors[] = $this->issue('topic_mapping.target_topic.slug', 'topic_slug_mismatch', 'Target topic must be iq-eq.');
        }
        if (data_get($topicMapping, 'required_display_policy.split_mixed_group') !== true) {
            $errors[] = $this->issue('topic_mapping.required_display_policy.split_mixed_group', 'split_group_required', 'IQ/EQ topic mapping must split IQ and EQ groups.');
        }

        $mapped = collect((array) data_get($topicMapping, 'entry_groups.0.items', []))->pluck('slug')->all();
        $slugs = array_column($items, 'slug');
        if ($mapped !== $slugs) {
            $errors[] = $this->issue('topic_mapping.entry_groups.iq_articles.items', 'topic_items_mismatch', 'Topic IQ article items must match import order.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateLandingMapping(array $landingMapping, array $items, array &$errors): void
    {
        if ((string) data_get($landingMapping, 'target_landing_surface.related_test_slug') !== 'iq-test-intelligence-quotient-assessment') {
            $errors[] = $this->issue('landing_mapping.related_test_slug', 'related_test_slug_mismatch', 'Landing mapping must target the IQ test slug.');
        }
        if (data_get($landingMapping, 'guardrails.frontend_hardcode_allowed') !== false) {
            $errors[] = $this->issue('landing_mapping.guardrails.frontend_hardcode_allowed', 'frontend_hardcode_allowed', 'Frontend hardcode must remain forbidden.');
        }

        $mapped = collect((array) data_get($landingMapping, 'proposed_page_blocks.0.items', []))->pluck('slug')->all();
        $slugs = array_column($items, 'slug');
        if ($mapped !== $slugs) {
            $errors[] = $this->issue('landing_mapping.proposed_page_blocks.items', 'landing_items_mismatch', 'Landing page block items must match import order.');
        }
    }

    private function withoutPolicyKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $filtered = [];
        foreach ($value as $key => $child) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, [
                'private_flow_guard',
                'blocked_prefixes',
                'forbidden',
                'forbidden_paths',
                'forbidden_private_routes',
                'forbidden_query_keys',
                'forbidden_sensitive_query_keys',
                'forbidden_substrings',
                'sensitive_query_keys',
            ], true)) {
                continue;
            }

            $filtered[$key] = $this->withoutPolicyKeys($child);
        }

        return $filtered;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function plannedArticle(array $item): array
    {
        $existing = $this->existingArticle($item);

        return [
            'locale' => 'zh-CN',
            'slug' => (string) $item['slug'],
            'title' => (string) $item['title'],
            'action' => $existing instanceof Article ? 'would_update_draft' : 'would_create_draft',
            'article_id' => $existing instanceof Article ? (int) $existing->id : null,
            'status' => self::DEFAULT_STATE['status'],
            'is_public' => false,
            'is_indexable' => false,
            'robots' => self::DEFAULT_STATE['robots'],
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'canonical_url' => (string) $item['canonical_url'],
            'human_review_required' => true,
        ];
    }

    public static function translationGroupId(string $slug): string
    {
        $group = 'iq-method-pages-zh-cn-v0-2-'.$slug;

        return strlen($group) > 64 ? hash('sha256', $group) : $group;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function writeArticleDraft(array $item): array
    {
        $existing = $this->existingArticle($item);
        if ($existing instanceof Article && $this->isPublishedOrPublic($existing)) {
            throw new RuntimeException('Existing published/public IQ method article cannot be mutated by draft importer.');
        }

        $category = $this->resolveCategory((string) $item['category']);
        $metadata = $this->metadata($item);

        $article = $existing instanceof Article ? $existing : new Article;
        $article->forceFill([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_name' => 'Fermat Institute',
            'reviewer_name' => null,
            'reading_minutes' => $this->readingMinutes((string) $item['content_md']),
            'slug' => (string) $item['slug'],
            'locale' => 'zh-CN',
            'translation_group_id' => self::translationGroupId((string) $item['slug']),
            'title' => (string) $item['title'],
            'excerpt' => (string) $item['excerpt'],
            'content_md' => (string) $item['content_md'],
            'content_html' => null,
            'cover_image_url' => null,
            'cover_image_alt' => null,
            'cover_image_width' => null,
            'cover_image_height' => null,
            'cover_image_variants' => $metadata,
            'related_test_slug' => (string) $item['related_test_slug'],
            'status' => self::DEFAULT_STATE['status'],
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => null,
            'scheduled_at' => null,
            'published_revision_id' => null,
        ])->save();

        $revision = $this->revisionWorkspace->saveWorkingRevision($article, [
            'title' => (string) $item['title'],
            'excerpt' => (string) $item['excerpt'],
            'content_md' => (string) $item['content_md'],
            'seo_title' => (string) $item['seo_title'],
            'seo_description' => (string) $item['seo_description'],
            'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'status' => self::DEFAULT_STATE['status'],
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'published_at' => null,
            'published_revision_id' => null,
        ])->save();

        ArticleSeoMeta::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'org_id' => 0,
                'article_id' => (int) $article->id,
                'locale' => 'zh-CN',
            ],
            [
                'seo_title' => (string) $item['seo_title'],
                'seo_description' => (string) $item['seo_description'],
                'canonical_url' => (string) $item['canonical_url'],
                'og_title' => (string) $item['seo_title'],
                'og_description' => (string) $item['seo_description'],
                'og_image_url' => null,
                'robots' => self::DEFAULT_STATE['robots'],
                'schema_json' => [
                    'editorial_package_v1' => $metadata['editorial_package_v1'],
                ],
                'is_indexable' => false,
            ],
        );

        $this->syncTags($article, (array) $item['tags']);
        $this->persistImportRecord($article, $revision, $item, $metadata);

        return [
            'locale' => 'zh-CN',
            'slug' => (string) $item['slug'],
            'title' => (string) $item['title'],
            'action' => $existing instanceof Article ? 'updated_draft' : 'created_draft',
            'article_id' => (int) $article->id,
            'working_revision_id' => (int) $revision->id,
            'status' => self::DEFAULT_STATE['status'],
            'working_revision_status' => (string) $revision->revision_status,
            'is_public' => false,
            'is_indexable' => false,
            'robots' => self::DEFAULT_STATE['robots'],
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'canonical_url' => (string) $item['canonical_url'],
            'preview_url_candidate' => '/ops/article-preview/'.(int) $article->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function existingArticle(array $item): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', (string) $item['slug'])
            ->first();
    }

    private function isPublishedOrPublic(Article $article): bool
    {
        return (string) $article->status === 'published'
            || (bool) $article->is_public
            || $article->published_revision_id !== null
            || $article->published_at !== null;
    }

    private function resolveCategory(string $name): ArticleCategory
    {
        $label = trim($name) !== '' ? trim($name) : '测评方法与边界';
        $existing = ArticleCategory::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('name', $label)
            ->first();

        if ($existing instanceof ArticleCategory) {
            if (! (bool) $existing->is_active) {
                $existing->forceFill(['is_active' => true])->save();
            }

            return $existing;
        }

        return ArticleCategory::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => $this->uniqueCategorySlug($label),
            'name' => $label,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function uniqueCategorySlug(string $seed): string
    {
        $base = Str::slug($seed);
        if ($base === '') {
            $base = 'article-category-'.substr(hash('sha256', $seed), 0, 10);
        }

        $slug = $base;
        $suffix = 2;
        while (ArticleCategory::query()->withoutGlobalScopes()->where('org_id', 0)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  list<string>  $tags
     */
    private function syncTags(Article $article, array $tags): void
    {
        $tagIds = [];
        foreach ($tags as $tagName) {
            $name = trim($tagName);
            if ($name === '') {
                continue;
            }

            $tag = ArticleTag::query()->withoutGlobalScopes()->firstOrCreate(
                ['org_id' => 0, 'name' => $name],
                ['slug' => $this->uniqueTagSlug($name), 'is_active' => true],
            );
            if (! (bool) $tag->is_active) {
                $tag->forceFill(['is_active' => true])->save();
            }
            $tagIds[(int) $tag->id] = ['org_id' => 0];
        }

        $article->tags()->sync($tagIds);
    }

    private function uniqueTagSlug(string $seed): string
    {
        $base = Str::slug($seed);
        if ($base === '') {
            $base = 'article-tag-'.substr(hash('sha256', $seed), 0, 10);
        }

        $slug = $base;
        $suffix = 2;
        while (ArticleTag::query()->withoutGlobalScopes()->where('org_id', 0)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function metadata(array $item): array
    {
        return [
            'editorial_package_v1' => [
                'source' => self::CONTENT_TRACK,
                'source_pr_id' => self::EXPECTED_PR_ID,
                'import_pr_id' => self::EXPECTED_IMPORT_PR_ID,
                'status' => self::DEFAULT_STATE['status'],
                'is_public' => false,
                'is_indexable' => false,
                'robots' => self::DEFAULT_STATE['robots'],
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'search_submission_allowed' => false,
                'publish_allowed' => false,
                'review_required_before_publish' => true,
                'answer_surface_v1' => $item['answer_surface_v1'] ?? [],
                'internal_links' => $item['internal_links'] ?? [],
                'media_brief' => $item['media_brief'] ?? [],
                'claim_audit' => $item['claim_audit'] ?? [],
                'source_files' => $item['source_files'] ?? [],
                'body_hash' => hash('sha256', preg_replace("/\r\n?/", "\n", trim((string) $item['content_md'])) ?: trim((string) $item['content_md'])),
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $articles
     * @return array<string,mixed>
     */
    private function writeTopicDraftLinks(array $plan, array $articles): array
    {
        $profile = TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'iq-eq')
            ->first();

        if (! $profile instanceof TopicProfile) {
            $profile = TopicProfile::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'topic_code' => 'iq-eq',
                'slug' => 'iq-eq',
                'locale' => 'zh-CN',
                'title' => 'IQ 与 EQ 主题内容聚合',
                'subtitle' => 'IQ 文章和 EQ 文章分组阅读。',
                'excerpt' => '认知能力、情绪能力与解释边界。',
                'status' => TopicProfile::STATUS_DRAFT,
                'is_public' => false,
                'is_indexable' => false,
                'schema_version' => 'v1',
                'sort_order' => 0,
            ]);
        }

        TopicProfileEntry::query()
            ->where('profile_id', (int) $profile->id)
            ->where('group_key', 'iq_articles')
            ->delete();

        foreach ($articles as $index => $article) {
            TopicProfileEntry::query()->create([
                'profile_id' => (int) $profile->id,
                'entry_type' => 'article',
                'group_key' => 'iq_articles',
                'target_key' => (string) $article['slug'],
                'target_locale' => 'zh-CN',
                'title_override' => (string) $article['title'],
                'excerpt_override' => null,
                'badge_label' => 'IQ 文章',
                'cta_label' => '阅读文章',
                'target_url_override' => '/zh/articles/'.(string) $article['slug'],
                'payload_json' => [
                    'source' => self::CONTENT_TRACK,
                    'status' => self::DEFAULT_STATE['status'],
                    'is_public' => false,
                    'is_indexable' => false,
                ],
                'sort_order' => ($index + 1) * 10,
                'is_featured' => $index === 0,
                'is_enabled' => true,
            ]);
        }

        return [
            'operation' => 'upserted_iq_topic_draft_links',
            'topic_profile_id' => (int) $profile->id,
            'slug' => 'iq-eq',
            'group_key' => 'iq_articles',
            'label' => 'IQ 文章',
            'items_count' => count($articles),
            'topic_publication_changed' => false,
            'topic_status' => (string) $profile->status,
            'topic_is_public' => (bool) $profile->is_public,
            'topic_is_indexable' => (bool) $profile->is_indexable,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function plannedTopicMapping(array $topicMapping, array $items): array
    {
        $profile = TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'iq-eq')
            ->first();

        return [
            'operation' => $profile instanceof TopicProfile ? 'would_replace_iq_topic_draft_links' : 'would_create_topic_and_iq_draft_links',
            'target_topic' => data_get($topicMapping, 'target_topic.route', '/zh/topics/iq-eq'),
            'group_key' => 'iq_articles',
            'label' => 'IQ 文章',
            'items_count' => count($items),
            'topic_publication_changed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writeLandingDraftBlock(array $plan): array
    {
        $landing = $plan['landing_mapping_payload'] ?? [];
        $surface = LandingSurface::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'org_id' => 0,
                'surface_key' => 'test:iq-test-intelligence-quotient-assessment',
                'locale' => 'zh-CN',
            ],
            [
                'title' => 'IQ 测试方法与边界',
                'description' => 'IQ 风格测试 landing 页辅助方法论链接草稿配置。',
                'schema_version' => 'iq-method-links.v1',
                'payload_json' => [
                    'source' => self::CONTENT_TRACK,
                    'route' => '/zh/tests/iq-test-intelligence-quotient-assessment',
                    'related_test_slug' => 'iq-test-intelligence-quotient-assessment',
                    'status' => self::DEFAULT_STATE['status'],
                    'frontend_hardcode_allowed' => false,
                ],
                'status' => LandingSurface::STATUS_DRAFT,
                'is_public' => false,
                'is_indexable' => false,
                'published_at' => null,
                'scheduled_at' => null,
            ],
        );

        $block = (array) data_get($landing, 'proposed_page_blocks.0', []);
        PageBlock::query()->updateOrCreate(
            [
                'landing_surface_id' => (int) $surface->id,
                'block_key' => 'iq_methodology_boundary_links',
            ],
            [
                'block_type' => 'article_link_cluster',
                'title' => 'IQ 测试方法与边界',
                'payload_json' => [
                    'source' => self::CONTENT_TRACK,
                    'placement' => (string) ($block['placement'] ?? 'supporting_methodology_links'),
                    'label' => (string) ($block['label'] ?? 'IQ 测试方法与边界'),
                    'items' => (array) ($block['items'] ?? []),
                    'status' => self::DEFAULT_STATE['status'],
                    'frontend_hardcode_allowed' => false,
                    'publish_or_indexing_change_allowed' => false,
                ],
                'sort_order' => 700,
                'is_enabled' => true,
            ],
        );

        return [
            'operation' => 'upserted_landing_draft_block',
            'surface_key' => 'test:iq-test-intelligence-quotient-assessment',
            'landing_surface_id' => (int) $surface->id,
            'block_key' => 'iq_methodology_boundary_links',
            'status' => LandingSurface::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'items_count' => count((array) ($block['items'] ?? [])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function plannedLandingBlock(array $landingMapping): array
    {
        $surface = LandingSurface::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('surface_key', 'test:iq-test-intelligence-quotient-assessment')
            ->where('locale', 'zh-CN')
            ->first();

        return [
            'operation' => $surface instanceof LandingSurface ? 'would_update_landing_draft_block' : 'would_create_landing_draft_block',
            'surface_key' => 'test:iq-test-intelligence-quotient-assessment',
            'block_key' => 'iq_methodology_boundary_links',
            'status' => LandingSurface::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => false,
            'items_count' => count((array) data_get($landingMapping, 'proposed_page_blocks.0.items', [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $metadata
     */
    private function persistImportRecord(Article $article, ArticleTranslationRevision $revision, array $item, array $metadata): void
    {
        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'slug' => (string) $item['slug'],
            'locale' => 'zh-CN',
            'title' => (string) $item['title'],
            'content_track' => self::CONTENT_TRACK,
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
            'intended_status' => self::DEFAULT_STATE['status'],
            'validation_summary_json' => [
                'source' => 'articles:import-iq-method-pages-draft',
                'source_pr_id' => self::EXPECTED_PR_ID,
                'import_pr_id' => self::EXPECTED_IMPORT_PR_ID,
                'working_revision_id' => (int) $revision->id,
                'preview_url_candidate' => '/ops/article-preview/'.(int) $article->id,
            ],
            'claim_result_json' => [
                'status' => 'pass_with_human_review_required',
                'human_review_required' => true,
                'claim_audit' => $item['claim_audit'] ?? [],
            ],
            'exactness_json' => [
                'canonical_url' => (string) $item['canonical_url'],
                'robots' => self::DEFAULT_STATE['robots'],
                'status' => self::DEFAULT_STATE['status'],
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
            ],
            'references_json' => ['status' => 'method_and_claim_review_required'],
            'media_json' => [
                'status' => 'media_library_upload_deferred',
                'media_brief' => $item['media_brief'] ?? [],
            ],
            'graph_json' => [
                'topic_group' => 'iq_articles',
                'topic_route' => '/zh/topics/iq-eq',
                'landing_surface_key' => 'test:iq-test-intelligence-quotient-assessment',
                'internal_links' => $item['internal_links'] ?? [],
            ],
            'answer_surface_json' => $item['answer_surface_v1'] ?? [],
            'body_hash' => (string) data_get($metadata, 'editorial_package_v1.body_hash'),
            'heading_sequence_json' => $this->headingSequence((string) $item['content_md']),
            'references_count' => 0,
            'missing_fields_json' => [],
            'blocked_reasons_json' => [
                'human_method_review_required',
                'human_claim_review_required',
                'cms_readback_required',
                'publish_not_allowed_in_import_pr',
                'sitemap_llms_activation_not_allowed_in_import_pr',
            ],
            'imported_by' => null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function headingSequence(string $body): array
    {
        $headings = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $body)) as $line) {
            if (preg_match('/^(#{2,6})[ \t]+/', $line, $matches) === 1) {
                $headings[] = strlen((string) $matches[1]).':'.trim(substr($line, strlen((string) $matches[0])));
            }
        }

        return $headings;
    }

    private function readingMinutes(string $body): int
    {
        return max(1, (int) ceil(max(1, mb_strlen(strip_tags($body))) / 700));
    }

    /**
     * @param  list<array<string,mixed>>  $articles
     */
    private function summaryAction(array $articles): string
    {
        $actions = array_values(array_unique(array_map(static fn (array $article): string => (string) ($article['action'] ?? ''), $articles)));

        return count($actions) === 1 ? $actions[0] : 'would_upsert_drafts';
    }

    /**
     * @return array<string,mixed>
     */
    private function issue(string $field, string $code, string $message): array
    {
        return [
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
