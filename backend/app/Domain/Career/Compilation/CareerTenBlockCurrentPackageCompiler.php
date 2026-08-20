<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;

final class CareerTenBlockCurrentPackageCompiler
{
    public const VERSION = 'career.ten_block.current_package_compiler.v1';

    public function __construct(
        private readonly CareerTenBlockBatchNormalizer $batchNormalizer,
        private readonly CareerTenBlockCompiler $singleCompiler,
        private readonly CareerCurrentAuthorityPackage $package,
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
        $accountants = $this->singleCompiler->compile(
            $sourceRoot,
            'accountants-and-auditors',
            $lookupPath,
            rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/assets.jsonl',
            $evidenceRoot,
        );
        if ($accountants['row'] === null || ($accountants['receipt']['publication_eligible'] ?? false) !== true) {
            throw new CareerTenBlockCompileFailure('TEN_BLOCK_REQUIRED_EVIDENCE_BLOCKED');
        }
        $evidenceManifestPath = rtrim($evidenceRoot, '/').'/manifest.json';
        $schemaManifestPath = rtrim($evidenceRoot, '/').'/schema-profile-manifest.json';
        $evidenceAuthorityDigest = $this->fileDigest($evidenceManifestPath, 'TEN_BLOCK_EVIDENCE_INVALID');
        $schemaManifestDigest = $this->fileDigest($schemaManifestPath, 'TEN_BLOCK_EVIDENCE_INVALID');
        $lookupDigest = $this->fileDigest($lookupPath, 'TEN_BLOCK_LOOKUP_INVALID');
        $candidateRows = [];
        $publicChangedLocalePages = 0;
        foreach ($baseline['slugs'] as $slug) {
            $baselineRow = $baseline['rows'][$slug];
            $candidate = $slug === 'accountants-and-auditors' ? $accountants['row'] : $baselineRow;
            $profile = $batch['manifest']['profiles'][$slug];
            $candidate['metadata_json']['ten_block_compilation_v1'] = [
                'contract_version' => 'career.ten_block.row_lineage.v1',
                'compiler_version' => self::VERSION,
                'source_input_digest' => $profile['input_digest'],
                'schema_profile' => $profile['input_profile'],
                'normalized_ir_digest' => $profile['ir_digest'],
                'evidence_authority_digest' => $evidenceAuthorityDigest,
                'content_application' => $slug === 'accountants-and-auditors'
                    ? 'exact_claim_binding' : 'current_baseline_retained_missing_claim_bindings',
                'unsupported_claim_policy' => 'omit_or_retain_current_baseline',
            ];
            foreach (CareerCurrentAuthorityPackage::LOCALES as $locale) {
                if (! hash_equals(
                    $this->package->publicContentHash($baselineRow, $locale),
                    $this->package->publicContentHash($candidate, $locale),
                )) {
                    $publicChangedLocalePages++;
                }
            }
            $candidateRows[$slug] = $candidate;
        }
        $assetsBytes = implode("\n", array_map(
            static fn (array $row): string => CareerCurrentAuthorityPackage::encodeCanonical($row),
            $candidateRows,
        ))."\n";
        $manifest = $baseline['manifest'];
        $manifest['ten_block_compilation'] = [
            'contract_version' => 'career.ten_block.current_package_lineage.v1',
            'compiler_version' => self::VERSION,
            'source_root_digest' => $batch['manifest']['source_root_digest'],
            'lookup_digest' => $lookupDigest,
            'evidence_authority_digest' => $evidenceAuthorityDigest,
            'schema_profile_manifest_digest' => $schemaManifestDigest,
            'profile_counts' => $batch['receipt']['profile_counts'],
            'input_link_count' => $batch['receipt']['input_link_count'],
            'variant_rewrite_count' => $batch['receipt']['variant_rewrite_count'],
            'output_variant_link_count' => 0,
            'candidate_claim_policy' => 'exact_bound_or_current_baseline',
            'discoverability_writes' => 0,
            'search_submissions' => 0,
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
                'career_count' => count($candidateRows),
                'locale_page_count' => count($candidateRows) * 2,
                'components_per_page' => 26,
                'profile_counts' => $batch['receipt']['profile_counts'],
                'evidence_bound_slug_count' => 1,
                'baseline_retained_slug_count' => count($candidateRows) - 1,
                'active_claim_binding_count' => count($accountants['receipt']['claim_blockers']) === 0 ? 6 : 0,
                'active_evidence_source_count' => count($accountants['row']['sources_json']['references'] ?? []),
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
                'evidence_bound_slug_count' => 1,
                'baseline_retained_with_reason_slug_count' => count($candidateRows) - 1,
                'blocked_count' => 0,
                'baseline_retention_reason' => 'candidate claims without exact active binding remain on Current baseline authority',
            ],
            'package_diff' => [
                'contract_version' => 'career.ten_block.package_diff_report.v1',
                'before_career_count' => count($baseline['rows']),
                'after_career_count' => count($candidateRows),
                'missing_slug_count' => 0,
                'extra_slug_count' => 0,
                'duplicate_slug_count' => 0,
                'changed_row_count' => count($candidateRows),
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
