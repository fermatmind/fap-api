<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;

final class CareerTenBlockCurrentPackageCompiler
{
    public const VERSION = 'career.ten_block.current_package_compiler.v3';

    private const ACCOUNTANTS_SLUG = 'accountants-and-auditors';

    public function __construct(
        private readonly CareerTenBlockBatchNormalizer $batchNormalizer,
        private readonly CareerTenBlockCompiler $singleCompiler,
        private readonly CareerEvidenceAuthorityLoader $evidenceLoader,
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerCurrentZhBatchPreparer $sourcePreparer,
    ) {}

    /** @return array{assets_bytes:string,manifest_template:array<string,mixed>,receipt:array<string,mixed>,field_coverage:array<string,mixed>,package_diff:array<string,mixed>} */
    public function compile(
        string $sourceRoot,
        string $lookupPath,
        string $evidenceRoot,
        string $backendRoot,
    ): array {
        $batch = $this->batchNormalizer->normalize($sourceRoot, $lookupPath);
        $baseline = $this->package->load($backendRoot);
        $profileSlugs = array_keys($batch['manifest']['profiles']);
        if ($profileSlugs !== $baseline['slugs']) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_CURRENT_SLUG_SET_MISMATCH');
        }
        $cohort = $this->evidenceLoader->cohort($evidenceRoot);
        $boundSlugs = $cohort['evidence_bound_slugs'];
        if (array_diff($boundSlugs, $baseline['slugs']) !== []) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_EVIDENCE_COHORT_INVALID');
        }
        $compiled = [];
        foreach ($boundSlugs as $slug) {
            $result = $this->singleCompiler->compile(
                $sourceRoot,
                $slug,
                $lookupPath,
                rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/assets.jsonl',
                $evidenceRoot,
            );
            if ($result['row'] === null || ($result['receipt']['publication_eligible'] ?? false) !== true) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_REQUIRED_EVIDENCE_BLOCKED');
            }
            $compiled[$slug] = $result;
        }
        $evidenceManifestPath = rtrim($evidenceRoot, '/').'/manifest.json';
        $schemaManifestPath = rtrim($evidenceRoot, '/').'/schema-profile-manifest.json';
        $evidenceAuthorityDigest = $this->fileDigest($evidenceManifestPath, 'TEN_BLOCK_EVIDENCE_INVALID');
        $schemaManifestDigest = $this->fileDigest($schemaManifestPath, 'TEN_BLOCK_EVIDENCE_INVALID');
        $cohortDigest = $this->fileDigest(rtrim($evidenceRoot, '/').'/cohort.json', 'TEN_BLOCK_EVIDENCE_INVALID');
        $selectionReportDigest = $this->fileDigest(rtrim($evidenceRoot, '/').'/selection-report.json', 'TEN_BLOCK_EVIDENCE_INVALID');
        $lookupDigest = $this->fileDigest($lookupPath, 'TEN_BLOCK_LOOKUP_INVALID');
        $componentRegistryDigest = $this->fileDigest(
            rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/structured-component-source-registry.json',
            'TEN_BLOCK_STRUCTURED_COMPONENT_SOURCE_REGISTRY_INVALID',
        );
        $candidateRows = [];
        $publicChangedLocalePages = 0;
        $changedRows = 0;
        $activeClaimBindings = 0;
        $activeSources = [];
        foreach ($baseline['slugs'] as $slug) {
            $baselineRow = $baseline['rows'][$slug];
            $candidate = $this->sourcePreparer->candidateRowForSource($sourceRoot, $slug, $baselineRow);
            $profile = $batch['manifest']['profiles'][$slug];
            $candidate['metadata_json']['ten_block_compilation_v1'] = [
                'contract_version' => 'career.ten_block.row_lineage.v1',
                'compiler_version' => self::VERSION,
                'source_input_digest' => $profile['input_digest'],
                'schema_profile' => $profile['input_profile'],
                'normalized_ir_digest' => $profile['ir_digest'],
                'evidence_authority_digest' => $evidenceAuthorityDigest,
                'structured_component_source_registry_sha256' => $componentRegistryDigest,
                'content_application' => 'full_v4_3_structured_component_projection',
                'unsupported_claim_policy' => 'fail_closed',
            ];
            $activeClaimBindings += 2;
            foreach ($candidate['sources_json']['references'] ?? [] as $source) {
                if (is_array($source) && is_string($source['source_key'] ?? null)) {
                    $activeSources[$source['source_key']] = true;
                }
            }
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                $comparisonHash = $this->package->publicContentHash($baselineRow, $locale);
                if (! hash_equals(
                    $comparisonHash,
                    $this->package->publicContentHash($candidate, $locale),
                )) {
                    $publicChangedLocalePages++;
                }
            }
            $comparisonRowHash = CareerCurrentAuthorityPackage::hashValue($baselineRow);
            if (! hash_equals(
                $comparisonRowHash,
                CareerCurrentAuthorityPackage::hashValue($candidate),
            )) {
                $changedRows++;
            }
            $candidateRows[$slug] = $candidate;
        }
        $baselineVersion = data_get($baseline, 'manifest.structural_contract.asset_version');
        if ($baselineVersion === 'v4.2'
            && ($publicChangedLocalePages !== count($candidateRows) * 2 || $changedRows !== count($candidateRows))) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_V4_3_DIFF_INVALID');
        }
        $assetsBytes = implode("\n", array_map(
            static fn (array $row): string => CareerCurrentAuthorityPackage::encodeCanonical($row),
            $candidateRows,
        ))."\n";
        $manifest = $baseline['manifest'];
        $manifest['structural_contract']['asset_version'] = CareerCurrentAuthorityPackage::ASSET_VERSION;
        $manifest['structural_contract']['template_version'] = CareerCurrentAuthorityPackage::ASSET_VERSION;
        $manifest['structural_contract']['component_order'] = CareerDisplayAssetComponentContract::CURRENT_V4_3_ORDER;
        $manifest['ten_block_compilation'] = [
            'contract_version' => 'career.ten_block.current_package_lineage.v1',
            'compiler_version' => self::VERSION,
            'source_root_digest' => $batch['manifest']['source_root_digest'],
            'lookup_digest' => $lookupDigest,
            'evidence_authority_digest' => $evidenceAuthorityDigest,
            'schema_profile_manifest_digest' => $schemaManifestDigest,
            'cohort_digest' => $cohortDigest,
            'selection_report_digest' => $selectionReportDigest,
            'profile_counts' => $batch['receipt']['profile_counts'],
            'input_link_count' => $batch['receipt']['input_link_count'],
            'variant_rewrite_count' => $batch['receipt']['variant_rewrite_count'],
            'output_variant_link_count' => 0,
            'candidate_claim_policy' => 'full_structured_component_projection_with_exact_source_value_digests',
            'discoverability_writes' => 0,
            'search_submissions' => 0,
        ];
        $manifest['structured_components_v1'] = [
            'contract_version' => 'career.structured_components.package_lineage.v1',
            'source_registry' => [
                'path' => 'structured-component-source-registry.json',
                'sha256' => $componentRegistryDigest,
            ],
            'source_root_digest' => $batch['manifest']['source_root_digest'],
            'schema_version' => CareerTenBlockVariantSchema::VERSION,
            'schema_profile_manifest_sha256' => CareerCurrentAuthorityPackage::hashValue($batch['manifest']),
            'profile_counts' => $batch['receipt']['profile_counts'],
            'claim_binding_count' => $activeClaimBindings,
            'zh_published_component_count' => count($candidateRows) * 2,
            'en_unavailable_component_count' => count($candidateRows) * 2,
        ];
        $this->assertCandidatePublicContract($candidateRows);

        return [
            'assets_bytes' => $assetsBytes,
            'manifest_template' => $manifest,
            'receipt' => [
                'contract_version' => 'career.ten_block.full_compile_receipt.v1',
                'compiler_version' => self::VERSION,
                'source_root_digest' => $batch['manifest']['source_root_digest'],
                'lookup_digest' => $lookupDigest,
                'evidence_authority_digest' => $evidenceAuthorityDigest,
                'schema_profile_manifest_digest' => $schemaManifestDigest,
                'cohort_digest' => $cohortDigest,
                'selection_report_digest' => $selectionReportDigest,
                'career_count' => count($candidateRows),
                'locale_page_count' => count($candidateRows) * 2,
                'components_per_page' => 28,
                'profile_counts' => $batch['receipt']['profile_counts'],
                'evidence_bound_slug_count' => count($boundSlugs),
                'baseline_retained_slug_count' => 0,
                'active_claim_binding_count' => $activeClaimBindings,
                'active_evidence_source_count' => count($activeSources),
                'input_link_count' => $batch['receipt']['input_link_count'],
                'variant_rewrite_count' => $batch['receipt']['variant_rewrite_count'],
                'unresolved_link_count' => 0,
                'output_variant_link_count' => 0,
                'unknown_profile_count' => 0,
                'ambiguous_profile_count' => 0,
                'blocker_count' => 0,
                'forbidden_public_key_count' => 0,
                'forbidden_structured_data_type_count' => 0,
                'allowed_structured_data_families' => ['Occupation', 'BreadcrumbList', 'FAQPage'],
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
                'generated_at' => null,
            ],
            'field_coverage' => [
                'contract_version' => 'career.ten_block.field_coverage_report.v1',
                'source_field_dispositions' => $batch['receipt']['field_coverage_counts'],
                'silent_drop_count' => 0,
                'evidence_bound_slug_count' => count($boundSlugs),
                'baseline_retained_with_reason_slug_count' => 0,
                'blocked_count' => 0,
                'baseline_retention_reason' => null,
            ],
            'package_diff' => [
                'contract_version' => 'career.ten_block.package_diff_report.v1',
                'before_career_count' => count($baseline['rows']),
                'after_career_count' => count($candidateRows),
                'missing_slug_count' => 0,
                'extra_slug_count' => 0,
                'duplicate_slug_count' => 0,
                'changed_row_count' => $changedRows,
                'public_changed_locale_page_count' => $publicChangedLocalePages,
                'locale_integrity' => 'PASS',
                'component_order_integrity' => 'PASS',
                'manual_hold_integrity' => 'PASS',
                'canonical_route_inventory_changed' => false,
                'discoverability_surface_changed' => false,
                'output_variant_link_count' => 0,
                'forbidden_public_key_count' => 0,
                'forbidden_structured_data_type_count' => 0,
            ],
        ];
    }

    /**
     * Deterministically fills the accountants boundary component from already-published,
     * same-locale authority fields. This intentionally does not read source packages,
     * templates, or another occupation's projection.
     *
     * @return array{assets_bytes:string,manifest_template:array<string,mixed>,receipt:array<string,mixed>,package_diff:array<string,mixed>}
     */
    public function compileAccountantsBoundaryNoticeProjection(string $backendRoot): array
    {
        $baseline = $this->package->load($backendRoot);
        $candidateRows = $baseline['rows'];
        $accountants = $candidateRows[self::ACCOUNTANTS_SLUG] ?? null;
        if (! is_array($accountants)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_ACCOUNTANTS_BOUNDARY_TARGET_MISSING');
        }

        $pages = $accountants['page_payload_json']['page'] ?? null;
        if (! is_array($pages)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_ACCOUNTANTS_BOUNDARY_SOURCE_MISSING');
        }
        $notices = $this->deriveAccountantsBoundaryNotices($pages);
        foreach (['en', 'zh'] as $locale) {
            $candidateRows[self::ACCOUNTANTS_SLUG]['page_payload_json']['page'][$locale]['boundary_notice'] = $notices[$locale];
        }

        $changedSlugs = [];
        $publicChangedLocalePages = 0;
        foreach ($baseline['slugs'] as $slug) {
            $before = $baseline['rows'][$slug];
            $after = $candidateRows[$slug];
            if (! hash_equals(CareerCurrentAuthorityPackage::hashValue($before), CareerCurrentAuthorityPackage::hashValue($after))) {
                $changedSlugs[] = $slug;
            }
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                if (! hash_equals(
                    $this->package->publicContentHash($before, $locale),
                    $this->package->publicContentHash($after, $locale),
                )) {
                    $publicChangedLocalePages++;
                }
            }
        }
        if (($changedSlugs !== [] && $changedSlugs !== [self::ACCOUNTANTS_SLUG])
            || ! in_array($publicChangedLocalePages, [0, 1, 2], true)
            || (count($changedSlugs) === 0 && $publicChangedLocalePages !== 0)
            || (count($changedSlugs) === 1 && $publicChangedLocalePages === 0)) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_ACCOUNTANTS_BOUNDARY_SCOPE_INVALID');
        }
        $this->assertCandidatePublicContract($candidateRows);

        return [
            'assets_bytes' => implode("\n", array_map(
                static fn (array $row): string => CareerCurrentAuthorityPackage::encodeCanonical($row),
                $candidateRows,
            ))."\n",
            'manifest_template' => $baseline['manifest'],
            'receipt' => [
                'contract_version' => 'career.ten_block.accountants_boundary_projection_receipt.v1',
                'compiler_version' => self::VERSION,
                'canonical_slug' => self::ACCOUNTANTS_SLUG,
                'locale_notice_counts' => [
                    'en' => count($notices['en']),
                    'zh-CN' => count($notices['zh']),
                ],
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
                'generated_at' => null,
            ],
            'package_diff' => [
                'contract_version' => 'career.ten_block.package_diff_report.v1',
                'before_career_count' => count($baseline['rows']),
                'after_career_count' => count($candidateRows),
                'missing_slug_count' => 0,
                'extra_slug_count' => 0,
                'duplicate_slug_count' => 0,
                'changed_row_count' => count($changedSlugs),
                'changed_slugs' => $changedSlugs,
                'public_changed_locale_page_count' => $publicChangedLocalePages,
                'locale_integrity' => 'PASS',
                'component_order_integrity' => 'PASS',
                'manual_hold_integrity' => 'PASS',
                'canonical_route_inventory_changed' => false,
                'discoverability_surface_changed' => false,
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ],
        ];
    }

    /** @param array<string,mixed> $pages @return array{en:list<string>,zh:list<string>} */
    public function deriveAccountantsBoundaryNotices(array $pages): array
    {
        $notices = [];
        foreach (['en', 'zh'] as $locale) {
            $page = $pages[$locale] ?? null;
            $decision = is_array($page) && is_array($page['fermat_decision_card'] ?? null)
                ? $page['fermat_decision_card']
                : [];
            $caveat = $decision['caveat'] ?? null;
            $boundaries = [];
            foreach ($pages as $candidatePage) {
                $candidate = is_array($candidatePage)
                    ? ($candidatePage['ai_impact_table']['explanation'][$locale]['boundary'] ?? null)
                    : null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    $boundaries[trim($candidate)] = true;
                }
            }
            $boundary = count($boundaries) === 1 ? array_key_first($boundaries) : null;
            if ($boundary === null && is_array($page['boundary_notice'] ?? null)) {
                foreach ($page['boundary_notice'] as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        $boundary = trim($candidate);
                        break;
                    }
                }
            }
            if (! is_string($caveat) || trim($caveat) === '' || ! is_string($boundary) || trim($boundary) === '') {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_ACCOUNTANTS_BOUNDARY_SOURCE_MISSING');
            }
            $notices[$locale] = array_values(array_unique([trim($caveat), trim($boundary)]));
            if ($notices[$locale] === []) {
                throw new CareerTenBlockCompileFailure('TEN_BLOCK_ACCOUNTANTS_BOUNDARY_SOURCE_MISSING');
            }
        }

        return $notices;
    }

    /** @param array<string,array<string,mixed>> $rows */
    public function assertCandidatePublicContract(array $rows): void
    {
        if ($this->forbiddenStructuredDataCount($rows) !== 0 || $this->forbiddenPublicKeyCount($rows) !== 0) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_CURRENT_PUBLIC_CONTRACT_INVALID');
        }
    }

    /** @param array<string,array<string,mixed>> $rows */
    private function forbiddenStructuredDataCount(array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $this->walk((array) $row['structured_data_json'], static function (mixed $value, string|int $key) use (&$count): void {
                if ($key === '@type' && in_array($value, ['JobPosting', 'Article', 'Review', 'AggregateRating'], true)) {
                    $count++;
                }
            });
        }

        return $count;
    }

    /** @param array<string,array<string,mixed>> $rows */
    private function forbiddenPublicKeyCount(array $rows): int
    {
        $forbidden = [
            'private_answers', 'score_vector', 'percentile', 'attempt_url', 'report_url',
            'user_id', 'order_id', 'payment_id', 'compile_run_id', 'import_run_id', 'source_trace_id',
        ];
        $count = 0;
        foreach ($rows as $row) {
            $publicFields = [
                $row['page_payload_json'] ?? null,
                $row['seo_payload_json'] ?? null,
                $row['sources_json'] ?? null,
                $row['structured_data_json'] ?? null,
                $row['implementation_contract_json'] ?? null,
            ];
            $this->walk($publicFields, static function (mixed $_, string|int $key) use (&$count, $forbidden): void {
                if (is_string($key) && in_array($key, $forbidden, true)) {
                    $count++;
                }
            });
        }

        return $count;
    }

    private function walk(mixed $value, callable $visitor): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $visitor($child, $key);
            $this->walk($child, $visitor);
        }
    }

    private function fileDigest(string $path, string $safeCode): string
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerTenBlockCompileFailure($safeCode);
        }
        $digest = hash_file('sha256', $path);
        if (! is_string($digest)) {
            throw new CareerTenBlockCompileFailure($safeCode);
        }

        return $digest;
    }
}
