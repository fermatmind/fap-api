<?php

declare(strict_types=1);

namespace App\Services\Cms\SeoContentPackage;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SeoContentPackageExistingArticleUpdater
{
    private const PRIVATE_ROUTE_PATTERN = '~(?<![A-Za-z0-9_-])/(?:result|results|orders|order|share|pay|payment|history|take)(?:/|[?#\s)"\']|$)~i';

    private const SENSITIVE_QUERY_PATTERN = '/(?:[?&]|^)(?:result_id|order_id|payment_id|token|score|user_id|report_id)=/i';

    /**
     * @var list<string>
     */
    private const REQUIRED_HOLD_FLAGS = [
        'slug_lock',
        'canonical_lock',
        'schema_hold',
        'hreflang_hold',
        'search_hold',
        'no_revalidation',
        'no_sitemap',
        'no_llms',
    ];

    public function __construct(
        private readonly ArticleTranslationRevisionWorkspace $revisionWorkspace,
        private readonly ArticleBodyHeadingGuard $articleBodyHeadingGuard,
        private readonly SeoContentPackageJsonNormalizer $jsonNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function planFromDirectory(array $options): array
    {
        return $this->buildPlan($options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function updateWorkingRevisionFromDirectory(array $options): array
    {
        $plan = $this->buildPlan($options);
        if (($plan['ok'] ?? false) !== true) {
            return $plan;
        }

        /** @var array<string,mixed> $item */
        $item = $plan['package_item'];
        /** @var Article $article */
        $article = $item['article'];

        $result = DB::transaction(function () use ($article, $item): array {
            /** @var Article $locked */
            $locked = Article::query()
                ->withoutGlobalScopes()
                ->with(['workingRevision', 'seoMeta'])
                ->whereKey((int) $article->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertArticleIdentity($locked, $item);
            $createdIsolatedWorkingRevision = $this->usesPublishedRevisionAsWorkingRevision($locked);
            $locked = $this->ensureIsolatedWorkingRevision($locked);

            $revision = $this->revisionWorkspace->saveWorkingRevision($locked, [
                'title' => (string) $item['title'],
                'excerpt' => (string) $item['excerpt'],
                'content_md' => (string) $item['body_markdown'],
                'seo_title' => (string) $item['meta_title'],
                'seo_description' => (string) $item['meta_description'],
                'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            ]);

            $metadata = $this->metadata($item, $locked, $revision);
            $this->persistImportRecord($locked, $revision, $item, $metadata);

            $locked->refresh();

            return [
                'locale' => (string) $locked->locale,
                'slug' => (string) $locked->slug,
                'action' => 'updated_existing_working_revision',
                'article_id' => (int) $locked->id,
                'working_revision_id' => (int) $revision->id,
                'published_revision_id' => $locked->published_revision_id !== null ? (int) $locked->published_revision_id : null,
                'created_isolated_working_revision' => $createdIsolatedWorkingRevision,
                'status' => (string) $locked->status,
                'is_public' => (bool) $locked->is_public,
                'is_indexable' => (bool) $locked->is_indexable,
                'sitemap_eligible' => (bool) $locked->sitemap_eligible,
                'llms_eligible' => (bool) $locked->llms_eligible,
                'working_revision_status' => (string) $revision->revision_status,
                'body_hash' => (string) data_get($metadata, 'editorial_package_update_v1.body_hash'),
                'preview_url_candidate' => '/ops/article-preview/'.(int) $locked->id,
            ];
        });

        return array_merge($plan, [
            'dry_run' => false,
            'action' => 'updated_existing_working_revision',
            'would_write' => true,
            'articles' => [$result],
            'package_item' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildPlan(array $options): array
    {
        $errors = [];
        $warnings = [];
        $packageRoot = $this->resolvePackageRoot((string) ($options['package'] ?? ''), $errors);
        $articleId = (int) ($options['article_id'] ?? 0);
        $expectedLocale = trim((string) ($options['locale'] ?? ''));
        $expectedSlug = Str::slug((string) ($options['expected_slug'] ?? ''));
        $expectedCanonical = trim((string) ($options['expected_canonical'] ?? ''));
        $expectedTranslationGroupId = trim((string) ($options['translation_group_id'] ?? ''));

        $this->validateCommandSafety($options, $errors);

        if ($articleId <= 0) {
            $errors[] = $this->issue('article_id', 'missing_article_id', '--article-id is required.');
        }
        if ($expectedLocale === '') {
            $errors[] = $this->issue('locale', 'missing_locale', '--locale is required.');
        }
        if ($expectedSlug === '') {
            $errors[] = $this->issue('expected_slug', 'missing_expected_slug', '--expected-slug is required.');
        }
        if ($expectedCanonical === '') {
            $errors[] = $this->issue('expected_canonical', 'missing_expected_canonical', '--expected-canonical is required.');
        }
        if ($expectedTranslationGroupId === '') {
            $errors[] = $this->issue('translation_group_id', 'missing_translation_group_id', '--translation-group-id is required.');
        }

        $manifest = [];
        $identityLock = [];
        $item = [];
        $guardScan = ['status' => 'not_run', 'error_count' => 0];
        $article = null;

        if ($packageRoot !== null) {
            $this->validateRequiredFiles($packageRoot, $errors);
            $manifest = $this->readJson($packageRoot.'/manifest.json', 'manifest.json', $errors);
            $identityLock = $this->readJson($packageRoot.'/contracts/ARTICLE_IDENTITY_LOCK.json', 'contracts/ARTICLE_IDENTITY_LOCK.json', $errors);
            $guardScan = $this->validateActiveSurfaceGuard($packageRoot, $expectedCanonical, $errors);
            $this->validatePackageHolds($manifest, $errors);
            $this->validateIdentityLock($identityLock, $articleId, $expectedLocale, $expectedSlug, $expectedCanonical, $expectedTranslationGroupId, $errors);
            $article = $this->findArticle($articleId);
            if ($article instanceof Article) {
                $this->validateArticle($article, $expectedLocale, $expectedSlug, $expectedCanonical, $expectedTranslationGroupId, $errors);
            } elseif ($articleId > 0) {
                $errors[] = $this->issue('article_id', 'article_not_found', 'Target article was not found.');
            }
            $item = $this->buildPackageItem($packageRoot, $manifest, $identityLock, $expectedLocale, $errors, $warnings);
            if ($article instanceof Article && $item !== []) {
                $item['article'] = $article;
                $this->validateItemAgainstLocks($item, $article, $expectedSlug, $expectedCanonical, $expectedTranslationGroupId, $errors);
                $this->validateJsonFieldSerialization($item, $errors, $warnings);
            }
        }

        $ok = $errors === [];
        $plannedArticle = ($ok && $article instanceof Article && $item !== [])
            ? [$this->plannedArticle($article, $item)]
            : [];

        return [
            'ok' => $ok,
            'dry_run' => (bool) ($options['dry_run'] ?? true),
            'action' => $ok ? 'would_update_existing_working_revision' : 'will_skip',
            'would_write' => $ok,
            'article_id' => $articleId,
            'translation_group_id' => $expectedTranslationGroupId,
            'locale' => $expectedLocale,
            'slug_lock' => $expectedSlug,
            'canonical_lock' => $expectedCanonical,
            'package_root' => $packageRoot,
            'manifest_status' => $manifest !== [] ? 'valid_json' : 'missing_or_invalid',
            'identity_lock_status' => $identityLock !== [] ? 'valid_json' : 'missing_or_invalid',
            'active_surface_guard_scan' => $guardScan,
            'safety_flags' => $this->safetyFlagSnapshot($options),
            'articles' => $plannedArticle,
            'package_item' => $ok ? $item : [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function resolvePackageRoot(string $package, array &$errors): ?string
    {
        $path = trim($package);
        if ($path === '') {
            $errors[] = $this->issue('package', 'missing_package', '--package is required.');

            return null;
        }

        if (! is_dir($path)) {
            $errors[] = $this->issue('package', 'package_directory_not_found', 'Package directory not found.');

            return null;
        }

        $root = realpath($path);
        if (! is_string($root)) {
            $errors[] = $this->issue('package', 'package_directory_unreadable', 'Package directory is unreadable.');

            return null;
        }

        if (is_file($root.'/manifest.json')) {
            return $root;
        }

        $children = glob($root.'/*/manifest.json') ?: [];
        if (count($children) === 1) {
            return dirname($children[0]);
        }

        $errors[] = $this->issue('manifest.json', 'manifest_not_found', 'manifest.json must exist in package root or a single child directory.');

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateRequiredFiles(string $root, array &$errors): void
    {
        foreach ([
            'manifest.json',
            'contracts/ARTICLE_IDENTITY_LOCK.json',
            'contracts/PRIVATE_URL_GUARD.json',
            'review/claim_gate.md',
            'review/operator_review.md',
        ] as $relativePath) {
            if (! is_file($root.'/'.$relativePath)) {
                $errors[] = $this->issue($relativePath, 'missing_required_file', $relativePath.' is required.');
            }
        }

        if ((glob($root.'/pages/*.md') ?: []) === []) {
            $errors[] = $this->issue('pages/*.md', 'missing_pages', 'At least one Markdown page is required.');
        }
        if ((glob($root.'/cms/CMS_IMPORT_UPDATE_DRAFT_*.json') ?: []) === []) {
            $errors[] = $this->issue('cms/CMS_IMPORT_UPDATE_DRAFT_*.json', 'missing_cms_update_import', 'CMS_IMPORT_UPDATE_DRAFT JSON file is required.');
        }
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

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            $errors[] = $this->issue($field, 'invalid_json', $field.' must be valid JSON.');

            return [];
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateCommandSafety(array $options, array &$errors): void
    {
        if ((bool) ($options['dry_run'] ?? false) && (bool) ($options['execute'] ?? false)) {
            $errors[] = $this->issue('dry_run', 'dry_run_execute_conflict', '--dry-run and --execute cannot be used together.');
        }

        foreach (self::REQUIRED_HOLD_FLAGS as $flag) {
            if ((bool) ($options[$flag] ?? false) !== true) {
                $errors[] = $this->issue($flag, 'required_hold_flag_missing', '--'.str_replace('_', '-', $flag).' is required.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  list<array<string,mixed>>  $errors
     */
    private function validatePackageHolds(array $manifest, array &$errors): void
    {
        foreach ([
            'schema_enabled' => false,
            'schema_generation_allowed' => false,
            'hreflang_enabled' => false,
            'hreflang_enablement_allowed' => false,
            'search_submission_allowed' => false,
            'revalidation_allowed' => false,
            'sitemap_change_allowed' => false,
            'llms_change_allowed' => false,
            'create_new_article' => false,
            'create_new_slug' => false,
        ] as $field => $expected) {
            if (array_key_exists($field, $manifest) && (bool) $manifest[$field] !== $expected) {
                $errors[] = $this->issue('manifest.'.$field, 'manifest_hold_mismatch', $field.' must remain false.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $identityLock
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateIdentityLock(
        array $identityLock,
        int $articleId,
        string $expectedLocale,
        string $expectedSlug,
        string $expectedCanonical,
        string $expectedTranslationGroupId,
        array &$errors
    ): void {
        $targetArticleId = (int) data_get($identityLock, 'target_article_id', data_get($identityLock, 'article_id', 0));
        $lockSlug = Str::slug((string) data_get($identityLock, 'slug', data_get($identityLock, 'current_slug', '')));
        $lockCanonical = trim((string) data_get($identityLock, 'canonical_url', data_get($identityLock, 'current_route', '')));
        $lockLocale = trim((string) data_get($identityLock, 'locale', ''));
        $lockTranslationGroupId = trim((string) data_get($identityLock, 'translation_group_id', ''));

        if ($targetArticleId !== $articleId) {
            $errors[] = $this->issue('identity_lock.target_article_id', 'article_id_mismatch', 'Identity lock article id does not match --article-id.');
        }
        if ($lockLocale !== $expectedLocale) {
            $errors[] = $this->issue('identity_lock.locale', 'locale_mismatch', 'Identity lock locale does not match --locale.');
        }
        if ($lockSlug !== $expectedSlug) {
            $errors[] = $this->issue('identity_lock.slug', 'slug_lock_mismatch', 'Identity lock slug does not match --expected-slug.');
        }
        if ($lockCanonical !== $expectedCanonical) {
            $errors[] = $this->issue('identity_lock.canonical_url', 'canonical_lock_mismatch', 'Identity lock canonical does not match --expected-canonical.');
        }
        if ($lockTranslationGroupId !== '' && $lockTranslationGroupId !== $expectedTranslationGroupId) {
            $errors[] = $this->issue('identity_lock.translation_group_id', 'translation_group_id_mismatch', 'Identity lock translation_group_id mismatch.');
        }
        if ((bool) data_get($identityLock, 'preserve_slug', true) !== true) {
            $errors[] = $this->issue('identity_lock.preserve_slug', 'preserve_slug_required', 'Identity lock must preserve slug.');
        }
        if ((bool) data_get($identityLock, 'create_new_article', false) !== false) {
            $errors[] = $this->issue('identity_lock.create_new_article', 'create_new_article_forbidden', 'Existing article update cannot create a new article.');
        }
        if ((bool) data_get($identityLock, 'create_new_slug', false) !== false) {
            $errors[] = $this->issue('identity_lock.create_new_slug', 'create_new_slug_forbidden', 'Existing article update cannot create a new slug.');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>
     */
    private function validateActiveSurfaceGuard(string $root, string $expectedCanonical, array &$errors): array
    {
        $start = count($errors);
        $text = $this->activeSurfaceText($root);

        if (str_contains($text, '__CMS_MEDIA_LIBRARY_PLACEHOLDER__') || str_contains($text, '{{ media_library_visual:')) {
            $errors[] = $this->issue('active_surface_guard_scan.media', 'media_placeholder_found', 'Media placeholders are forbidden in active update surfaces.');
        }
        if (preg_match(self::PRIVATE_ROUTE_PATTERN, $text) === 1) {
            $errors[] = $this->issue('active_surface_guard_scan.private_url_guard', 'private_route_found_in_active_surface', 'Private routes are forbidden in active update surfaces.');
        }
        if (preg_match(self::SENSITIVE_QUERY_PATTERN, $text) === 1) {
            $errors[] = $this->issue('active_surface_guard_scan.private_url_guard', 'sensitive_query_key_found_in_active_surface', 'Sensitive query keys are forbidden in active update surfaces.');
        }

        $manifest = $this->readJsonLenient($root.'/manifest.json');
        $forbiddenNewRoute = trim((string) data_get($manifest, 'forbidden_new_route', ''));
        if ($forbiddenNewRoute !== '' && $forbiddenNewRoute !== $expectedCanonical && str_contains($text, $forbiddenNewRoute)) {
            $errors[] = $this->issue('active_surface_guard_scan.route', 'forbidden_new_route_found_in_active_surface', 'Forbidden new article route appears in active update surfaces.');
        }

        $errorCount = count($errors) - $start;

        return [
            'status' => $errorCount === 0 ? 'passed' : 'failed',
            'error_count' => $errorCount,
        ];
    }

    private function activeSurfaceText(string $root): string
    {
        $parts = [];

        foreach (glob($root.'/pages/*.md') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $page = $this->readMarkdownPage($path);
            $parts[] = (string) json_encode($page['frontmatter'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $parts[] = $page['body'];
        }

        foreach (glob($root.'/cms/*.json') ?: [] as $path) {
            if (is_file($path)) {
                $parts[] = (string) file_get_contents($path);
            }
        }

        $manifest = $this->readJsonLenient($root.'/manifest.json');
        $parts[] = (string) json_encode($manifest['pages'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return implode("\n", $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function readJsonLenient(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function findArticle(int $articleId): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->with(['workingRevision', 'publishedRevision', 'seoMeta'])
            ->find($articleId);
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateArticle(
        Article $article,
        string $expectedLocale,
        string $expectedSlug,
        string $expectedCanonical,
        string $expectedTranslationGroupId,
        array &$errors
    ): void {
        if ((string) $article->locale !== $expectedLocale) {
            $errors[] = $this->issue('article.locale', 'locale_mismatch', 'Article locale does not match --locale.');
        }
        if ((string) $article->slug !== $expectedSlug) {
            $errors[] = $this->issue('article.slug', 'slug_lock_mismatch', 'Article slug does not match --expected-slug.');
        }
        if ((string) $article->translation_group_id !== $expectedTranslationGroupId) {
            $errors[] = $this->issue('article.translation_group_id', 'translation_group_id_mismatch', 'Article translation_group_id mismatch.');
        }
        if ((string) $article->lifecycle_state !== '' && in_array((string) $article->lifecycle_state, [
            Article::LIFECYCLE_ARCHIVED,
            Article::LIFECYCLE_SOFT_DELETED,
        ], true)) {
            $errors[] = $this->issue('article.lifecycle_state', 'article_lifecycle_not_updateable', 'Archived or soft-deleted articles cannot be updated.');
        }
        if (method_exists($article, 'trashed') && $article->trashed()) {
            $errors[] = $this->issue('article.deleted_at', 'article_soft_deleted', 'Soft-deleted articles cannot be updated.');
        }

        $canonical = (string) ($article->seoMeta?->canonical_url ?? '');
        if ($canonical !== '' && ! in_array($canonical, [$expectedCanonical, 'https://fermatmind.com'.$expectedCanonical], true)) {
            $errors[] = $this->issue('article.seo.canonical_url', 'canonical_lock_mismatch', 'Existing article canonical does not match --expected-canonical.');
        }
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function assertArticleIdentity(Article $article, array $item): void
    {
        if ((int) $article->id !== (int) $item['target_article_id']
            || (string) $article->slug !== (string) $item['slug']
            || (string) $article->locale !== (string) $item['locale']
            || (string) $article->translation_group_id !== (string) $item['translation_group_id']) {
            throw new RuntimeException('target article identity changed before update.');
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $identityLock
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function buildPackageItem(
        string $root,
        array $manifest,
        array $identityLock,
        string $expectedLocale,
        array &$errors,
        array &$warnings
    ): array {
        $import = $this->readFirstJson($root.'/cms/CMS_IMPORT_UPDATE_DRAFT_'.$expectedLocale.'_*.json', 'cms import update '.$expectedLocale, $errors);
        $fields = $this->readFirstJson($root.'/cms/CMS_FIELDS_UPDATE_'.$expectedLocale.'_*.json', 'cms fields update '.$expectedLocale, $errors, required: false);
        $pagePath = $this->pagePathFor($root, $expectedLocale, $import, $manifest, $errors);
        $page = $pagePath !== null ? $this->readMarkdownPage($pagePath) : ['frontmatter' => [], 'body' => ''];
        $frontmatter = $page['frontmatter'];
        $body = $this->articleBodyHeadingGuard->downgradeMarkdownH1ToH2((string) $page['body']);
        $manifestPage = $this->manifestPage($manifest, $expectedLocale);

        $item = [
            'target_article_id' => (int) data_get($identityLock, 'target_article_id', data_get($identityLock, 'article_id', 0)),
            'locale' => $expectedLocale,
            'translation_group_id' => $this->firstString($import['translation_group_id'] ?? null, $fields['translation_group_id'] ?? null, $frontmatter['translation_group_id'] ?? null, $identityLock['translation_group_id'] ?? null, $manifest['translation_group_id'] ?? null),
            'slug' => Str::slug($this->firstString($import['slug'] ?? null, $fields['slug'] ?? null, $frontmatter['slug'] ?? null, $identityLock['slug'] ?? null, $manifestPage['slug'] ?? null)),
            'title' => $this->firstString($import['title'] ?? null, $fields['title'] ?? null, $frontmatter['title'] ?? null, $manifestPage['title'] ?? null),
            'meta_title' => $this->firstString($import['meta_title'] ?? null, $fields['meta_title'] ?? null, $frontmatter['meta_title_draft'] ?? null, $manifestPage['meta_title_draft'] ?? null),
            'meta_description' => $this->firstString($import['meta_description'] ?? null, $fields['meta_description'] ?? null, $frontmatter['meta_description_draft'] ?? null, $manifestPage['meta_description_draft'] ?? null),
            'excerpt' => $this->firstString($import['excerpt'] ?? null, $fields['excerpt'] ?? null, $manifestPage['excerpt'] ?? null),
            'canonical_url' => $this->firstString($import['canonical_url'] ?? null, $fields['canonical_url'] ?? null, $frontmatter['canonical_url_draft'] ?? null, $identityLock['canonical_url'] ?? null, $identityLock['current_route'] ?? null),
            'body_markdown' => $body,
            'claim_gate_status' => $this->firstString($import['claim_gate_status'] ?? null, $fields['claim_gate_status'] ?? null, $frontmatter['claim_gate_status'] ?? null, 'not_reviewed'),
            'schema_hold' => (bool) ($import['schema_hold'] ?? $fields['schema_hold'] ?? true),
            'hreflang_hold' => (bool) ($import['hreflang_hold'] ?? $fields['hreflang_hold'] ?? true),
            'search_submission_allowed' => (bool) ($import['search_submission_allowed'] ?? $fields['search_submission_allowed'] ?? false),
            'revalidation_allowed' => (bool) ($import['revalidation_allowed'] ?? $fields['revalidation_allowed'] ?? false),
            'sitemap_change_allowed' => (bool) ($import['sitemap_change_allowed'] ?? $fields['sitemap_change_allowed'] ?? false),
            'llms_change_allowed' => (bool) ($import['llms_change_allowed'] ?? $fields['llms_change_allowed'] ?? false),
        ];

        if ((string) $item['excerpt'] === '') {
            $item['excerpt'] = mb_substr((string) $item['meta_description'], 0, 240);
        }

        $this->validateItem($item, $errors, $warnings);

        return $item;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>
     */
    private function readFirstJson(string $pattern, string $field, array &$errors, bool $required = true): array
    {
        $matches = glob($pattern) ?: [];
        if ($matches === [] && $required === false) {
            return [];
        }
        if (count($matches) !== 1) {
            $errors[] = $this->issue($field, 'json_file_count_invalid', $field.' must resolve to exactly one JSON file.');

            return [];
        }

        return $this->readJson($matches[0], $field, $errors);
    }

    /**
     * @param  array<string,mixed>  $import
     * @param  array<string,mixed>  $manifest
     * @param  list<array<string,mixed>>  $errors
     */
    private function pagePathFor(string $root, string $locale, array $import, array $manifest, array &$errors): ?string
    {
        $relative = trim((string) ($import['body_markdown_file'] ?? ''));
        if ($relative === '') {
            foreach ((array) ($manifest['pages'] ?? []) as $page) {
                if (is_array($page) && (string) ($page['locale'] ?? '') === $locale) {
                    $relative = trim((string) ($page['file'] ?? ''));
                    break;
                }
            }
        }

        if ($relative === '') {
            $matches = glob($root.'/pages/'.($locale === 'zh-CN' ? 'zh-CN-*' : 'en-*').'.md') ?: [];
            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        $path = $relative !== '' ? $root.'/'.ltrim($relative, '/') : '';
        if ($path === '' || ! is_file($path)) {
            $errors[] = $this->issue($locale.'.page', 'page_file_not_found', 'Markdown page for locale was not found.');

            return null;
        }

        return $path;
    }

    /**
     * @return array{frontmatter:array<string,mixed>,body:string}
     */
    private function readMarkdownPage(string $path): array
    {
        $contents = (string) file_get_contents($path);
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/s', $contents, $matches) !== 1) {
            return ['frontmatter' => [], 'body' => trim($contents)];
        }

        return [
            'frontmatter' => $this->parseSimpleFrontmatter((string) $matches[1]),
            'body' => trim((string) $matches[2]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseSimpleFrontmatter(string $yaml): array
    {
        $data = [];
        $lines = preg_split('/\R/', $yaml) ?: [];
        $currentKey = null;
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $matches) === 1) {
                $currentKey = (string) $matches[1];
                $value = trim((string) $matches[2]);
                $data[$currentKey] = $value === '' ? [] : $this->parseScalar($value);

                continue;
            }

            if ($currentKey !== null && preg_match('/^\s*-\s*(.+)$/', $line, $matches) === 1) {
                if (! is_array($data[$currentKey] ?? null)) {
                    $data[$currentKey] = [];
                }
                $data[$currentKey][] = $this->parseScalar(trim((string) $matches[1]));
            }
        }

        return $data;
    }

    private function parseScalar(string $value): mixed
    {
        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => trim($value, '"\''),
        };
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function manifestPage(array $manifest, string $locale): array
    {
        foreach ((array) ($manifest['pages'] ?? []) as $page) {
            if (is_array($page) && (string) ($page['locale'] ?? '') === $locale) {
                return $page;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     */
    private function validateItem(array $item, array &$errors, array &$warnings): void
    {
        $locale = (string) $item['locale'];
        foreach (['translation_group_id', 'slug', 'title', 'meta_title', 'meta_description', 'canonical_url', 'body_markdown'] as $field) {
            if (trim((string) ($item[$field] ?? '')) === '') {
                $errors[] = $this->issue($locale.'.'.$field, 'missing_required_field', $field.' is required.');
            }
        }

        if ((bool) $item['schema_hold'] !== true) {
            $errors[] = $this->issue($locale.'.schema_hold', 'schema_hold_not_satisfied', 'schema_hold must remain true.');
        }
        if ((bool) $item['hreflang_hold'] !== true) {
            $errors[] = $this->issue($locale.'.hreflang_hold', 'hreflang_hold_not_satisfied', 'hreflang_hold must remain true.');
        }
        if ((bool) $item['search_submission_allowed']) {
            $errors[] = $this->issue($locale.'.search_submission_allowed', 'search_submission_allowed_forbidden', 'search_submission_allowed must remain false.');
        }
        if ((bool) $item['revalidation_allowed']) {
            $errors[] = $this->issue($locale.'.revalidation_allowed', 'revalidation_allowed_forbidden', 'revalidation_allowed must remain false.');
        }
        if ((bool) $item['sitemap_change_allowed']) {
            $errors[] = $this->issue($locale.'.sitemap_change_allowed', 'sitemap_change_allowed_forbidden', 'sitemap_change_allowed must remain false.');
        }
        if ((bool) $item['llms_change_allowed']) {
            $errors[] = $this->issue($locale.'.llms_change_allowed', 'llms_change_allowed_forbidden', 'llms_change_allowed must remain false.');
        }
        if (! in_array((string) $item['claim_gate_status'], ['not_reviewed', 'human_review', 'passed'], true)) {
            $warnings[] = $this->issue($locale.'.claim_gate_status', 'claim_gate_status_unrecognized', 'claim_gate_status is not a standard safe value.');
        }

        $this->articleBodyHeadingGuard->assertNoBodyH1((string) $item['body_markdown']);
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateItemAgainstLocks(
        array $item,
        Article $article,
        string $expectedSlug,
        string $expectedCanonical,
        string $expectedTranslationGroupId,
        array &$errors
    ): void {
        if ((int) $item['target_article_id'] !== (int) $article->id) {
            $errors[] = $this->issue('item.target_article_id', 'article_id_mismatch', 'Package target article id does not match article.');
        }
        if ((string) $item['slug'] !== $expectedSlug) {
            $errors[] = $this->issue('item.slug', 'slug_lock_mismatch', 'Package slug does not match --expected-slug.');
        }
        if ((string) $item['canonical_url'] !== $expectedCanonical) {
            $errors[] = $this->issue('item.canonical_url', 'canonical_lock_mismatch', 'Package canonical does not match --expected-canonical.');
        }
        if ((string) $item['translation_group_id'] !== $expectedTranslationGroupId) {
            $errors[] = $this->issue('item.translation_group_id', 'translation_group_id_mismatch', 'Package translation_group_id mismatch.');
        }
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function plannedArticle(Article $article, array $item): array
    {
        $bodyHash = $this->bodyHash((string) $item['body_markdown']);

        return [
            'locale' => (string) $article->locale,
            'slug' => (string) $article->slug,
            'action' => 'would_update_existing_working_revision',
            'article_id' => (int) $article->id,
            'working_revision_id' => $article->working_revision_id !== null ? (int) $article->working_revision_id : null,
            'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
            'working_revision_is_published_revision' => $this->usesPublishedRevisionAsWorkingRevision($article),
            'will_create_isolated_working_revision' => $this->usesPublishedRevisionAsWorkingRevision($article),
            'status' => (string) $article->status,
            'is_public' => (bool) $article->is_public,
            'is_indexable' => (bool) $article->is_indexable,
            'sitemap_eligible' => (bool) $article->sitemap_eligible,
            'llms_eligible' => (bool) $article->llms_eligible,
            'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'body_hash' => $bodyHash,
            'preview_url_candidate' => '/ops/article-preview/'.(int) $article->id,
        ];
    }

    private function usesPublishedRevisionAsWorkingRevision(Article $article): bool
    {
        return $article->working_revision_id !== null
            && $article->published_revision_id !== null
            && (int) $article->working_revision_id === (int) $article->published_revision_id;
    }

    private function ensureIsolatedWorkingRevision(Article $article): Article
    {
        if (! $this->usesPublishedRevisionAsWorkingRevision($article)) {
            return $article;
        }

        $published = $article->publishedRevision instanceof ArticleTranslationRevision
            ? $article->publishedRevision
            : ArticleTranslationRevision::query()
                ->withoutGlobalScopes()
                ->whereKey((int) $article->published_revision_id)
                ->first();

        if (! $published instanceof ArticleTranslationRevision) {
            throw new RuntimeException('Cannot isolate working revision because the published revision was not found.');
        }

        $nextRevisionNumber = ((int) ArticleTranslationRevision::query()
            ->withoutGlobalScopes()
            ->where('article_id', (int) $article->id)
            ->max('revision_number')) + 1;

        $working = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $published->org_id,
            'article_id' => (int) $article->id,
            'source_article_id' => $published->source_article_id !== null ? (int) $published->source_article_id : (int) $article->id,
            'translation_group_id' => (string) $published->translation_group_id,
            'locale' => (string) $published->locale,
            'source_locale' => $published->source_locale,
            'revision_number' => $nextRevisionNumber,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'source_version_hash' => (string) $published->source_version_hash,
            'translated_from_version_hash' => (string) $published->translated_from_version_hash,
            'title' => (string) $published->title,
            'excerpt' => $published->excerpt,
            'content_md' => (string) $published->content_md,
            'seo_title' => $published->seo_title,
            'seo_description' => $published->seo_description,
            'supersedes_revision_id' => (int) $published->id,
        ]);

        $article->forceFill(['working_revision_id' => (int) $working->id])->saveQuietly();
        $article->setRelation('workingRevision', $working);
        $article->setRelation('publishedRevision', $published);

        return $article;
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function metadata(array $item, Article $article, ArticleTranslationRevision $revision): array
    {
        return [
            'editorial_package_update_v1' => [
                'source' => 'existing_article_update_writer',
                'command' => 'articles:update-existing-seo-content-package',
                'article_id' => (int) $article->id,
                'working_revision_id' => (int) $revision->id,
                'translation_group_id' => (string) $item['translation_group_id'],
                'canonical_lock' => (string) $item['canonical_url'],
                'slug_lock' => (string) $item['slug'],
                'claim_gate_status' => (string) $item['claim_gate_status'],
                'schema_hold' => true,
                'hreflang_hold' => true,
                'search_submission_allowed' => false,
                'revalidation_allowed' => false,
                'sitemap_change_allowed' => false,
                'llms_change_allowed' => false,
                'body_hash' => $this->bodyHash((string) $item['body_markdown']),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $metadata
     */
    private function persistImportRecord(Article $article, ArticleTranslationRevision $revision, array $item, array $metadata): void
    {
        $bodyHash = (string) data_get($metadata, 'editorial_package_update_v1.body_hash');
        $jsonFields = [
            'validation_summary_json' => [
                'source' => 'articles:update-existing-seo-content-package',
                'operation' => 'update_existing_article_working_revision',
                'article_id' => (int) $article->id,
                'working_revision_id' => (int) $revision->id,
                'published_revision_id' => $article->published_revision_id !== null ? (int) $article->published_revision_id : null,
                'slug_lock' => (string) $item['slug'],
                'canonical_lock' => (string) $item['canonical_url'],
                'schema_hreflang_search_hold' => true,
                'preview_url_candidate' => '/ops/article-preview/'.(int) $article->id,
            ],
            'claim_result_json' => [
                'status' => (string) $item['claim_gate_status'],
                'matches' => [],
            ],
            'exactness_json' => [
                'status' => 'passed',
                'article_id' => (int) $article->id,
                'translation_group_id' => (string) $item['translation_group_id'],
                'canonical_url' => (string) $item['canonical_url'],
                'slug' => (string) $item['slug'],
                'body_hash' => $bodyHash,
            ],
            'references_json' => ['status' => 'operator_review_required'],
            'media_json' => ['status' => 'unchanged_hold'],
            'graph_json' => ['status' => 'unchanged_hold'],
            'answer_surface_json' => ['status' => 'visible_only'],
            'heading_sequence_json' => $this->headingSequence((string) $item['body_markdown']),
            'missing_fields_json' => [],
            'blocked_reasons_json' => [],
        ];

        foreach ($jsonFields as $field => $value) {
            $jsonFields[$field] = $this->normalizedJsonForWrite('article_editorial_package_import.'.$field, $value);
        }

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => (int) $article->org_id,
            'article_id' => (int) $article->id,
            'slug' => (string) $item['slug'],
            'locale' => (string) $item['locale'],
            'title' => (string) $item['title'],
            'content_track' => 'seo_content_package_existing_article_update',
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
            'intended_status' => 'working_revision_human_review',
            'body_hash' => $bodyHash,
            'references_count' => 0,
            'imported_by' => null,
            ...$jsonFields,
        ]);
    }

    private function normalizedJsonForWrite(string $field, mixed $value): mixed
    {
        $result = $this->jsonNormalizer->normalizeField($field, $value);
        if ($result['errors'] !== []) {
            $error = $result['errors'][0];

            throw new RuntimeException((string) $error['field'].' '.$error['code'].': '.$error['message']);
        }

        return $result['value'];
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     */
    private function validateJsonFieldSerialization(array $item, array &$errors, array &$warnings): void
    {
        $article = $item['article'] instanceof Article ? $item['article'] : null;
        if (! $article instanceof Article) {
            return;
        }

        $revision = $article->workingRevision instanceof ArticleTranslationRevision ? $article->workingRevision : null;
        $metadata = [
            'editorial_package_update_v1' => [
                'source' => 'existing_article_update_writer',
                'command' => 'articles:update-existing-seo-content-package',
                'article_id' => (int) $article->id,
                'working_revision_id' => $revision instanceof ArticleTranslationRevision ? (int) $revision->id : null,
                'translation_group_id' => (string) $item['translation_group_id'],
                'canonical_lock' => (string) $item['canonical_url'],
                'slug_lock' => (string) $item['slug'],
                'body_hash' => $this->bodyHash((string) $item['body_markdown']),
            ],
        ];

        foreach ([
            'article_editorial_package_import.validation_summary_json' => [
                'source' => 'articles:update-existing-seo-content-package',
                'metadata' => $metadata,
            ],
            'article_editorial_package_import.exactness_json' => [
                'status' => 'passed',
                'body_hash' => $this->bodyHash((string) $item['body_markdown']),
            ],
            'article_editorial_package_import.heading_sequence_json' => $this->headingSequence((string) $item['body_markdown']),
        ] as $field => $value) {
            $result = $this->jsonNormalizer->normalizeField($field, $value);
            foreach ($result['warnings'] as $warning) {
                $warnings[] = $warning;
            }
            foreach ($result['errors'] as $error) {
                $errors[] = $error;
            }
        }
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

    private function bodyHash(string $body): string
    {
        return hash('sha256', preg_replace("/\r\n?/", "\n", trim($body)) ?: trim($body));
    }

    private function firstString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,bool>
     */
    private function safetyFlagSnapshot(array $options): array
    {
        $snapshot = [];
        foreach (self::REQUIRED_HOLD_FLAGS as $flag) {
            $snapshot[$flag] = (bool) ($options[$flag] ?? false);
        }

        return $snapshot;
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
