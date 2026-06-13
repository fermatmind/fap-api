<?php

declare(strict_types=1);

namespace App\Services\Cms\SeoContentPackage;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\ArticleBodyHeadingGuard;
use App\Services\Cms\ArticleTranslationRevisionWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SeoContentPackageDraftImporter
{
    private const REQUIRED_FILES = [
        'manifest.json',
        'contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json',
        'contracts/ROUTE_ALIAS_CONTRACT.json',
        'contracts/SOCIAL_IMAGE_METADATA_REQUIREMENTS.json',
        'contracts/DYNAMIC_CTA_CONTRACT.json',
        'contracts/INTERNAL_LINK_PLAN.json',
        'contracts/PRIVATE_URL_GUARD.json',
        'review/claim_gate.md',
        'review/operator_review.md',
        'codex/qa_checklist.md',
    ];

    private const REQUIRED_FLAGS = [
        'draft_only',
        'no_publish',
        'no_index',
        'no_sitemap',
        'no_llms',
        'schema_hold',
        'hreflang_hold',
    ];

    private const PRIVATE_ROUTE_PATTERN = '~(?<![A-Za-z0-9_-])/(?:result|results|orders|order|share|pay|payment|history|take)(?:/|[?#\s)"\']|$)~i';

    private const SENSITIVE_QUERY_PATTERN = '/(?:[?&]|^)(?:result_id|order_id|payment_id|token|score|user_id|report_id)=/i';

    private const OLD_BIG_FIVE_ROUTE_PATTERN = '#/tests/big-five-personality-test(?!-ocean-model)#';

    private const CANONICAL_BIG_FIVE_ROUTE = '/tests/big-five-personality-test-ocean-model';

    private const SENSITIVE_QUERY_KEYS = [
        'result_id',
        'order_id',
        'payment_id',
        'token',
        'score',
        'user_id',
        'report_id',
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
    public function importFromDirectory(array $options): array
    {
        $plan = $this->buildPlan($options);
        if (($plan['ok'] ?? false) !== true) {
            return $plan;
        }

        $articles = [];
        $packageItems = is_array($plan['package_items'] ?? null) ? $plan['package_items'] : [];

        DB::transaction(function () use ($packageItems, &$articles): void {
            foreach ($packageItems as $item) {
                if (is_array($item)) {
                    $articles[] = $this->writeDraft($item);
                }
            }
        });

        return array_merge($plan, [
            'dry_run' => false,
            'action' => $this->summaryAction($articles),
            'would_write' => true,
            'articles' => $articles,
            'package_items' => [],
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
        $expectedTranslationGroupId = trim((string) ($options['translation_group_id'] ?? ''));
        $locales = $this->normalizeLocales($options['locales'] ?? []);
        $expectedSlugs = is_array($options['expected_slugs'] ?? null) ? $options['expected_slugs'] : [];

        $this->validateSafetyFlags($options, $errors);

        if ($expectedTranslationGroupId === '') {
            $errors[] = $this->issue('translation_group_id', 'missing_expected_translation_group_id', '--translation-group-id is required.');
        }
        $manifest = [];
        $packageItems = [];
        $guardScans = [
            'active_surface_guard_scan' => ['status' => 'not_run'],
            'contract_integrity_scan' => ['status' => 'not_run'],
        ];
        if ($packageRoot !== null) {
            $this->validateRequiredFiles($packageRoot, $errors);
            $manifest = $this->readJson($packageRoot.'/manifest.json', 'manifest.json', $errors);
            $guardScans = $this->validatePackageGuardScopes($packageRoot, $errors);
            $locales = $this->validatedLocales($locales, $manifest, $errors);
            $packageItems = $this->buildPackageItems($packageRoot, $manifest, $locales, $expectedTranslationGroupId, $expectedSlugs, $errors, $warnings);
            $this->validateJsonFieldSerialization($packageItems, $errors, $warnings);
        }

        $ok = $errors === [];
        $plannedArticles = $ok ? array_map(fn (array $item): array => $this->plannedArticle($item), $packageItems) : [];

        return [
            'ok' => $ok,
            'dry_run' => (bool) ($options['dry_run'] ?? true),
            'action' => $ok ? $this->summaryAction($plannedArticles) : 'will_skip',
            'would_write' => $ok,
            'translation_group_id' => $expectedTranslationGroupId,
            'package_root' => $packageRoot,
            'manifest_status' => $manifest !== [] ? 'valid_json' : 'missing_or_invalid',
            'active_surface_guard_scan' => $guardScans['active_surface_guard_scan'],
            'contract_integrity_scan' => $guardScans['contract_integrity_scan'],
            'safety_flags' => $this->safetyFlagSnapshot($options),
            'articles' => $plannedArticles,
            'package_items' => $packageItems,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function writeDraft(array $item): array
    {
        $existing = $this->existingArticle($item);
        if ($existing instanceof Article && $this->isPublishedOrPublic($existing)) {
            throw new RuntimeException('Existing published/public article cannot be mutated by SEO content package draft import.');
        }

        $category = $this->resolveCategory();
        $metadata = $this->normalizedJsonForWrite('article.cover_image_variants', $this->metadata($item));
        $body = (string) $item['body_markdown'];

        $article = $existing instanceof Article ? $existing : new Article;
        $article->forceFill([
            'org_id' => 0,
            'category_id' => (int) $category->id,
            'author_name' => 'Fermat Institute',
            'reviewer_name' => null,
            'reading_minutes' => $this->readingMinutes($body),
            'slug' => (string) $item['slug'],
            'locale' => (string) $item['locale'],
            'translation_group_id' => (string) $item['translation_group_id'],
            'title' => (string) $item['title'],
            'excerpt' => (string) $item['excerpt'],
            'content_md' => $body,
            'content_html' => null,
            'cover_image_url' => (string) $item['cover_image_url'],
            'cover_image_alt' => (string) $item['cover_image_alt'],
            'cover_image_width' => (int) $item['cover_image_width'],
            'cover_image_height' => (int) $item['cover_image_height'],
            'cover_image_variants' => $metadata,
            'related_test_slug' => (string) $item['related_test_slug'],
            'status' => 'draft',
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
            'content_md' => $body,
            'seo_title' => (string) $item['meta_title'],
            'seo_description' => (string) $item['meta_description'],
            'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
        ]);

        $article->forceFill([
            'working_revision_id' => (int) $revision->id,
            'status' => 'draft',
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
                'locale' => (string) $item['locale'],
            ],
            [
                'seo_title' => (string) $item['meta_title'],
                'seo_description' => (string) $item['meta_description'],
                'canonical_url' => (string) $item['canonical_url'],
                'og_title' => (string) $item['meta_title'],
                'og_description' => (string) $item['meta_description'],
                'og_image_url' => (string) $item['og_image_url'],
                'robots' => 'noindex,nofollow',
                'schema_json' => $this->normalizedJsonForWrite('article_seo_meta.schema_json', [
                    'editorial_package_v1' => $metadata['editorial_package_v1'],
                ]),
                'is_indexable' => false,
            ],
        );

        $this->persistImportRecord($article, $revision, $item, $metadata);

        return [
            'locale' => (string) $item['locale'],
            'slug' => (string) $item['slug'],
            'action' => $existing instanceof Article ? 'updated_working_revision' : 'created_draft',
            'article_id' => (int) $article->id,
            'working_revision_id' => (int) $revision->id,
            'status' => 'draft',
            'working_revision_status' => (string) $revision->revision_status,
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'preview_url_candidate' => '/ops/article-preview/'.(int) $article->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function plannedArticle(array $item): array
    {
        $existing = $this->existingArticle($item);

        return [
            'locale' => (string) $item['locale'],
            'slug' => (string) $item['slug'],
            'action' => $existing instanceof Article ? 'would_update_working_revision' : 'would_create_draft',
            'article_id' => $existing instanceof Article ? (int) $existing->id : null,
            'working_revision_id' => null,
            'status' => 'draft',
            'working_revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'preview_url_candidate' => $existing instanceof Article ? '/ops/article-preview/'.(int) $existing->id : null,
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
        foreach (self::REQUIRED_FILES as $relativePath) {
            if (! is_file($root.'/'.$relativePath)) {
                $errors[] = $this->issue($relativePath, 'missing_required_file', $relativePath.' is required.');
            }
        }

        if ((glob($root.'/pages/*.md') ?: []) === []) {
            $errors[] = $this->issue('pages/*.md', 'missing_pages', 'At least one Markdown page is required.');
        }
        if ((glob($root.'/cms/CMS_FIELDS_*.json') ?: []) === []) {
            $errors[] = $this->issue('cms/CMS_FIELDS_*.json', 'missing_cms_fields', 'CMS_FIELDS JSON files are required.');
        }
        if ((glob($root.'/cms/CMS_IMPORT_DRAFT_*.json') ?: []) === []) {
            $errors[] = $this->issue('cms/CMS_IMPORT_DRAFT_*.json', 'missing_cms_import_drafts', 'CMS_IMPORT_DRAFT JSON files are required.');
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
     * @param  list<array<string,mixed>>  $errors
     * @return array{active_surface_guard_scan:array<string,mixed>,contract_integrity_scan:array<string,mixed>}
     */
    private function validatePackageGuardScopes(string $root, array &$errors): array
    {
        $activeErrorStart = count($errors);
        $activeSurfaceText = $this->activeSurfaceText($root);
        $packageText = $this->packageText($root);

        if (preg_match(self::OLD_BIG_FIVE_ROUTE_PATTERN, $activeSurfaceText) === 1) {
            $errors[] = $this->issue('active_surface_guard_scan.big_five_route', 'old_big_five_route_found_in_active_surface', 'Old Big Five route is forbidden in active import surfaces.');
        }
        if (preg_match(self::OLD_BIG_FIVE_ROUTE_PATTERN, $packageText) === 1
            && ! str_contains($packageText, self::CANONICAL_BIG_FIVE_ROUTE)) {
            $errors[] = $this->issue('big_five_route', 'required_big_five_route_missing', 'Canonical Big Five route is required.');
        }
        if (str_contains($activeSurfaceText, '__CMS_MEDIA_LIBRARY_PLACEHOLDER__')) {
            $errors[] = $this->issue('social_image', 'media_placeholder_found', 'CMS media placeholder marker is forbidden.');
        }
        if (preg_match(self::PRIVATE_ROUTE_PATTERN, $activeSurfaceText) === 1) {
            $errors[] = $this->issue('active_surface_guard_scan.private_url_guard', 'private_route_found_in_active_surface', 'Private routes are forbidden in active import surfaces.');
        }
        if (preg_match(self::SENSITIVE_QUERY_PATTERN, $activeSurfaceText) === 1) {
            $errors[] = $this->issue('active_surface_guard_scan.private_url_guard', 'sensitive_query_key_found_in_active_surface', 'Sensitive query keys are forbidden in active import surfaces.');
        }
        $activeErrorCount = count($errors) - $activeErrorStart;

        $contractErrorStart = count($errors);
        $this->validateRouteAliasContract($root, $errors);
        $this->validatePrivateUrlGuardContract($root, $errors);
        $this->validateDynamicCtaContract($root, $errors);
        $contractErrorCount = count($errors) - $contractErrorStart;

        return [
            'active_surface_guard_scan' => [
                'status' => $activeErrorCount === 0 ? 'passed' : 'failed',
                'error_count' => $activeErrorCount,
            ],
            'contract_integrity_scan' => [
                'status' => $contractErrorCount === 0 ? 'passed' : 'failed',
                'error_count' => $contractErrorCount,
            ],
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

        $parts[] = (string) json_encode(
            $this->withoutPolicyKeys($this->readJsonLenient($root.'/contracts/DYNAMIC_CTA_CONTRACT.json')),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $parts[] = (string) json_encode(
            $this->withoutPolicyKeys($this->readJsonLenient($root.'/contracts/INTERNAL_LINK_PLAN.json')),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $parts[] = (string) json_encode(
            $this->readJsonLenient($root.'/contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json'),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return implode("\n", $parts);
    }

    private function packageText(string $root): string
    {
        $files = array_merge(
            glob($root.'/manifest.json') ?: [],
            glob($root.'/pages/*.md') ?: [],
            glob($root.'/cms/*.json') ?: [],
            glob($root.'/contracts/*') ?: [],
            glob($root.'/review/*') ?: [],
            glob($root.'/codex/*') ?: [],
        );

        $text = '';
        foreach ($files as $file) {
            if (is_file($file)) {
                $text .= "\n".(string) file_get_contents($file);
            }
        }

        return $text;
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

    private function withoutPolicyKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $filtered = [];
        foreach ($value as $key => $child) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, [
                'forbidden',
                'forbidden_paths',
                'forbidden_private_routes',
                'forbidden_query_keys',
                'forbidden_sensitive_query_keys',
                'forbidden_tracking_params',
                'private_url_guard_ref',
                'private_links_forbidden',
            ], true)) {
                continue;
            }

            $filtered[$key] = $this->withoutPolicyKeys($child);
        }

        return $filtered;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateRouteAliasContract(string $root, array &$errors): void
    {
        $path = $root.'/contracts/ROUTE_ALIAS_CONTRACT.json';
        $contract = $this->readJsonLenient($path);
        $aliases = $this->aliasMappings($contract);
        $allowedOldAliasCount = 0;

        foreach ($aliases as $alias => $target) {
            if (preg_match(self::OLD_BIG_FIVE_ROUTE_PATTERN, (string) $alias) === 1) {
                if ($target !== self::CANONICAL_BIG_FIVE_ROUTE) {
                    $errors[] = $this->issue('contract_integrity_scan.route_alias_contract', 'route_alias_contract_invalid', 'Old Big Five alias must resolve to the canonical OCEAN route.');
                } else {
                    $allowedOldAliasCount++;
                }
            }

            if (preg_match(self::OLD_BIG_FIVE_ROUTE_PATTERN, $target) === 1) {
                $errors[] = $this->issue('contract_integrity_scan.route_alias_contract', 'route_alias_contract_invalid', 'Old Big Five route is forbidden as an alias target.');
            }
        }

        $raw = is_file($path) ? (string) file_get_contents($path) : '';
        $oldRouteCount = preg_match_all(self::OLD_BIG_FIVE_ROUTE_PATTERN, $raw);
        if ($oldRouteCount > $allowedOldAliasCount) {
            $errors[] = $this->issue('contract_integrity_scan.route_alias_contract', 'route_alias_contract_invalid', 'Old Big Five route may appear only as a known alias key.');
        }
    }

    /**
     * @param  array<string,mixed>  $contract
     * @return array<string,string>
     */
    private function aliasMappings(array $contract): array
    {
        $candidates = [];
        foreach (['known_aliases', 'aliases', 'route_aliases'] as $key) {
            if (is_array($contract[$key] ?? null)) {
                $candidates[] = $contract[$key];
            }
        }
        if ($candidates === []) {
            $candidates[] = $contract;
        }

        $aliases = [];
        foreach ($candidates as $candidate) {
            foreach ($candidate as $alias => $target) {
                if (is_string($alias) && is_string($target) && str_starts_with($alias, '/')) {
                    $aliases[$alias] = $target;
                }
            }
        }

        return $aliases;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validatePrivateUrlGuardContract(string $root, array &$errors): void
    {
        $contract = $this->readJsonLenient($root.'/contracts/PRIVATE_URL_GUARD.json');
        $invalidPaths = [];
        $this->collectPrivateGuardViolations($contract, [], false, $invalidPaths);

        if ($invalidPaths !== []) {
            $errors[] = $this->issue('contract_integrity_scan.private_url_guard', 'private_url_guard_contract_invalid', 'Private URL guard contract contains private routes or sensitive keys outside forbidden guard fields.');
        }
    }

    /**
     * @param  list<string>  $path
     * @param  list<string>  $invalidPaths
     */
    private function collectPrivateGuardViolations(mixed $value, array $path, bool $allowedGuardContext, array &$invalidPaths): void
    {
        $key = strtolower((string) end($path));
        $allowed = $allowedGuardContext || in_array($key, [
            'forbidden',
            'forbidden_paths',
            'forbidden_private_routes',
            'forbidden_query_keys',
            'forbidden_sensitive_query_keys',
            'forbidden_substrings',
            'sensitive_query_keys',
        ], true);

        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $this->collectPrivateGuardViolations($child, [...$path, (string) $childKey], $allowed, $invalidPaths);
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        if ((preg_match(self::PRIVATE_ROUTE_PATTERN, $value) === 1 || $this->isSensitiveQueryKeyLiteral($value)) && ! $allowed) {
            $invalidPaths[] = implode('.', $path);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateDynamicCtaContract(string $root, array &$errors): void
    {
        $contract = $this->readJsonLenient($root.'/contracts/DYNAMIC_CTA_CONTRACT.json');
        $invalidPaths = [];
        $this->collectDynamicCtaSensitiveKeyViolations($contract, [], false, $invalidPaths);

        if ($invalidPaths !== []) {
            $errors[] = $this->issue('contract_integrity_scan.dynamic_cta_contract', 'dynamic_cta_forbidden_params_contract_invalid', 'Sensitive tracking params may appear only in forbidden_tracking_params.');
        }
    }

    /**
     * @param  list<string>  $path
     * @param  list<string>  $invalidPaths
     */
    private function collectDynamicCtaSensitiveKeyViolations(mixed $value, array $path, bool $allowedForbiddenParamContext, array &$invalidPaths): void
    {
        $key = strtolower((string) end($path));
        $allowed = $allowedForbiddenParamContext || in_array($key, [
            'forbidden_tracking_params',
            'forbidden_parameters',
            'forbidden_query_keys',
            'forbidden_sensitive_query_keys',
        ], true);

        if (is_array($value)) {
            foreach ($value as $childKey => $child) {
                $this->collectDynamicCtaSensitiveKeyViolations($child, [...$path, (string) $childKey], $allowed, $invalidPaths);
            }

            return;
        }

        if (is_string($value) && $this->isSensitiveQueryKeyLiteral($value) && ! $allowed) {
            $invalidPaths[] = implode('.', $path);
        }
    }

    private function isSensitiveQueryKeyLiteral(string $value): bool
    {
        return in_array(strtolower(trim($value)), self::SENSITIVE_QUERY_KEYS, true);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $expectedSlugs
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return list<array<string,mixed>>
     */
    private function buildPackageItems(
        string $root,
        array $manifest,
        array $locales,
        string $expectedTranslationGroupId,
        array $expectedSlugs,
        array &$errors,
        array &$warnings
    ): array {
        $manifestTranslationGroupId = trim((string) ($manifest['translation_group_id'] ?? ''));
        if ($manifestTranslationGroupId !== '' && $manifestTranslationGroupId !== $expectedTranslationGroupId) {
            $errors[] = $this->issue('manifest.translation_group_id', 'translation_group_id_mismatch', 'manifest translation_group_id does not match expected value.');
        }

        $items = [];
        foreach ($locales as $locale) {
            $import = $this->readFirstJson($root.'/cms/CMS_IMPORT_DRAFT_'.$locale.'_*.json', 'cms import '.$locale, $errors);
            $fields = $this->readFirstJson($root.'/cms/CMS_FIELDS_'.$locale.'_*.json', 'cms fields '.$locale, $errors);
            $pagePath = $this->pagePathFor($root, $locale, $import, $manifest, $errors);
            $page = $pagePath !== null ? $this->readMarkdownPage($pagePath) : ['frontmatter' => [], 'body' => ''];

            $item = $this->normalizeItem($root, $locale, $manifest, $import, $fields, $page, $errors, $warnings);
            $expectedSlug = trim((string) ($expectedSlugs[$locale] ?? ''));
            if ($expectedSlug !== '' && (string) ($item['slug'] ?? '') !== $expectedSlug) {
                $errors[] = $this->issue($locale.'.slug', 'expected_slug_mismatch', 'Package slug does not match expected slug.');
            }
            if ($item !== [] && (string) ($item['translation_group_id'] ?? '') !== $expectedTranslationGroupId) {
                $errors[] = $this->issue($locale.'.translation_group_id', 'translation_group_id_mismatch', 'Package translation_group_id does not match expected value.');
            }

            if ($item !== []) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     * @return array<string,mixed>
     */
    private function readFirstJson(string $pattern, string $field, array &$errors): array
    {
        $matches = glob($pattern) ?: [];
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
     * @param  array<string,mixed>  $import
     * @param  array<string,mixed>  $fields
     * @param  array{frontmatter:array<string,mixed>,body:string}  $page
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     * @return array<string,mixed>
     */
    private function normalizeItem(
        string $root,
        string $locale,
        array $manifest,
        array $import,
        array $fields,
        array $page,
        array &$errors,
        array &$warnings
    ): array {
        $frontmatter = $page['frontmatter'];
        $body = $this->articleBodyHeadingGuard->downgradeMarkdownH1ToH2((string) $page['body']);
        $manifestPage = $this->manifestPage($manifest, $locale);
        $translationGroupId = $this->firstString($import['translation_group_id'] ?? null, $fields['translation_group_id'] ?? null, $frontmatter['translation_group_id'] ?? null, $manifest['translation_group_id'] ?? null);
        $slug = Str::slug($this->firstString($import['slug'] ?? null, $fields['slug'] ?? null, $frontmatter['slug'] ?? null, $manifestPage['slug'] ?? null));
        $title = $this->firstString($import['title'] ?? null, $fields['title'] ?? null, $frontmatter['title'] ?? null, $manifestPage['title'] ?? null);
        $metaTitle = $this->firstString($import['meta_title'] ?? null, $import['meta_title_draft'] ?? null, $fields['meta_title'] ?? null, $fields['meta_title_draft'] ?? null, $frontmatter['meta_title_draft'] ?? null, $manifestPage['meta_title_draft'] ?? null, $title);
        $metaDescription = $this->firstString($import['meta_description'] ?? null, $import['meta_description_draft'] ?? null, $fields['meta_description'] ?? null, $fields['meta_description_draft'] ?? null, $frontmatter['meta_description_draft'] ?? null, $manifestPage['meta_description_draft'] ?? null);
        $canonical = $this->firstString($import['canonical_url'] ?? null, $import['canonical_url_draft'] ?? null, $fields['canonical_url'] ?? null, $fields['canonical_url_draft'] ?? null, $frontmatter['canonical_url_draft'] ?? null, $manifestPage['canonical_url_draft'] ?? null);
        $claimGateStatus = $this->firstString($import['claim_gate_status'] ?? null, $fields['claim_gate_status'] ?? null, $frontmatter['claim_gate_status'] ?? null, 'not_reviewed');
        $social = is_array($import['social_image_metadata'] ?? null) ? $import['social_image_metadata'] : (is_array($fields['social_image_metadata'] ?? null) ? $fields['social_image_metadata'] : []);
        $primaryCtaPath = $this->firstString(data_get($import, 'primary_cta.href'), data_get($fields, 'primary_cta.href'), $import['primary_cta'] ?? null, $fields['primary_cta'] ?? null, $frontmatter['primary_cta'] ?? null, $import['primary_hub_url'] ?? null, $fields['primary_hub_url'] ?? null, $frontmatter['primary_hub_url'] ?? null);

        $item = [
            'locale' => $locale,
            'translation_group_id' => $translationGroupId,
            'slug' => $slug,
            'title' => $title,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'excerpt' => mb_substr($metaDescription !== '' ? $metaDescription : trim(strip_tags($body)), 0, 240),
            'canonical_url' => $canonical,
            'body_markdown' => $body,
            'primary_keyword' => $fields['primary_keyword'] ?? $import['primary_keyword'] ?? $frontmatter['primary_keyword'] ?? null,
            'secondary_keywords' => $fields['secondary_keywords'] ?? $import['secondary_keywords'] ?? $frontmatter['secondary_keywords'] ?? null,
            'claim_gate_status' => $claimGateStatus,
            'publish_allowed' => (bool) ($import['publish_allowed'] ?? $fields['publish_allowed'] ?? $frontmatter['publish_allowed'] ?? false),
            'sitemap_eligible' => (bool) ($import['sitemap_eligible'] ?? $fields['sitemap_eligible'] ?? $frontmatter['sitemap_eligible'] ?? false),
            'llms_eligible' => (bool) ($import['llms_eligible'] ?? $fields['llms_eligible'] ?? $frontmatter['llms_eligible'] ?? false),
            'schema_eligibility' => is_array($import['schema_eligibility'] ?? null) ? $import['schema_eligibility'] : ($fields['schema_eligibility'] ?? []),
            'cover_media_asset_key' => $this->firstString($import['cover_media_asset_key'] ?? null, $fields['cover_media_asset_key'] ?? null, $social['media_library_asset_key'] ?? null),
            'cover_image_url' => $this->firstString($import['cover_image_url'] ?? null, $fields['cover_image_url'] ?? null, $social['cover_image_url'] ?? null),
            'cover_image_alt' => $this->firstString($import['cover_image_alt'] ?? null, $fields['cover_image_alt'] ?? null, $social['alt_text'] ?? null),
            'cover_image_width' => (int) ($import['cover_image_width'] ?? $fields['cover_image_width'] ?? $social['width'] ?? 0),
            'cover_image_height' => (int) ($import['cover_image_height'] ?? $fields['cover_image_height'] ?? $social['height'] ?? 0),
            'cover_image_variants' => is_array($import['cover_image_variants'] ?? null) ? $import['cover_image_variants'] : ($fields['cover_image_variants'] ?? []),
            'og_image_url' => $this->firstString($import['og_image_url'] ?? null, $fields['og_image_url'] ?? null, data_get($social, 'og_1200x630_variant.url'), $social['twitter_image_url'] ?? null),
            'twitter_image_url' => $this->firstString($import['twitter_image_url'] ?? null, $fields['twitter_image_url'] ?? null, $social['twitter_image_url'] ?? null),
            'social_image_metadata' => $social,
            'primary_cta_path' => $primaryCtaPath,
            'related_test_slug' => $this->testSlugFromPath($primaryCtaPath),
            'source_package_root' => $root,
        ];

        $this->validateItem($item, $errors, $warnings);

        return $item;
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

        if (! is_string($item['primary_keyword'] ?? null)) {
            $errors[] = $this->issue($locale.'.primary_keyword', 'primary_keyword_not_string', 'primary_keyword must be a string.');
        }
        if (! is_array($item['secondary_keywords'] ?? null)) {
            $errors[] = $this->issue($locale.'.secondary_keywords', 'secondary_keywords_not_array', 'secondary_keywords must be an array.');
        }
        if (! in_array((string) $item['claim_gate_status'], ['not_reviewed', 'human_review'], true)) {
            $errors[] = $this->issue($locale.'.claim_gate_status', 'invalid_claim_gate_status', 'claim_gate_status must be not_reviewed or human_review.');
        }
        if ((bool) $item['publish_allowed']) {
            $errors[] = $this->issue($locale.'.publish_allowed', 'publish_allowed_true_forbidden', 'publish_allowed must remain false.');
        }
        if ((bool) $item['sitemap_eligible']) {
            $errors[] = $this->issue($locale.'.sitemap_eligible', 'sitemap_eligible_true_forbidden', 'sitemap_eligible must remain false.');
        }
        if ((bool) $item['llms_eligible']) {
            $errors[] = $this->issue($locale.'.llms_eligible', 'llms_eligible_true_forbidden', 'llms_eligible must remain false.');
        }
        if (! str_starts_with((string) $item['canonical_url'], $locale === 'zh-CN' ? '/zh/articles/' : '/en/articles/')) {
            $errors[] = $this->issue($locale.'.canonical_url', 'invalid_canonical_route', 'canonical_url must be a locale article route.');
        }
        if ((string) $item['cover_media_asset_key'] === '' || (string) $item['cover_image_url'] === '' || (string) $item['og_image_url'] === '') {
            $errors[] = $this->issue($locale.'.social_image_metadata', 'missing_social_image_metadata', 'social image asset, cover URL, and OG URL are required.');
        }
        if ((int) $item['cover_image_width'] <= 0 || (int) $item['cover_image_height'] <= 0) {
            $errors[] = $this->issue($locale.'.social_image_metadata', 'missing_social_image_dimensions', 'social image width and height are required.');
        }
        if (is_array($item['social_image_metadata'])
            && isset($item['social_image_metadata']['media_library_status'])
            && (string) $item['social_image_metadata']['media_library_status'] !== 'published') {
            $errors[] = $this->issue($locale.'.social_image_metadata', 'social_image_not_published', 'Media Library asset must be published.');
        }
        if (is_array($item['social_image_metadata'])
            && array_key_exists('is_public', $item['social_image_metadata'])
            && (bool) $item['social_image_metadata']['is_public'] !== true) {
            $errors[] = $this->issue($locale.'.social_image_metadata', 'social_image_not_public', 'Media Library asset must be public.');
        }
        if (! is_array($item['schema_eligibility'] ?? null)
            || (bool) data_get($item, 'schema_eligibility.faq_schema', false) !== false) {
            $errors[] = $this->issue($locale.'.schema_eligibility', 'schema_hold_not_satisfied', 'FAQ schema must remain disabled.');
        }

        if (trim((string) $item['twitter_image_url']) === '') {
            $warnings[] = $this->issue($locale.'.twitter_image_url', 'twitter_image_reuses_og', 'Twitter image is missing and should reuse OG if needed.');
        }
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function existingArticle(array $item): ?Article
    {
        return Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('translation_group_id', (string) $item['translation_group_id'])
            ->where('locale', (string) $item['locale'])
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

    private function resolveCategory(): ArticleCategory
    {
        return ArticleCategory::query()->withoutGlobalScopes()->firstOrCreate(
            ['org_id' => 0, 'slug' => 'seo-articles'],
            ['name' => 'SEO Articles', 'is_active' => true, 'sort_order' => 0]
        );
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function metadata(array $item): array
    {
        $variants = is_array($item['cover_image_variants'] ?? null) ? $item['cover_image_variants'] : [];
        $variants['editorial_package_v1'] = [
            'source' => 'seo_content_package_mode_c',
            'translation_group_id' => (string) $item['translation_group_id'],
            'claim_gate_status' => (string) $item['claim_gate_status'],
            'publish_allowed' => false,
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'schema_hold' => true,
            'hreflang_hold' => true,
            'search_submission_allowed' => false,
            'revalidation_allowed' => false,
            'cover_media_asset_key' => (string) $item['cover_media_asset_key'],
            'social_image_metadata' => $item['social_image_metadata'],
            'body_hash' => hash('sha256', preg_replace("/\r\n?/", "\n", trim((string) $item['body_markdown'])) ?: trim((string) $item['body_markdown'])),
        ];

        return $variants;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<array<string,mixed>>  $errors
     * @param  list<array<string,mixed>>  $warnings
     */
    private function validateJsonFieldSerialization(array $items, array &$errors, array &$warnings): void
    {
        foreach ($items as $item) {
            $metadata = $this->metadata($item);
            foreach ($this->jsonFieldPayloads($item, $metadata) as $field => $value) {
                $result = $this->jsonNormalizer->normalizeField($field, $value);
                foreach ($result['warnings'] as $warning) {
                    $warnings[] = $warning;
                }
                foreach ($result['errors'] as $error) {
                    $errors[] = $error;
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function jsonFieldPayloads(
        array $item,
        array $metadata,
        ?Article $article = null,
        ?ArticleTranslationRevision $revision = null
    ): array {
        $articleId = $article instanceof Article ? (int) $article->id : null;
        $workingRevisionId = $revision instanceof ArticleTranslationRevision ? (int) $revision->id : null;

        return [
            'article.cover_image_variants' => $metadata,
            'article_seo_meta.schema_json' => [
                'editorial_package_v1' => $metadata['editorial_package_v1'] ?? [],
            ],
            'article_editorial_package_import.validation_summary_json' => [
                'source' => 'articles:import-seo-content-package-draft',
                'working_revision_id' => $workingRevisionId,
                'preview_url_candidate' => $articleId !== null ? '/ops/article-preview/'.$articleId : null,
            ],
            'article_editorial_package_import.claim_result_json' => [
                'status' => (string) $item['claim_gate_status'],
                'matches' => [],
            ],
            'article_editorial_package_import.exactness_json' => [
                'translation_group_id' => (string) $item['translation_group_id'],
                'canonical_url' => (string) $item['canonical_url'],
            ],
            'article_editorial_package_import.references_json' => ['status' => 'operator_review_required'],
            'article_editorial_package_import.media_json' => [
                'status' => 'complete',
                'cover_media_asset_key' => (string) $item['cover_media_asset_key'],
                'cover_image_url' => (string) $item['cover_image_url'],
                'og_image_url' => (string) $item['og_image_url'],
            ],
            'article_editorial_package_import.graph_json' => [
                'primary_cta' => (string) ($item['primary_cta_path'] ?? ''),
                'related_test_slug' => (string) ($item['related_test_slug'] ?? ''),
                'big_five_route' => str_contains((string) ($item['body_markdown'] ?? ''), self::CANONICAL_BIG_FIVE_ROUTE)
                    ? self::CANONICAL_BIG_FIVE_ROUTE
                    : null,
            ],
            'article_editorial_package_import.answer_surface_json' => ['status' => 'visible_only'],
            'article_editorial_package_import.heading_sequence_json' => $this->headingSequence((string) $item['body_markdown']),
            'article_editorial_package_import.missing_fields_json' => [],
            'article_editorial_package_import.blocked_reasons_json' => [],
        ];
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
     * @param  array<string,mixed>  $metadata
     */
    private function persistImportRecord(Article $article, ArticleTranslationRevision $revision, array $item, array $metadata): void
    {
        $jsonFields = [];
        foreach ($this->jsonFieldPayloads($item, $metadata, $article, $revision) as $field => $value) {
            if (! str_starts_with($field, 'article_editorial_package_import.')) {
                continue;
            }

            $jsonFields[Str::after($field, 'article_editorial_package_import.')] = $this->normalizedJsonForWrite($field, $value);
        }

        ArticleEditorialPackageImport::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'slug' => (string) $item['slug'],
            'locale' => (string) $item['locale'],
            'title' => (string) $item['title'],
            'content_track' => 'seo_content_package_mode_c',
            'status' => ArticleEditorialPackageImport::STATUS_IMPORTED,
            'intended_status' => 'draft',
            'body_hash' => (string) data_get($metadata, 'editorial_package_v1.body_hash'),
            'references_count' => 0,
            'imported_by' => null,
            ...$jsonFields,
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
        $characters = max(1, mb_strlen(strip_tags($body)));

        return max(1, (int) ceil($characters / 700));
    }

    /**
     * @return list<string>
     */
    private function normalizeLocales(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $locale): string => (string) $locale, $value));
    }

    /**
     * @param  list<string>  $requestedLocales
     * @param  array<string,mixed>  $manifest
     * @param  list<array<string,mixed>>  $errors
     * @return list<string>
     */
    private function validatedLocales(array $requestedLocales, array $manifest, array &$errors): array
    {
        $locales = array_values(array_filter($requestedLocales, static fn (string $locale): bool => $locale !== ''));
        $allowedSets = [
            ['zh-CN', 'en'],
            ['zh-CN'],
        ];

        if (! in_array($locales, $allowedSets, true)) {
            $errors[] = $this->issue('locales', 'unsupported_locale_set', '--locales must resolve to zh-CN,en or zh-CN.');

            return $locales;
        }

        $manifestLocales = array_values(array_filter(array_map(
            static fn (mixed $locale): string => (string) $locale,
            (array) ($manifest['locale_scope'] ?? [])
        ), static fn (string $locale): bool => $locale !== ''));

        if ($manifestLocales !== []) {
            foreach ($locales as $locale) {
                if (! in_array($locale, $manifestLocales, true)) {
                    $errors[] = $this->issue('locales', 'locale_not_in_manifest_scope', 'Requested locale is not present in manifest locale_scope.');
                }
            }
        }

        return $locales;
    }

    private function testSlugFromPath(string $path): string
    {
        if (preg_match('~/(?:zh|en)/tests/([^/?#]+)~', $path, $matches) === 1) {
            return (string) $matches[1];
        }

        if (preg_match('~/tests/([^/?#]+)~', $path, $matches) === 1) {
            return (string) $matches[1];
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  list<array<string,mixed>>  $errors
     */
    private function validateSafetyFlags(array $options, array &$errors): void
    {
        foreach (self::REQUIRED_FLAGS as $flag) {
            if ((bool) ($options[$flag] ?? false) !== true) {
                $errors[] = $this->issue('flags.'.$flag, 'missing_required_safety_flag', '--'.str_replace('_', '-', $flag).' is required.');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,bool>
     */
    private function safetyFlagSnapshot(array $options): array
    {
        $snapshot = [];
        foreach (self::REQUIRED_FLAGS as $flag) {
            $snapshot[$flag] = (bool) ($options[$flag] ?? false);
        }

        return $snapshot;
    }

    /**
     * @param  list<array<string,mixed>>  $articles
     */
    private function summaryAction(array $articles): string
    {
        if ($articles === []) {
            return 'will_skip';
        }

        $actions = array_values(array_unique(array_map(
            static fn (array $article): string => (string) ($article['action'] ?? ''),
            $articles
        )));

        return count($actions) === 1 ? $actions[0] : 'mixed';
    }

    private function firstString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (is_string($value) || is_numeric($value) || is_bool($value)) {
                $normalized = trim((string) $value);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }

    /**
     * @return array{field:string,code:string,message:string}
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
