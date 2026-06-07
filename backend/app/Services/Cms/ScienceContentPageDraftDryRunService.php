<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\ContentPage;
use Symfony\Component\Yaml\Yaml;

final class ScienceContentPageDraftDryRunService
{
    private const EXPECTED_PAGE_KEYS = [
        'SCIENCE-HUB-CONTENT-01',
        'METHOD-BOUNDARY-CONTENT-01',
        'ITEM-DESIGN-CONTENT-01',
        'RELIABILITY-VALIDITY-CONTENT-01',
        'DATA-NOTES-CONTENT-01',
        'MISCONCEPTIONS-CONTENT-01',
    ];

    private const DRAFT_FALSE_FIELDS = [
        'is_public',
        'is_indexable',
        'sitemap_eligible',
        'llms_eligible',
        'footer_eligible',
    ];

    /**
     * @return array<string, mixed>
     */
    public function dryRun(string $packagePath): array
    {
        $root = rtrim($packagePath, DIRECTORY_SEPARATOR);
        if ($root === '' || ! is_dir($root)) {
            throw new \RuntimeException('Science ContentPage draft package directory does not exist: '.$packagePath);
        }

        $manifestPath = $root.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            throw new \RuntimeException('manifest.json is required in the package root.');
        }

        $manifest = $this->readJson($manifestPath);
        $issues = [];
        $pages = [];

        $defaults = $manifest['eligibility_defaults'] ?? null;
        if (! is_array($defaults)) {
            $issues[] = $this->issue('manifest', 'missing_eligibility_defaults', 'eligibility_defaults is required.');
        } else {
            foreach (self::DRAFT_FALSE_FIELDS as $field) {
                if (($defaults[$field] ?? null) !== false) {
                    $issues[] = $this->issue('manifest', 'unsafe_default_'.$field, $field.' must default to false.');
                }
            }
        }

        $manifestPages = $manifest['pages'] ?? null;
        if (! is_array($manifestPages)) {
            $issues[] = $this->issue('manifest', 'missing_pages', 'manifest pages must be an array.');
            $manifestPages = [];
        }

        $seenKeys = [];
        foreach ($manifestPages as $index => $page) {
            if (! is_array($page)) {
                $issues[] = $this->issue('manifest.pages['.$index.']', 'invalid_page_entry', 'page entry must be an object.');

                continue;
            }

            $pageResult = $this->validatePage($root, $page, $index);
            $pages[] = $pageResult;
            $seenKeys[] = (string) ($pageResult['page_key'] ?? '');
            foreach ($pageResult['issues'] as $issue) {
                $issues[] = $issue;
            }
        }

        $missingKeys = array_values(array_diff(self::EXPECTED_PAGE_KEYS, $seenKeys));
        foreach ($missingKeys as $missingKey) {
            $issues[] = $this->issue('manifest', 'missing_expected_page', 'Missing expected page '.$missingKey.'.');
        }

        $duplicateKeys = $this->duplicates($seenKeys);
        foreach ($duplicateKeys as $duplicateKey) {
            $issues[] = $this->issue('manifest', 'duplicate_page_key', 'Duplicate page_key '.$duplicateKey.'.');
        }

        $blockingPages = array_values(array_filter(
            $pages,
            static fn (array $page): bool => ($page['blocks_package_dry_run'] ?? true) === true
        ));
        $reconciledAuthorityPages = array_values(array_filter(
            $pages,
            static fn (array $page): bool => ($page['draft_import_decision'] ?? '') === 'existing_authority_reconciliation_ready'
        ));

        return [
            'task' => 'SCIENCE-CONTENTPAGE-IMPORTER-DRYRUN-01',
            'mode' => 'dry_run',
            'dry_run' => true,
            'would_write' => false,
            'database_writes_allowed' => false,
            'package_path' => $root,
            'package_status' => (string) ($manifest['status'] ?? 'Unknown'),
            'pages_seen' => count($pages),
            'pages_expected' => count(self::EXPECTED_PAGE_KEYS),
            'pages_ready_for_non_public_draft_import' => count(array_filter(
                $pages,
                static fn (array $page): bool => ($page['draft_import_decision'] ?? '') === 'draft_import_ready'
            )),
            'pages_reconciled_existing_authority' => count($reconciledAuthorityPages),
            'pages_blocked' => count($blockingPages),
            'issue_count' => count($issues),
            'status' => $issues === [] ? 'pass_no_write_dry_run' : 'blocked_no_write_dry_run',
            'pages' => $pages,
            'issues' => $issues,
            'non_runtime_guarantees' => [
                'cms_mutation_performed' => false,
                'content_import_performed' => false,
                'publish_performed' => false,
                'sitemap_changed' => false,
                'llms_changed' => false,
                'footer_changed' => false,
                'private_url_accessed' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifestPage
     * @return array<string, mixed>
     */
    private function validatePage(string $root, array $manifestPage, int $index): array
    {
        $issues = [];
        $pageKey = (string) ($manifestPage['page_key'] ?? 'Unknown');
        $file = (string) ($manifestPage['file'] ?? '');
        $filePath = $file !== '' ? $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file) : '';
        $frontmatter = [];
        $body = '';

        if ($file === '') {
            $issues[] = $this->issue($pageKey, 'missing_file', 'manifest page file is required.');
        } elseif (! is_file($filePath)) {
            $issues[] = $this->issue($pageKey, 'page_file_not_found', 'page file does not exist: '.$file);
        } else {
            [$frontmatter, $body] = $this->readFrontmatter($filePath);
        }

        foreach (['page_key', 'proposed_slug', 'page_type', 'kind', 'review_state'] as $requiredField) {
            if (! array_key_exists($requiredField, $manifestPage)) {
                $issues[] = $this->issue($pageKey, 'missing_manifest_'.$requiredField, $requiredField.' is required in manifest.');
            }
            if ($frontmatter !== [] && ! array_key_exists($requiredField, $frontmatter)) {
                $issues[] = $this->issue($pageKey, 'missing_frontmatter_'.$requiredField, $requiredField.' is required in page frontmatter.');
            }
        }

        foreach (['page_key', 'proposed_slug', 'page_type', 'review_state', 'science_review_required', 'legal_review_required'] as $field) {
            if (array_key_exists($field, $manifestPage) && array_key_exists($field, $frontmatter)
                && $manifestPage[$field] !== $frontmatter[$field]) {
                $issues[] = $this->issue($pageKey, 'manifest_frontmatter_mismatch_'.$field, $field.' differs between manifest and page frontmatter.');
            }
        }

        foreach (self::DRAFT_FALSE_FIELDS as $field) {
            if (($manifestPage[$field] ?? false) !== false && ($frontmatter[$field] ?? false) !== false) {
                $issues[] = $this->issue($pageKey, 'unsafe_'.$field, $field.' must remain false for dry-run import.');
            }
        }

        $pageType = (string) ($manifestPage['page_type'] ?? '');
        if (! in_array($pageType, ContentPage::PAGE_TYPES, true)) {
            $issues[] = $this->issue($pageKey, 'unsupported_page_type', 'Unsupported ContentPage page_type '.$pageType.'.');
        }

        $reviewState = (string) ($manifestPage['review_state'] ?? '');
        if (! in_array($reviewState, ContentPage::REVIEW_STATES, true)) {
            $issues[] = $this->issue($pageKey, 'unsupported_review_state', 'Unsupported ContentPage review_state '.$reviewState.'.');
        }

        $proposedPath = $this->normalizePath((string) ($manifestPage['proposed_slug'] ?? ''));
        $fallbackPath = $this->normalizePath((string) ($manifestPage['fallback_slug'] ?? ($frontmatter['fallback_slug_if_nested_route_not_supported'] ?? '')));
        $canonicalPath = $this->chooseCanonicalPath($pageKey, $proposedPath, $fallbackPath);
        if ($canonicalPath === '') {
            $issues[] = $this->issue($pageKey, 'missing_canonical_path', 'A public canonical candidate path is required.');
        }

        if ($this->hasPrivateRouteReference($body) || $this->hasPrivateRouteReference(json_encode($manifestPage, JSON_UNESCAPED_SLASHES) ?: '')) {
            $issues[] = $this->issue($pageKey, 'private_route_pattern_present', 'Private route patterns may only appear as blocked metadata, not body/internal links.');
        }

        $metadataOnlyFields = [];
        foreach (['sitemap_eligible', 'llms_eligible', 'footer_eligible', 'visible_faq_items', 'claim_boundary_notes', 'reviewer_checklist', 'publish_blockers', 'unknown_fields'] as $field) {
            if (str_contains($body, $field.':') || array_key_exists($field, $manifestPage) || array_key_exists($field, $frontmatter)) {
                $metadataOnlyFields[] = $field;
            }
        }

        $normalized = [
            'org_id' => 0,
            'slug' => ltrim($canonicalPath, '/'),
            'path' => $canonicalPath,
            'canonical_path' => $canonicalPath,
            'kind' => ContentPage::KIND_POLICY,
            'source_kind' => (string) ($manifestPage['kind'] ?? 'Unknown'),
            'page_type' => $pageType,
            'locale' => 'zh-CN',
            'title_source_field' => 'zh_title',
            'template' => 'policy',
            'animation_profile' => 'editorial',
            'status' => ContentPage::STATUS_DRAFT,
            'review_state' => $reviewState,
            'science_review_required' => (bool) ($manifestPage['science_review_required'] ?? false),
            'legal_review_required' => (bool) ($manifestPage['legal_review_required'] ?? false),
            'is_public' => false,
            'is_indexable' => false,
            'seo_title_source_field' => 'meta_title_draft',
            'meta_description_source_field' => 'meta_description_draft',
            'content_md_source' => 'page_markdown_body',
            'metadata_only_not_content_page_fields' => array_values(array_unique($metadataOnlyFields)),
        ];

        $action = 'create_non_public_draft';
        $decision = 'draft_import_ready';
        $blocksPackageDryRun = false;
        if ($canonicalPath === '/method-boundaries') {
            $action = 'preserve_existing_authority_revision_only';
            $decision = 'existing_authority_reconciliation_ready';
        }
        if ($issues !== []) {
            $decision = 'blocked_schema_or_package_validation';
            $blocksPackageDryRun = true;
        }

        return [
            'index' => $index,
            'page_key' => $pageKey,
            'file' => $file,
            'draft_import_decision' => $decision,
            'planned_action' => $action,
            'blocks_package_dry_run' => $blocksPackageDryRun,
            'normalized_content_page' => $normalized,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON file: '.$path);
        }

        return $decoded;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function readFrontmatter(string $path): array
    {
        $content = (string) file_get_contents($path);
        if (! preg_match('/\A---\R(?P<yaml>.*?)\R---\R(?P<body>.*)\z/s', $content, $matches)) {
            throw new \RuntimeException('Page file is missing YAML frontmatter: '.$path);
        }

        $frontmatter = Yaml::parse((string) $matches['yaml']);
        if (! is_array($frontmatter)) {
            throw new \RuntimeException('Page frontmatter must parse to an object: '.$path);
        }

        return [$frontmatter, (string) $matches['body']];
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        return '/'.trim($path, '/');
    }

    private function chooseCanonicalPath(string $pageKey, string $proposedPath, string $fallbackPath): string
    {
        if ($pageKey === 'DATA-NOTES-CONTENT-01' && $fallbackPath === '/data-privacy') {
            return $fallbackPath;
        }

        return $proposedPath !== '' ? $proposedPath : $fallbackPath;
    }

    private function hasPrivateRouteReference(string $value): bool
    {
        $bodyWithoutForbiddenList = preg_replace('/forbidden_routes:\s*(?:(?:\r?\n)\s*-\s*[^\r\n]+)+/m', '', $value) ?? $value;

        return preg_match('#/(?:results?/|orders?\b|pay\b|payment\b|checkout\b|share/|history\b)#i', $bodyWithoutForbiddenList) === 1
            || preg_match('/(?:token=|orderNo=|payment_intent=)/i', $bodyWithoutForbiddenList) === 1;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function duplicates(array $values): array
    {
        $counts = array_count_values(array_filter($values, static fn (string $value): bool => $value !== ''));

        return array_values(array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)));
    }

    /**
     * @return array{scope: string, code: string, message: string}
     */
    private function issue(string $scope, string $code, string $message): array
    {
        return [
            'scope' => $scope,
            'code' => $code,
            'message' => $message,
        ];
    }
}
