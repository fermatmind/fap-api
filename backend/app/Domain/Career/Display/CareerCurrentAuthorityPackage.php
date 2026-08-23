<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use App\Services\Career\Review\CareerJobDetailReaderSafeReviewProjector;
use JsonException;
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

    public const SURFACE_VERSION = 'display.surface.v1';

    public const ASSET_VERSION = 'v4.2';

    public const ASSET_TYPE = 'career_job_public_display';

    public const ASSET_ROLE = 'formal_pilot_master';

    public const READY_STATUS = 'ready_for_pilot';

    public const LOCALES = ['en', 'zh-CN'];

    private const MANUAL_HOLD_SLUG = 'software-developers';

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

    private const OPTIONAL_DISPLAY_OWNED_PUBLIC_FIELDS = [
        'presentation_v1',
        'supporting_evidence_v1',
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

    private CareerJobDetailReaderSafeReviewProjector $readerSafeProjector;

    public function __construct(?CareerJobDetailReaderSafeReviewProjector $readerSafeProjector = null)
    {
        $this->readerSafeProjector = $readerSafeProjector ?? new CareerJobDetailReaderSafeReviewProjector;
    }

    /**
     * @return array{manifest: array<string,mixed>, rows: array<string,array<string,mixed>>, slugs: list<string>, summary: array<string,mixed>}
     */
    public function load(string $backendRoot): array
    {
        [$assetsPath, $manifestPath] = $this->packagePaths($backendRoot);
        $manifest = $this->readManifest($manifestPath);
        $this->assertManifestContract($manifest);
        $this->assertPresentationSourceRegistry($backendRoot, $manifest);
        $this->assertSupportingEvidenceRegistry($backendRoot, $manifest);
        $compiled = $this->compileAssets($assetsPath);
        $assetsSha256 = $compiled['summary']['assets_sha256'];
        $declaredAssetsSha256 = (string) self::value($manifest, 'files.0.sha256');
        if (preg_match('/\A[0-9a-f]{64}\z/', $declaredAssetsSha256) !== 1
            || ! hash_equals($declaredAssetsSha256, $assetsSha256)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSETS_HASH_MISMATCH');
        }
        $expectedManifest = $this->withComputedManifestFields($manifest, $compiled);
        if (! hash_equals(self::hashValue($expectedManifest), self::hashValue($manifest))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_SET_HASH_MISMATCH');
        }

        $compiled['manifest'] = $manifest;
        $compiled['summary']['manifest_sha256'] = hash_file('sha256', $manifestPath);
        unset($compiled['computed_manifest_fields']);

        return $compiled;
    }

    /** @return array{manifest: array<string,mixed>, summary: array<string,mixed>} */
    public function expectedManifest(string $backendRoot): array
    {
        [$assetsPath, $manifestPath] = $this->packagePaths($backendRoot);
        $manifest = $this->readManifest($manifestPath);
        $this->assertManifestContract($manifest);
        $compiled = $this->compileAssets($assetsPath);

        return [
            'manifest' => $this->withComputedManifestFields($manifest, $compiled),
            'summary' => $compiled['summary'],
        ];
    }

    public static function declaredAssetsSha256(string $backendRoot): string
    {
        $manifestPath = rtrim($backendRoot, '/').'/'.self::RELATIVE_PATH.'/manifest.json';
        if (! is_file($manifestPath) || is_link($manifestPath)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_PACKAGE_FILE_MISSING');
        }
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }
        $sha256 = is_array($manifest) ? self::value($manifest, 'files.0.sha256') : null;
        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }

        return $sha256;
    }

    /**
     * @return array{
     *   manifest?: array<string,mixed>,
     *   rows: array<string,array<string,mixed>>,
     *   slugs: list<string>,
     *   summary: array<string,mixed>,
     *   computed_manifest_fields: array<string,mixed>
     * }
     */
    private function compileAssets(string $assetsPath): array
    {
        $rows = [];
        $previousSlug = null;
        $localePageCount = 0;
        $numericRatingResidueCount = 0;
        $fieldHashContexts = [];
        foreach (self::EXPORTED_FIELDS as $field) {
            $fieldHashContexts[$field] = hash_init('sha256');
            hash_update($fieldHashContexts[$field], '[');
        }
        $publicFieldHashContexts = [];
        foreach (self::DISPLAY_OWNED_PUBLIC_FIELDS as $field) {
            $publicFieldHashContexts[$field] = hash_init('sha256');
            hash_update($publicFieldHashContexts[$field], '[');
        }
        $optionalPublicFieldHashContexts = [];
        foreach (self::OPTIONAL_DISPLAY_OWNED_PUBLIC_FIELDS as $field) {
            $optionalPublicFieldHashContexts[$field] = hash_init('sha256');
            hash_update($optionalPublicFieldHashContexts[$field], '[');
        }
        $optionalPublicFieldsPresent = [];
        $fullAssetSetHashContext = hash_init('sha256');
        hash_update($fullAssetSetHashContext, '[');
        $rowIndex = 0;
        $publicProjectionIndex = 0;
        $publicContentHashes = [];
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
                $rowSeparator = $rowIndex === 0 ? '' : ',';
                hash_update($fullAssetSetHashContext, $rowSeparator.self::encodeCanonical($row));
                foreach (self::EXPORTED_FIELDS as $field) {
                    hash_update($fieldHashContexts[$field], $rowSeparator.self::encodeCanonical([
                        'canonical_slug' => $slug,
                        'value' => $row[$field] ?? null,
                    ]));
                }
                foreach (self::LOCALES as $locale) {
                    $projection = $this->publicProjection($row, $locale);
                    $projectionSeparator = $publicProjectionIndex === 0 ? '' : ',';
                    foreach (self::DISPLAY_OWNED_PUBLIC_FIELDS as $field) {
                        hash_update($publicFieldHashContexts[$field], $projectionSeparator.self::encodeCanonical([
                            'canonical_slug' => $slug,
                            'locale' => $locale,
                            'value' => $projection[$field] ?? null,
                        ]));
                    }
                    foreach (self::OPTIONAL_DISPLAY_OWNED_PUBLIC_FIELDS as $field) {
                        hash_update($optionalPublicFieldHashContexts[$field], $projectionSeparator.self::encodeCanonical([
                            'canonical_slug' => $slug,
                            'locale' => $locale,
                            'value' => $projection[$field] ?? null,
                        ]));
                        if (array_key_exists($field, $projection)) {
                            $optionalPublicFieldsPresent[$field] = true;
                        }
                    }
                    $publicContentHashes[] = $this->publicContentHash($row, $locale);
                    $publicProjectionIndex++;
                }
                $rowIndex++;
                $previousSlug = $slug;
                $localePageCount += 2;
            }
        } finally {
            fclose($handle);
        }

        if (isset($rows[self::MANUAL_HOLD_SLUG])) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANUAL_HOLD_INCLUDED');
        }

        if (count($rows) !== self::EXPECTED_CAREERS
            || $localePageCount !== self::EXPECTED_LOCALE_PAGES
            || $numericRatingResidueCount !== 0) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSET_COUNT_MISMATCH');
        }

        $slugs = array_keys($rows);
        $fieldHashes = [];
        foreach ($fieldHashContexts as $field => $context) {
            hash_update($context, ']');
            $fieldHashes[$field] = hash_final($context);
        }
        $publicFieldHashes = [];
        foreach ($publicFieldHashContexts as $field => $context) {
            hash_update($context, ']');
            $publicFieldHashes[$field] = hash_final($context);
        }
        foreach ($optionalPublicFieldHashContexts as $field => $context) {
            hash_update($context, ']');
            $digest = hash_final($context);
            if (isset($optionalPublicFieldsPresent[$field])) {
                $publicFieldHashes[$field] = $digest;
            }
        }
        hash_update($fullAssetSetHashContext, ']');
        $fullAssetSetSha256 = hash_final($fullAssetSetHashContext);
        sort($publicContentHashes, SORT_STRING);
        $assetsSha256 = hash_file('sha256', $assetsPath);
        if (! is_string($assetsSha256)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_ASSETS_UNREADABLE');
        }

        return [
            'rows' => $rows,
            'slugs' => $slugs,
            'summary' => [
                'assets_sha256' => $assetsSha256,
                'career_count' => count($rows),
                'locale_page_count' => $localePageCount,
                'components_per_page' => count(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER),
                'numeric_rating_statement_residue_count' => $numericRatingResidueCount,
                'slug_set_sha256' => self::hashValue($slugs),
                'full_asset_set_sha256' => $fullAssetSetSha256,
            ],
            'computed_manifest_fields' => [
                'counts' => [
                    'careers' => count($rows),
                    'components_per_page' => count(CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER),
                    'locale_pages' => $localePageCount,
                    'manual_hold_locale_pages' => count(self::LOCALES),
                    'numeric_rating_statement_residue_count' => $numericRatingResidueCount,
                    'public_projection_locale_pages' => $localePageCount,
                ],
                'files' => [[
                    'path' => 'assets.jsonl',
                    'row_count' => count($rows),
                    'sha256' => $assetsSha256,
                ]],
                'exported_field_set_sha256' => $fieldHashes,
                'public_projection_field_set_sha256' => $publicFieldHashes,
                'set_hashes' => [
                    'full_asset_set_sha256' => $fullAssetSetSha256,
                    'public_content_aggregate_sha256' => self::hashValue($publicContentHashes),
                    'slug_set_sha256' => self::hashValue($slugs),
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $manifest */
    private function assertManifestContract(array $manifest): void
    {
        if (($manifest['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ($manifest['authority_path'] ?? null) !== 'backend/content_assets/career/current'
            || self::value($manifest, 'delivery_evidence.initial_governance_full_scan_required') !== true
            || self::value($manifest, 'delivery_evidence.required_ci_fix') !== 'career_search_entry_tier_context_wiring'
            || self::value($manifest, 'delivery_evidence.required_publish_fix') !== 'runner_autoload_order'
            || self::value($manifest, 'files.0.path') !== 'assets.jsonl'
            || self::value($manifest, 'structural_contract.surface_version') !== self::SURFACE_VERSION
            || self::value($manifest, 'structural_contract.asset_version') !== self::ASSET_VERSION
            || self::value($manifest, 'structural_contract.template_version') !== self::ASSET_VERSION
            || self::value($manifest, 'structural_contract.asset_type') !== self::ASSET_TYPE
            || self::value($manifest, 'structural_contract.status') !== self::READY_STATUS
            || self::value($manifest, 'structural_contract.component_order') !== CareerDisplayAssetComponentContract::CURRENT_V4_2_ORDER
            || self::value($manifest, 'structural_contract.public_projection_excluded_manual_hold_slugs') !== [self::MANUAL_HOLD_SLUG]
            || self::value($manifest, 'export_evidence.artifact_id') !== 9248668854
            || self::value($manifest, 'export_evidence.artifact_digest') !== 'sha256:2cfb298b90a1c8443254686d659e8bbf78c918e0fbb29960ba007603384223bc'
            || self::value($manifest, 'export_evidence.exporter_result') !== 'pass'
            || self::value($manifest, 'export_evidence.workflow_conclusion') !== 'failure'
            || self::value($manifest, 'superseded_source_coverage.workbuddy_block_count') !== 4184
            || self::value($manifest, 'superseded_source_coverage.workbuddy_block_mismatch_count') !== 0
            || self::value($manifest, 'superseded_source_coverage.missing_12_original_component_count') !== 576
            || self::value($manifest, 'superseded_source_coverage.missing_12_component_mismatch_count') !== 0) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }
        foreach ((array) ($manifest['superseded_sources'] ?? []) as $sha256) {
            if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/', $sha256) !== 1) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SUPERSEDED_SOURCE_HASH_INVALID');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $compiled
     * @return array<string,mixed>
     */
    private function withComputedManifestFields(array $manifest, array $compiled): array
    {
        foreach ($compiled['computed_manifest_fields'] as $key => $value) {
            $manifest[$key] = $value;
        }

        return $manifest;
    }

    /** @return array{0:string,1:string} */
    private function packagePaths(string $backendRoot): array
    {
        $root = rtrim($backendRoot, '/').'/'.self::RELATIVE_PATH;
        $assetsPath = $root.'/assets.jsonl';
        $manifestPath = $root.'/manifest.json';
        if (! is_file($assetsPath) || ! is_file($manifestPath) || is_link($assetsPath) || is_link($manifestPath)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_PACKAGE_FILE_MISSING');
        }

        return [$assetsPath, $manifestPath];
    }

    /** @return array<string,mixed> */
    private function readManifest(string $manifestPath): array
    {
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }
        if (! is_array($manifest)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }

        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function assertPresentationSourceRegistry(string $backendRoot, array $manifest): void
    {
        $declared = self::value($manifest, 'presentation_v1.source_registry');
        if ($declared === null) {
            return;
        }
        $path = rtrim($backendRoot, '/').'/'.self::RELATIVE_PATH.'/presentation-source-registry.json';
        if (! is_array($declared)
            || ($declared['path'] ?? null) !== 'presentation-source-registry.json'
            || ($declared['onet_multiple_occupation_count'] ?? null) !== 2
            || ($declared['bls_projection_count'] ?? null) !== 5
            || ! is_string($declared['sha256'] ?? null)
            || ! is_file($path)
            || is_link($path)
            || ! hash_equals((string) $declared['sha256'], (string) hash_file('sha256', $path))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_PRESENTATION_SOURCE_REGISTRY_INVALID');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertSupportingEvidenceRegistry(string $backendRoot, array $manifest): void
    {
        $declared = $manifest['supporting_evidence_v1'] ?? null;
        if ($declared === null) {
            return;
        }
        $path = rtrim($backendRoot, '/').'/'.self::RELATIVE_PATH.'/supporting-evidence-v1.json';
        if (! is_array($declared)
            || ($declared['contract_version'] ?? null) !== CareerSupportingEvidenceV1Contract::CONTRACT_VERSION
            || ($declared['registry_contract_version'] ?? null) !== 'career.supporting_evidence.registry.v1'
            || ($declared['registry_path'] ?? null) !== 'supporting-evidence-v1.json'
            || ! is_int($declared['zh_supporting_evidence_count'] ?? null)
            || ($declared['zh_supporting_evidence_count'] ?? 0) < 1
            || ! is_string($declared['registry_sha256'] ?? null)
            || ! is_file($path) || is_link($path)
            || ! hash_equals((string) $declared['registry_sha256'], (string) hash_file('sha256', $path))) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_SUPPORTING_EVIDENCE_V1_REGISTRY_INVALID');
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
        $presentation = $row['metadata_json']['presentation_v1'] ?? null;
        if ($presentation !== null) {
            if (! is_array($presentation) || array_keys($presentation) !== ['zh'] || ! is_array($presentation['zh'])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_PRESENTATION_V1_INVALID');
            }
            CareerPresentationV1Contract::assert($presentation['zh']);
        }
        $supporting = $row['metadata_json']['supporting_evidence_v1'] ?? null;
        if ($supporting !== null) {
            if (! is_array($supporting) || array_keys($supporting) !== ['zh'] || ! is_array($supporting['zh'])) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_SUPPORTING_EVIDENCE_V1_INVALID');
            }
            $references = is_array($row['sources_json']['references'] ?? null)
                ? array_values($row['sources_json']['references']) : [];
            CareerSupportingEvidenceV1Contract::assert($supporting['zh'], $references);
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

        $projection = [
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
        $presentation = $row['metadata_json']['presentation_v1'][$normalizedLocale] ?? null;
        if ($normalizedLocale === 'zh' && is_array($presentation)) {
            CareerPresentationV1Contract::assert($presentation);
            $projection['presentation_v1'] = $this->stripForbiddenKeys($presentation);
        }
        $supporting = $row['metadata_json']['supporting_evidence_v1'][$normalizedLocale] ?? null;
        if ($normalizedLocale === 'zh' && is_array($supporting)) {
            $references = is_array($row['sources_json']['references'] ?? null)
                ? array_values($row['sources_json']['references']) : [];
            CareerSupportingEvidenceV1Contract::assert($supporting, $references);
            $projection['supporting_evidence_v1'] = $this->stripForbiddenKeys($supporting);
        }

        return $projection;
    }

    /** @param array<string,mixed> $row */
    public function publicContentHash(array $row, string $locale): string
    {
        return self::hashValue(
            $this->readerSafeProjector->project($this->publicProjection($row, $locale)),
        );
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
        foreach (self::OPTIONAL_DISPLAY_OWNED_PUBLIC_FIELDS as $field) {
            if (array_key_exists($field, $surface)) {
                $owned[$field] = $surface[$field];
            }
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

    public static function encodePrettyCanonical(mixed $value): string
    {
        $encoded = json_encode(
            self::canonicalize($value),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        return preg_replace_callback(
            '/^( +)/m',
            static fn (array $match): string => str_repeat(' ', intdiv(strlen($match[1]), 2)),
            $encoded,
        )."\n";
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
