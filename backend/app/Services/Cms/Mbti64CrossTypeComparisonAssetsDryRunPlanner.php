<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class Mbti64CrossTypeComparisonAssetsDryRunPlanner
{
    private const FORBIDDEN_PUBLIC_ROUTE_PATTERN =
        '~/(?:[a-z]{2}(?:-[A-Z]{2})?/)?(?:result|results|orders|order|share|pay|payment|history|private|account)(?:/|[?#\s)"\']|$)~i';

    private const FORBIDDEN_QUERY_PATTERN =
        '/(?:[?&]|^)(?:token|session|user|result_id|report_id|order_no)=/i';

    /**
     * @return array<string,mixed>
     */
    public function planSourceDir(string $sourceDir): array
    {
        $resolvedSourceDir = $this->resolveSourceDir($sourceDir);
        $files = $this->comparisonFiles($resolvedSourceDir);
        $errors = [];
        $rows = [];

        if ($files === []) {
            $errors[] = $this->issue('source_dir', 'comparison_assets_missing', 'No comparisons/*_CMS_READY.json files were found.');
        }

        foreach ($files as $file) {
            $raw = (string) File::get($file);
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $errors[] = $this->issue($this->relativePath($file), 'invalid_json_object', 'Comparison CMS_READY file must be a JSON object.');

                continue;
            }

            $assetErrors = [];
            $this->validateAsset($decoded, $this->relativePath($file), $assetErrors);

            if ($assetErrors === []) {
                $rows[] = $this->rowPlan($decoded, $file, $raw);
            }

            $errors = array_merge($errors, $assetErrors);
        }

        return [
            'artifact' => 'MBTI64-CROSS-TYPE-COMPARISON-ASSETS-DRY-RUN-01',
            'status' => $errors === [] ? 'pass' : 'fail',
            'ok' => $errors === [],
            'dry_run' => true,
            'write' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'queue_enqueue_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'canonical_hreflang_jsonld_release_attempted' => false,
            'source_dir' => $resolvedSourceDir,
            'assets_found' => count($files),
            'valid_count' => count($rows),
            'errors_count' => count($errors),
            'comparison_count' => count($rows),
            'rows_would_stage' => count($rows),
            'target_contract' => $this->targetContract(),
            'rows' => $rows,
            'errors' => $errors,
            'warnings' => [],
        ];
    }

    private function resolveSourceDir(string $sourceDir): string
    {
        $sourceDir = trim($sourceDir);
        if ($sourceDir === '') {
            throw new RuntimeException('--source-dir is required.');
        }

        $resolved = str_starts_with($sourceDir, '/')
            ? $sourceDir
            : base_path($sourceDir);

        if (! File::isDirectory($resolved)) {
            throw new RuntimeException('Source directory not found: '.$resolved);
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function comparisonFiles(string $sourceDir): array
    {
        $files = glob($sourceDir.'/comparisons/*_CMS_READY.json') ?: [];
        sort($files);

        return array_values(array_map('strval', $files));
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  list<array<string,string>>  $errors
     */
    private function validateAsset(array $asset, string $path, array &$errors): void
    {
        $slug = $this->stringValue($asset['slug'] ?? null);
        $leftType = strtoupper((string) ($this->stringValue($asset['left_type'] ?? null) ?? ''));
        $rightType = strtoupper((string) ($this->stringValue($asset['right_type'] ?? null) ?? ''));

        if ($slug === null || preg_match('/^([a-z]{4})-vs-([a-z]{4})$/', $slug, $matches) !== 1 || $matches[1] === $matches[2]) {
            $errors[] = $this->issue($path.'.slug', 'invalid_mbti_cross_type_slug', 'Slug must match {type}-vs-{different_type}.');
        } elseif ($leftType !== strtoupper($matches[1]) || $rightType !== strtoupper($matches[2])) {
            $errors[] = $this->issue($path.'.left_type', 'type_fields_must_match_slug', 'left_type and right_type must match the slug.');
        }

        if ($this->stringValue($asset['comparison_type'] ?? null) !== 'mbti_cross_type') {
            $errors[] = $this->issue($path.'.comparison_type', 'comparison_type_must_be_mbti_cross_type', 'comparison_type must be mbti_cross_type.');
        }

        if ($this->stringValue($asset['locale'] ?? null) !== 'zh-CN') {
            $errors[] = $this->issue($path.'.locale', 'locale_must_be_zh_cn', 'Locale must be zh-CN.');
        }

        foreach (['title', 'seo_title', 'seo_description', 'summary'] as $field) {
            if ($this->stringValue($asset[$field] ?? null) === null) {
                $errors[] = $this->issue($path.'.'.$field, 'required_string_missing', $field.' is required.');
            }
        }

        if ($this->stringValue($asset['review_status'] ?? null) !== 'draft') {
            $errors[] = $this->issue($path.'.review_status', 'review_status_must_be_draft', 'Review status must remain draft.');
        }

        if ($this->stringValue($asset['publish_status'] ?? null) !== 'draft') {
            $errors[] = $this->issue($path.'.publish_status', 'publish_status_must_be_draft', 'Publish status must remain draft.');
        }

        if ($this->stringValue($asset['indexability_status'] ?? null) === 'indexable') {
            $errors[] = $this->issue($path.'.indexability_status', 'indexability_must_not_be_indexable', 'Draft comparison assets must not request indexability.');
        }

        $sections = is_array($asset['sections'] ?? null) ? array_values((array) $asset['sections']) : [];
        if (count($sections) < 5) {
            $errors[] = $this->issue($path.'.sections', 'sections_under_minimum', 'Expected at least five comparison sections.');
        }

        $faq = is_array($asset['faq'] ?? null) ? array_values((array) $asset['faq']) : [];
        if (count($faq) < 4) {
            $errors[] = $this->issue($path.'.faq', 'faq_under_minimum', 'Expected at least four FAQ entries.');
        }

        $internalLinks = is_array($asset['internal_links'] ?? null) ? array_values((array) $asset['internal_links']) : [];
        if (count($internalLinks) < 3) {
            $errors[] = $this->issue($path.'.internal_links', 'internal_links_under_minimum', 'Expected at least three safe internal links.');
        }

        if ($this->stringValue($asset['claim_boundary'] ?? null) === null) {
            $errors[] = $this->issue($path.'.claim_boundary', 'claim_boundary_missing', 'Claim boundary is required.');
        }

        if (! is_array($asset['source_notes'] ?? null)) {
            $errors[] = $this->issue($path.'.source_notes', 'source_notes_missing', 'Source notes are required.');
        }

        $this->validateForbiddenRoutes($asset, $path, $errors);
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  list<array<string,string>>  $errors
     */
    private function validateForbiddenRoutes(array $asset, string $path, array &$errors): void
    {
        $json = json_encode($asset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            $errors[] = $this->issue($path, 'json_encode_failed', 'Asset could not be normalized for route safety scanning.');

            return;
        }

        if (preg_match(self::FORBIDDEN_PUBLIC_ROUTE_PATTERN, $json) === 1) {
            $errors[] = $this->issue($path, 'forbidden_public_route_pattern_present', 'Active payload must not contain result/order/share/payment/history/private/account routes.');
        }

        if (preg_match(self::FORBIDDEN_QUERY_PATTERN, $json) === 1) {
            $errors[] = $this->issue($path, 'forbidden_query_pattern_present', 'Active payload must not contain sensitive query keys.');
        }
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return array<string,mixed>
     */
    private function rowPlan(array $asset, string $file, string $raw): array
    {
        return [
            'slug' => (string) $asset['slug'],
            'left_type' => strtoupper((string) $asset['left_type']),
            'right_type' => strtoupper((string) $asset['right_type']),
            'locale' => 'zh-CN',
            'source_file' => $this->relativePath($file),
            'source_sha256' => hash('sha256', $raw),
            'target' => [
                'storage' => 'future_backend_authority.mbti64_cross_type_comparison',
                'write_mode' => 'draft_package_plan_only',
                'public_api_enabled' => false,
            ],
            'draft_state_after_import' => [
                'review_status' => 'draft',
                'publish_status' => 'draft',
                'is_public' => false,
                'is_indexable' => false,
                'sitemap_eligible' => false,
                'llms_eligible' => false,
                'search_submission_eligible' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function targetContract(): array
    {
        return [
            'storage' => 'future_backend_authority.mbti64_cross_type_comparison',
            'overlay_contract' => [
                'slug',
                'comparison_type',
                'locale',
                'left_type',
                'right_type',
                'seo',
                'sections',
                'faq',
                'internal_links',
                'governance',
            ],
            'public_runtime_enabled' => false,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
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
}
