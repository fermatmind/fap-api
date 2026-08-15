<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use RuntimeException;

final class CareerCurrentAuthorityPackageFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerCurrentAuthorityPackage
{
    public const CONTRACT_VERSION = 'career.current_authority_manifest.v1';

    public const RELATIVE_PATH = 'content_assets/career/current';

    public const EXPECTED_CAREERS = 1046;

    public const EXPECTED_LOCALE_PAGES = 2092;

    public const ASSETS_SHA256 = 'e901ed5a9321244a7884ccbddcf1eb32d170393048e1d021bfda719ca8de59db';

    public const SURFACE_VERSION = 'display.surface.v1';

    public const ASSET_VERSION = 'v4.2';

    public const ASSET_TYPE = 'career_job_public_display';

    public const ASSET_ROLE = 'formal_pilot_master';

    public const READY_STATUS = 'ready_for_pilot';

    public const LOCALES = ['en', 'zh-CN'];

    private const EXPORTED_FIELDS = [
        'surface_version',
        'asset_version',
        'template_version',
        'asset_type',
        'asset_role',
        'status',
        'component_order_json',
        'page_payload_json',
        'seo_payload_json',
        'sources_json',
        'structured_data_json',
        'implementation_contract_json',
        'metadata_json',
    ];

    private const ROW_KEYS = [
        'asset_role',
        'asset_type',
        'asset_version',
        'canonical_slug',
        'component_order_json',
        'implementation_contract_json',
        'metadata_json',
        'page_payload_json',
        'seo_payload_json',
        'sources_json',
        'status',
        'structured_data_json',
        'surface_version',
        'template_version',
    ];

    private const DISPLAY_OWNED_PUBLIC_FIELDS = [
        'surface_version',
        'asset_version',
        'template_version',
        'asset_type',
        'asset_role',
        'status',
        'available_locales',
        'page',
        'component_order',
        'sources',
        'structured_data_from_visible_content',
        'implementation_contract',
    ];

    private const FORBIDDEN_PUBLIC_KEYS = [
        'release_gate',
        'release_gates',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
        'truth_metric_id',
        'trust_manifest_id',
        'index_state_id',
        'compile_run_id',
        'import_run_id',
        'source_trace_id',
        'metadata_fingerprint',
        'fingerprint_seed',
        'compile_refs',
        'provenance_meta',
        'lineage_id',
        'lineage_json',
    ];

    /**
     * @return array{manifest: array<string,mixed>, rows: array<string,array<string,mixed>>, slugs: list<string>, summary: array<string,mixed>}
     */
    public function load(string $backendRoot): array
    {
        $root = rtrim($backendRoot, '/').'/'.self::RELATIVE_PATH;
        $assetsPath = $root.'/assets.jsonl';
        $manifestPath = $root.'/manifest.json';
        if (! is_file($assetsPath) || ! is_file($manifestPath) || is_link($assetsPath) || is_link($manifestPath)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_PACKAGE_FILE_MISSING');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }
        $assetsSha256 = hash_file('sha256', $assetsPath);
        if (! is_string($assetsSha256) || ! hash_equals(self::ASSETS_SHA256, $assetsSha256)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSETS_HASH_MISMATCH');
        }
        $this->assertManifest($manifest, $assetsSha256);

        $rows = [];
        $orderedRows = [];
        $previousSlug = null;
        $localePageCount = 0;
        $numericRatingResidueCount = 0;
        $handle = fopen($assetsPath, 'rb');
        if ($handle === false) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSETS_UNREADABLE');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $canonicalLine = rtrim($line, "\r\n");
                if ($canonicalLine === '') {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_BLANK_LINE');
                }
                $row = json_decode($canonicalLine, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($row) || self::encodeCanonical($row) !== $canonicalLine) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_NOT_CANONICAL');
                }
                $keys = array_keys($row);
                sort($keys, SORT_STRING);
                if ($keys !== self::ROW_KEYS) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_FIELD_SET_INVALID');
                }
                $slug = strtolower(trim((string) ($row['canonical_slug'] ?? '')));
                if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                    || isset($rows[$slug])
                    || ($previousSlug !== null && strcmp($previousSlug, $slug) >= 0)) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_SLUG_SET_INVALID');
                }
                $this->assertRow($row, $numericRatingResidueCount);
                $rows[$slug] = $row;
                $orderedRows[] = $row;
                $previousSlug = $slug;
                $localePageCount += 2;
            }
        } finally {
            fclose($handle);
        }

        if (count($rows) !== self::EXPECTED_CAREERS
            || $localePageCount !== self::EXPECTED_LOCALE_PAGES
            || $numericRatingResidueCount !== 0) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_COUNT_MISMATCH');
        }

        $slugs = array_keys($rows);
        $fieldHashes = [];
        foreach (self::EXPORTED_FIELDS as $field) {
            $fieldHashes[$field] = self::hashValue(array_map(
                static fn (array $row): array => [
                    'canonical_slug' => $row['canonical_slug'],
                    'value' => $row[$field] ?? null,
                ],
                $orderedRows,
            ));
        }
        $publicFieldValues = array_fill_keys(self::DISPLAY_OWNED_PUBLIC_FIELDS, []);
        foreach ($rows as $slug => $row) {
            foreach (self::LOCALES as $locale) {
                $projection = $this->publicProjection($row, $locale);
                foreach (self::DISPLAY_OWNED_PUBLIC_FIELDS as $field) {
                    $publicFieldValues[$field][] = [
                        'canonical_slug' => $slug,
                        'locale' => $locale,
                        'value' => $projection[$field],
                    ];
                }
            }
        }
        $publicFieldHashes = array_map(self::hashValue(...), $publicFieldValues);
        if ($fieldHashes != ($manifest['exported_field_set_sha256'] ?? null)
            || $publicFieldHashes != ($manifest['public_projection_field_set_sha256'] ?? null)
            || ! hash_equals((string) self::value($manifest, 'set_hashes.slug_set_sha256'), self::hashValue($slugs))
            || ! hash_equals((string) self::value($manifest, 'set_hashes.full_asset_set_sha256'), self::hashValue($orderedRows))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_SET_HASH_MISMATCH');
        }

        return [
            'manifest' => $manifest,
            'rows' => $rows,
            'slugs' => $slugs,
            'summary' => [
                'assets_sha256' => $assetsSha256,
                'manifest_sha256' => hash_file('sha256', $manifestPath),
                'career_count' => count($rows),
                'locale_page_count' => $localePageCount,
                'components_per_page' => count(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER),
                'numeric_rating_statement_residue_count' => $numericRatingResidueCount,
                'slug_set_sha256' => self::hashValue($slugs),
                'full_asset_set_sha256' => self::hashValue($orderedRows),
            ],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifest(array $manifest, string $assetsSha256): void
    {
        if (($manifest['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($manifest['authority_path'] ?? null) !== 'backend/content_assets/career/current'
            || self::value($manifest, 'counts.careers') !== self::EXPECTED_CAREERS
            || self::value($manifest, 'counts.locale_pages') !== self::EXPECTED_LOCALE_PAGES
            || self::value($manifest, 'counts.public_projection_locale_pages') !== self::EXPECTED_LOCALE_PAGES
            || self::value($manifest, 'counts.components_per_page') !== count(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER)
            || self::value($manifest, 'counts.numeric_rating_statement_residue_count') !== 0
            || self::value($manifest, 'files.0.path') !== 'assets.jsonl'
            || self::value($manifest, 'files.0.row_count') !== self::EXPECTED_CAREERS
            || self::value($manifest, 'files.0.sha256') !== $assetsSha256
            || self::value($manifest, 'structural_contract.surface_version') !== self::SURFACE_VERSION
            || self::value($manifest, 'structural_contract.asset_version') !== self::ASSET_VERSION
            || self::value($manifest, 'structural_contract.template_version') !== self::ASSET_VERSION
            || self::value($manifest, 'structural_contract.asset_type') !== self::ASSET_TYPE
            || self::value($manifest, 'structural_contract.status') !== self::READY_STATUS
            || self::value($manifest, 'structural_contract.component_order') !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER
            || self::value($manifest, 'export_evidence.artifact_id') !== 9248668854
            || self::value($manifest, 'export_evidence.artifact_digest') !== 'sha256:2cfb298b90a1c8443254686d659e8bbf78c918e0fbb29960ba007603384223bc'
            || self::value($manifest, 'export_evidence.exporter_result') !== 'pass'
            || self::value($manifest, 'export_evidence.workflow_conclusion') !== 'failure'
            || self::value($manifest, 'superseded_source_coverage.workbuddy_block_count') !== 4184
            || self::value($manifest, 'superseded_source_coverage.workbuddy_block_mismatch_count') !== 0
            || self::value($manifest, 'superseded_source_coverage.missing_12_original_component_count') !== 576
            || self::value($manifest, 'superseded_source_coverage.missing_12_component_mismatch_count') !== 0
            || count((array) ($manifest['exported_field_set_sha256'] ?? [])) !== count(self::EXPORTED_FIELDS)
            || count((array) ($manifest['public_projection_field_set_sha256'] ?? [])) !== count(self::DISPLAY_OWNED_PUBLIC_FIELDS)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }
        foreach ((array) ($manifest['superseded_sources'] ?? []) as $sha256) {
            if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SUPERSEDED_SOURCE_HASH_INVALID');
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function assertRow(array $row, int &$numericRatingResidueCount): void
    {
        if (($row['surface_version'] ?? null) !== self::SURFACE_VERSION
            || ($row['asset_version'] ?? null) !== self::ASSET_VERSION
            || ($row['template_version'] ?? null) !== self::ASSET_VERSION
            || ($row['asset_type'] ?? null) !== self::ASSET_TYPE
            || ($row['asset_role'] ?? null) !== self::ASSET_ROLE
            || ($row['status'] ?? null) !== self::READY_STATUS
            || array_values((array) ($row['component_order_json'] ?? [])) !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER
            || ! CareerDisplayAssetComponentContract::hasExactCurrentPages((array) ($row['page_payload_json'] ?? []))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_STRUCTURE_INVALID');
        }
        $pages = self::localizedPages($row);
        foreach (['en', 'zh'] as $locale) {
            if (preg_match(
                '/(?<![0-9])(10|[0-9])(?:\.0)?\s*\/\s*10(?![0-9])/u',
                self::encodeCanonical($pages[$locale]['career_ai_description_block'] ?? null),
            ) === 1) {
                $numericRatingResidueCount++;
            }
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function databaseAttributes(array $row): array
    {
        return array_intersect_key($row, array_flip(self::EXPORTED_FIELDS));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function publicProjection(array $row, string $locale): array
    {
        $normalizedLocale = $locale === 'en' ? 'en' : 'zh';
        $pages = self::localizedPages($row);
        $page = $pages[$normalizedLocale] ?? null;
        if (! is_array($page)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_LOCALIZED_PAGE_MISSING');
        }

        return [
            'surface_version' => $row['surface_version'],
            'asset_version' => $row['asset_version'],
            'template_version' => $row['template_version'],
            'asset_type' => $row['asset_type'],
            'asset_role' => $row['asset_role'],
            'status' => $row['status'],
            'available_locales' => ['en', 'zh-CN'],
            'page' => [
                'locale' => $normalizedLocale === 'en' ? 'en' : 'zh-CN',
                'content' => $this->stripForbiddenKeys($this->localizeInternalHrefs($page, $normalizedLocale)),
            ],
            'component_order' => $row['component_order_json'],
            'sources' => $this->stripForbiddenKeys((array) $row['sources_json']),
            'structured_data_from_visible_content' => $this->stripForbiddenKeys((array) $row['structured_data_json']),
            'implementation_contract' => $this->stripForbiddenKeys((array) $row['implementation_contract_json']),
        ];
    }

    /** @param array<string,mixed> $surface @return array<string,mixed> */
    public function displayOwnedProjection(array $surface): array
    {
        $owned = [];
        foreach (self::DISPLAY_OWNED_PUBLIC_FIELDS as $field) {
            if (! array_key_exists($field, $surface)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_PUBLIC_PROJECTION_INVALID');
            }
            $owned[$field] = $surface[$field];
        }

        return $owned;
    }

    /** @param array<string,mixed> $row @return array<string,array<string,mixed>> */
    private static function localizedPages(array $row): array
    {
        $payload = (array) ($row['page_payload_json'] ?? []);
        $pages = is_array($payload['page'] ?? null) ? $payload['page'] : $payload;

        return [
            'en' => (array) ($pages['en'] ?? []),
            'zh' => (array) ($pages['zh'] ?? []),
        ];
    }

    /** @param array<string,mixed> $page @return array<string,mixed> */
    private function localizeInternalHrefs(array $page, string $locale): array
    {
        $expectedPrefix = $locale === 'en' ? '/en/' : '/zh/';
        $otherPrefix = $locale === 'en' ? '/zh/' : '/en/';
        array_walk_recursive($page, static function (&$value, $key) use ($expectedPrefix, $otherPrefix): void {
            if ($key !== 'href' || ! is_string($value) || trim($value) === '') {
                return;
            }
            foreach (preg_split('/\s*\|\s*/', trim($value)) ?: [] as $candidate) {
                if (str_starts_with($candidate, $expectedPrefix)) {
                    $value = $candidate;

                    return;
                }
            }
            if (str_starts_with($value, $otherPrefix)) {
                $value = $expectedPrefix.substr($value, strlen($otherPrefix));
            }
        });

        return $page;
    }

    private function stripForbiddenKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach (self::FORBIDDEN_PUBLIC_KEYS as $key) {
            unset($value[$key]);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->stripForbiddenKeys($item);
        }

        return $value;
    }

    public static function hashValue(mixed $value): string
    {
        return hash('sha256', self::encodeCanonical($value));
    }

    public static function encodeCanonical(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }

    /** @param array<string,mixed> $value */
    private static function value(array $value, string $path): mixed
    {
        $current = $value;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
