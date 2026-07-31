<?php

declare(strict_types=1);

namespace App\Services\ContentImport;

use RuntimeException;

final class MbtiResultEnglishPackageImporter
{
    public const PACKAGE_SHA256 = '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3';

    public const MANIFEST_SHA256 = '43f646a288c46b698d49f102eb7e7b611b66148f74cd459bd61ea9826d7c8bac';

    public const PACKAGE_ID = 'EN-PARITY-W1-MBTI-RESULT-ASSETS-2026-07-31';

    public const INVENTORY_PACKAGE_SHA256 = '8079465c6ec26820c99ca2be3f08346674e90509dee6d84fd610d5c6bbac2b85';

    private const EXPECTED_FILE_BYTES = [
        'package_manifest.json' => 3066,
        'README.md' => 2115,
        'assets.json' => 35524,
        'inventory_reconciliation.json' => 7922,
        'translation_map.json' => 5034,
        'entitlement_matrix.json' => 4202,
        'pdf_reader_fixture_mapping.json' => 8587,
        'claim_boundary_report.json' => 3097,
        'editorial_review.json' => 6192,
        'approval_envelope.json' => 1681,
    ];

    public static function defaultPackageDirectory(): string
    {
        return base_path('content_assets/en-content-parity/W1-mbti/result-content');
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(string $packageDirectory, string $confirmedPackageSha256): array
    {
        $confirmedPackageSha256 = strtolower(trim($confirmedPackageSha256));
        if ($confirmedPackageSha256 !== self::PACKAGE_SHA256) {
            $this->fail('confirmed_package_sha256_mismatch', 'The confirmed package SHA-256 is not the frozen W1 result package.');
        }

        $manifestBytes = $this->readPackageFile($packageDirectory, 'package_manifest.json');
        $manifest = $this->decodeJson($manifestBytes);
        $validatedPackage = $this->validateManifestAndReadPackageFiles($packageDirectory, $manifest, $manifestBytes);
        if ($validatedPackage['package_sha256'] !== self::PACKAGE_SHA256) {
            $this->fail('computed_package_sha256_mismatch', 'The package file chain does not reproduce the frozen W1 result package SHA-256.');
        }

        $documents = [];
        foreach (['assets.json', 'inventory_reconciliation.json', 'translation_map.json', 'entitlement_matrix.json', 'pdf_reader_fixture_mapping.json', 'approval_envelope.json'] as $filename) {
            $bytes = $validatedPackage['files'][$filename] ?? null;
            if (! is_string($bytes)) {
                $this->fail('required_file_not_declared', 'The frozen manifest is missing a required result-package document.');
            }
            $documents[$filename] = $this->decodeJson($bytes);
        }

        $this->validateTopLevelContracts($manifest, $documents);
        $assetPlans = $this->buildAssetPlans($documents['assets.json']);
        $rows = $this->buildReconciledRowPlans(
            $documents['inventory_reconciliation.json'],
            $documents['pdf_reader_fixture_mapping.json'],
            $assetPlans,
        );

        return [
            'artifact' => 'EN-PARITY-W1-MBTI-RESULT-IMPORTER-DRY-RUN-RECEIPT',
            'schema_version' => 'fermatmind.en_parity.result_import_dry_run_receipt.v1',
            'status' => 'pass',
            'ok' => true,
            'mode' => 'dry_run',
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'database_write_attempted' => false,
            'cms_write_attempted' => false,
            'private_payload_read_attempted' => false,
            'attempt_or_report_accessed' => false,
            'activation_attempted' => false,
            'publish_attempted' => false,
            'indexability_attempted' => false,
            'search_submission_attempted' => false,
            'package' => [
                'package_id' => self::PACKAGE_ID,
                'package_sha256' => self::PACKAGE_SHA256,
                'inventory_package_sha256' => self::INVENTORY_PACKAGE_SHA256,
                'inventory_row_count' => 46,
                'preserved_control_count' => 24,
                'candidate_asset_count' => 21,
                'w9_fixture_target_count' => 1,
                'reader_copy_in_receipt' => false,
                'local_path_in_receipt' => false,
            ],
            'target_contract' => [
                'scale_code' => 'MBTI',
                'locale' => 'en',
                'region' => 'GLOBAL',
                'pack_id' => 'MBTI.global.en.default',
                'target_state' => 'inactive_draft',
                'existing_target_required_before_write' => true,
                'private_result_authority_read_allowed' => false,
                'active_pointer_change_allowed' => false,
                'public_release_allowed' => false,
            ],
            'replay_contract' => [
                'deterministic' => true,
                'identity_field' => 'row_id',
                'same_package_same_plan' => true,
                'duplicate_asset_creation_allowed' => false,
                'write_execution_deferred_to_pr' => 'EN-PARITY-W1-MBTI-RESULT-DRAFT-IMPORT-01',
            ],
            'row_count' => count($rows),
            'rows' => $rows,
            'errors' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{package_sha256: string, files: array<string, string>}
     */
    private function validateManifestAndReadPackageFiles(string $packageDirectory, array $manifest, string $manifestBytes): array
    {
        if (! hash_equals(self::MANIFEST_SHA256, hash('sha256', $manifestBytes))) {
            $this->fail('manifest_sha256_mismatch', 'The package manifest bytes do not match the frozen W1 result manifest.');
        }

        if (($manifest['package_sha256'] ?? null) !== self::PACKAGE_SHA256) {
            $this->fail('manifest_package_sha256_mismatch', 'The manifest does not name the frozen W1 result package SHA-256.');
        }

        $files = $manifest['files'] ?? null;
        if (! is_array($files) || count($files) !== 9) {
            $this->fail('manifest_files_invalid', 'The result-package manifest must retain its exact nine-file chain.');
        }

        $chain = '';
        $seen = [];
        $verifiedFiles = [];
        foreach (array_values($files) as $position => $entry) {
            if (! is_array($entry)) {
                $this->fail('manifest_file_entry_invalid', 'Every manifest file entry must be an object.');
            }

            $path = trim((string) ($entry['path'] ?? ''));
            $expectedSha256 = trim((string) ($entry['sha256'] ?? ''));
            if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || basename($path) !== $path) {
                $this->fail('manifest_file_path_invalid', 'Manifest file paths must be safe package-root filenames.');
            }
            if (isset($seen[$path])) {
                $this->fail('manifest_file_duplicate', 'Manifest file paths must be unique.');
            }
            if (preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1) {
                $this->fail('manifest_file_sha256_invalid', 'Manifest file SHA-256 values must be lowercase hexadecimal.');
            }

            $fileBytes = $this->readPackageFile($packageDirectory, $path);
            if (! hash_equals($expectedSha256, hash('sha256', $fileBytes))) {
                $this->fail('manifest_file_sha256_mismatch', 'A manifest-declared package file no longer matches its frozen SHA-256.');
            }

            $seen[$path] = $position;
            $verifiedFiles[$path] = $fileBytes;
            $chain .= $path."\0".$expectedSha256."\n";
        }

        return [
            'package_sha256' => hash('sha256', $chain),
            'files' => $verifiedFiles,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $documents
     */
    private function validateTopLevelContracts(array $manifest, array $documents): void
    {
        $approval = $documents['approval_envelope.json'];
        $assets = $documents['assets.json'];
        $reconciliation = $documents['inventory_reconciliation.json'];
        $translation = $documents['translation_map.json'];
        $entitlements = $documents['entitlement_matrix.json'];
        $pdf = $documents['pdf_reader_fixture_mapping.json'];

        foreach ([$manifest, $approval] as $document) {
            if (($document['package_id'] ?? null) !== self::PACKAGE_ID
                || ($document['inventory_package_sha256'] ?? null) !== self::INVENTORY_PACKAGE_SHA256
                || ($document['status'] ?? null) !== 'unpublished_candidate') {
                $this->fail('package_identity_mismatch', 'The frozen result package identity or lifecycle contract is invalid.');
            }
        }

        if (($assets['package_id'] ?? null) !== self::PACKAGE_ID
            || ($assets['inventory_package_sha256'] ?? null) !== self::INVENTORY_PACKAGE_SHA256
            || ($assets['locale'] ?? null) !== 'en'
            || ($assets['asset_count'] ?? null) !== 21) {
            $this->fail('assets_contract_mismatch', 'The assets document must retain the exact English 21-candidate contract.');
        }

        if (($reconciliation['package_id'] ?? null) !== self::PACKAGE_ID
            || ($reconciliation['reconciliation']['total_rows'] ?? null) !== 46
            || ($reconciliation['reconciliation']['preserved_complete_controls'] ?? null) !== 24
            || ($reconciliation['reconciliation']['candidate_assets'] ?? null) !== 21
            || ($reconciliation['reconciliation']['w9_fixture_targets'] ?? null) !== 1
            || ($reconciliation['reconciliation']['producer_targets'] ?? null) !== 22) {
            $this->fail('reconciliation_contract_mismatch', 'The result inventory must remain exactly 46 = 24 + 21 + 1.');
        }

        if (($translation['package_id'] ?? null) !== self::PACKAGE_ID
            || ($translation['source_locale'] ?? null) !== 'zh-CN'
            || ($translation['target_locale'] ?? null) !== 'en'
            || count($translation['assets'] ?? []) !== 21) {
            $this->fail('locale_pairing_mismatch', 'Translation mapping must retain the exact zh-CN-to-en 21-asset cohort.');
        }

        $requiredSurfaces = [
            'free_result',
            'preview_result',
            'locked_result',
            'full_result',
            'entitlement_envelope',
            'share_public_summary',
            'pdf_reader',
            'history_account_reentry',
            'module_and_cta_labels',
            'processing_empty_error_expired_access_denied',
        ];
        $actualSurfaces = array_map(
            static fn (mixed $surface): mixed => is_array($surface) ? ($surface['surface'] ?? null) : null,
            $entitlements['surfaces'] ?? [],
        );
        if (($entitlements['mobile_desktop_authority'] ?? null) !== 'same_fields' || $actualSurfaces !== $requiredSurfaces) {
            $this->fail('entitlement_surface_mismatch', 'The exact ten-surface entitlement and mobile/desktop contract is invalid.');
        }

        $runtimeAccess = $pdf['fixture_contract']['required_runtime_access'] ?? null;
        if (($pdf['row_id'] ?? null) !== 'W1-RESULT-SURFACE-02-PDF'
            || ($pdf['status'] ?? null) !== 'w9_fixture_target'
            || ($pdf['fixture_contract']['required_locale'] ?? null) !== 'en'
            || ($pdf['fixture_contract']['production_payload_read_allowed'] ?? null) !== false
            || ($pdf['fixture_contract']['database_read_allowed'] ?? null) !== false
            || ! is_array($runtimeAccess)
            || ($runtimeAccess['mbti_access_hub_v1.access_state'] ?? null) !== 'ready'
            || ($runtimeAccess['access_level'] ?? null) !== 'full'
            || ($runtimeAccess['variant'] ?? null) !== 'full'
            || ($runtimeAccess['locked'] ?? null) !== false
            || ($runtimeAccess['mbti_access_hub_v1.report_access.can_view_report'] ?? null) !== true
            || ($runtimeAccess['mbti_access_hub_v1.pdf_access.can_download_pdf'] ?? null) !== true) {
            $this->fail('pdf_access_identity_mismatch', 'The synthetic PDF fixture must retain the exact private-safe entitled access identity.');
        }

        if (($approval['locale'] ?? null) !== 'en'
            || ($approval['inventory_row_count'] ?? null) !== 46
            || ($approval['preserved_control_count'] ?? null) !== 24
            || ($approval['candidate_asset_count'] ?? null) !== 21
            || ($approval['w9_fixture_target_count'] ?? null) !== 1
            || ($approval['producer_target_count'] ?? null) !== 22) {
            $this->fail('approval_envelope_mismatch', 'The approval envelope does not bind the exact result cohort.');
        }

        foreach (['private_payload_read_authorized', 'cms_write_authorized', 'database_write_authorized', 'draft_import_authorized', 'production_import_authorized', 'publication_authorized', 'activation_authorized', 'seo_runtime_release_authorized', 'indexability_authorized', 'search_submission_authorized', 'deploy_authorized'] as $permission) {
            if (($manifest['permissions'][$permission] ?? null) !== false || ($approval['permissions'][$permission] ?? null) !== false) {
                $this->fail('package_permission_open', 'Every controlled result-package permission must remain false.');
            }
        }

        $authority = $assets['template_contract']['authority_targets']['english_commercial_spec_v1'] ?? null;
        if (! is_array($authority)
            || ($authority['scale_code'] ?? null) !== 'MBTI'
            || ($authority['region'] ?? null) !== 'GLOBAL'
            || ($authority['locale'] ?? null) !== 'en'
            || ($authority['pack_id'] ?? null) !== 'MBTI.global.en.default'
            || ($authority['target_state'] ?? null) !== 'inactive_draft'
            || ($authority['existing_at_package_freeze'] ?? null) !== false
            || ($authority['materialized_by_this_package'] ?? null) !== false
            || ($authority['importer_absent_target_behavior'] ?? null) !== 'fail_closed_with_separate_pack_creation_prerequisite') {
            $this->fail('target_authority_identity_mismatch', 'The target must remain the absent inactive English MBTI content-pack authority.');
        }
    }

    /**
     * @param  array<string, mixed>  $assetsDocument
     * @return array<string, array<string, mixed>>
     */
    private function buildAssetPlans(array $assetsDocument): array
    {
        $assets = $assetsDocument['assets'] ?? null;
        if (! is_array($assets) || count($assets) !== 21) {
            $this->fail('asset_count_mismatch', 'The exact result package must contain 21 candidate assets.');
        }

        $plans = [];
        foreach ($assets as $position => $asset) {
            if (! is_array($asset)) {
                $this->fail('asset_not_object', 'Every result asset must be an object.');
            }

            $rowId = trim((string) ($asset['row_id'] ?? ''));
            $stableIdentity = trim((string) ($asset['stable_asset_identity'] ?? ''));
            $kind = $asset['asset_kind'] ?? null;
            $authorityField = $asset['authority_field'] ?? null;
            $entitlement = $asset['entitlement_level'] ?? null;
            if ($rowId === '' || $stableIdentity === '' || isset($plans[$rowId])) {
                $this->fail('asset_identity_invalid', 'Candidate row and stable identities must be present and unique.');
            }
            if (($asset['mobile_desktop_consumption'] ?? null) !== 'same_authority_fields') {
                $this->fail('mobile_desktop_authority_mismatch', 'Every candidate must use the same authority fields on mobile and desktop.');
            }

            if ($kind === 'offer_copy_family') {
                if ($rowId !== 'W1-RESULT-CORE-05-OFFER-CTA'
                    || $stableIdentity !== 'mbti:result:offer-set-and-cta-copy'
                    || $authorityField !== 'commercial_spec.variants[].cta_copy'
                    || ($asset['consumer_field'] ?? null) !== 'offer_set.cta'
                    || $entitlement !== 'locked_upsell_only') {
                    $this->fail('offer_identity_mismatch', 'The offer candidate must retain its exact authority, consumer, and locked access identity.');
                }
            } elseif ($kind === 'canonical_section_family') {
                $sectionKey = trim((string) ($asset['section_key'] ?? ''));
                if ($sectionKey === ''
                    || $stableIdentity !== 'mbti:result:section:'.$sectionKey
                    || ! in_array($entitlement, ['free_preview_or_full_by_access_policy', 'premium_full'], true)
                    || ($entitlement === 'premium_full'
                        ? $authorityField !== 'premium_teaser.'.$sectionKey
                        : $authorityField !== 'sections.'.$sectionKey)) {
                    $this->fail('canonical_section_identity_mismatch', 'Canonical section, authority bucket, and entitlement identities must align.');
                }
            } else {
                $this->fail('asset_kind_invalid', 'Only the exact offer and canonical section candidate kinds are accepted.');
            }

            $plans[$rowId] = [
                'position' => $position + 1,
                'row_id' => $rowId,
                'stable_asset_identity' => $stableIdentity,
                'asset_kind' => $kind,
                'authority_field' => $authorityField,
                'section_key' => $asset['section_key'] ?? null,
                'entitlement_level' => $entitlement,
            ];
        }

        return $plans;
    }

    /**
     * @param  array<string, mixed>  $reconciliation
     * @param  array<string, mixed>  $pdf
     * @param  array<string, array<string, mixed>>  $assetPlans
     * @return list<array<string, mixed>>
     */
    private function buildReconciledRowPlans(array $reconciliation, array $pdf, array $assetPlans): array
    {
        $rows = $reconciliation['rows'] ?? null;
        if (! is_array($rows) || count($rows) !== 46) {
            $this->fail('inventory_row_count_mismatch', 'The exact result inventory must contain 46 ordered rows.');
        }

        $plans = [];
        $seen = [];
        $counts = ['preserved_reference' => 0, 'candidate_asset' => 0, 'w9_fixture_target' => 0];
        foreach ($rows as $position => $row) {
            if (! is_array($row)) {
                $this->fail('inventory_row_invalid', 'Every reconciliation row must be an object.');
            }

            $rowId = trim((string) ($row['row_id'] ?? ''));
            $disposition = $row['disposition'] ?? null;
            if ($rowId === '' || isset($seen[$rowId]) || ! array_key_exists((string) $disposition, $counts)) {
                $this->fail('inventory_identity_invalid', 'Inventory row identities and dispositions must be unique and registered.');
            }
            $seen[$rowId] = true;
            $counts[$disposition]++;

            if ($disposition === 'candidate_asset') {
                $assetPlan = $assetPlans[$rowId] ?? null;
                if (! is_array($assetPlan) || ($row['content_in_package'] ?? null) !== true) {
                    $this->fail('candidate_reconciliation_mismatch', 'Every candidate row must map exactly once to package content.');
                }
                $plans[] = $assetPlan + [
                    'inventory_position' => $position + 1,
                    'disposition' => 'candidate_asset',
                    'action' => 'would_stage_inactive_english_candidate',
                    'planned_state' => 'inactive_draft',
                    'write_executed' => false,
                    'reader_copy_in_plan' => false,
                ];
            } elseif ($disposition === 'w9_fixture_target') {
                if ($rowId !== ($pdf['row_id'] ?? null)
                    || ($row['content_in_package'] ?? null) !== false
                    || isset($assetPlans[$rowId])) {
                    $this->fail('pdf_fixture_reconciliation_mismatch', 'The PDF row must remain a synthetic W9 fixture target without package content.');
                }
                $plans[] = [
                    'inventory_position' => $position + 1,
                    'row_id' => $rowId,
                    'disposition' => 'w9_fixture_target',
                    'fixture_kind' => 'synthetic_private_safe',
                    'required_locale' => 'en',
                    'action' => 'validate_fixture_mapping_only',
                    'planned_state' => 'inactive_no_write',
                    'write_executed' => false,
                    'private_payload_read' => false,
                    'reader_copy_in_plan' => false,
                ];
            } else {
                if (($row['content_in_package'] ?? null) !== false || isset($assetPlans[$rowId])) {
                    $this->fail('preserved_control_reconciliation_mismatch', 'Preserved controls must not be reauthored or imported.');
                }
                $plans[] = [
                    'inventory_position' => $position + 1,
                    'row_id' => $rowId,
                    'disposition' => 'preserved_reference',
                    'action' => 'preserve_existing_control_no_write',
                    'planned_state' => 'unchanged',
                    'write_executed' => false,
                    'reader_copy_in_plan' => false,
                ];
            }
        }

        if ($counts !== ['preserved_reference' => 24, 'candidate_asset' => 21, 'w9_fixture_target' => 1]
            || count($assetPlans) !== 21) {
            $this->fail('reconciliation_count_mismatch', 'The dry-run plan must remain exactly 24 preserved, 21 candidate, and one W9 fixture row.');
        }

        return $plans;
    }

    private function readPackageFile(string $packageDirectory, string $filename): string
    {
        $expectedBytes = self::EXPECTED_FILE_BYTES[$filename] ?? null;
        if (! is_int($expectedBytes)) {
            $this->fail('package_file_not_allowlisted', 'Only frozen exact-package filenames may be read.');
        }

        $path = rtrim($packageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;
        if (is_link($path)) {
            $this->fail('package_file_symlink_rejected', 'Exact-package files must not be symbolic links.');
        }

        $linkStat = @lstat($path);
        if ($linkStat === false || (($linkStat['mode'] ?? 0) & 0170000) !== 0100000) {
            $this->fail('package_file_missing', 'A required exact-package file is missing or is not a regular file.');
        }
        if (($linkStat['nlink'] ?? null) !== 1) {
            $this->fail('package_file_hardlink_rejected', 'Exact-package files must have exactly one filesystem link.');
        }
        if (($linkStat['size'] ?? null) !== $expectedBytes) {
            $this->fail('package_file_size_mismatch', 'An exact-package file does not match its frozen byte length.');
        }

        $resolvedDirectory = realpath($packageDirectory);
        $resolvedPath = realpath($path);
        if ($resolvedDirectory === false
            || $resolvedPath === false
            || ! str_starts_with($resolvedPath, rtrim($resolvedDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            $this->fail('package_file_boundary_invalid', 'Exact-package files must resolve inside the selected package directory.');
        }

        $handle = @fopen($resolvedPath, 'rb');
        if ($handle === false) {
            $this->fail('package_file_unreadable', 'A required exact-package file cannot be opened safely.');
        }

        try {
            $openedStat = fstat($handle);
            if ($openedStat === false
                || (($openedStat['mode'] ?? 0) & 0170000) !== 0100000
                || ($openedStat['nlink'] ?? null) !== 1
                || ($openedStat['size'] ?? null) !== $expectedBytes
                || ($openedStat['dev'] ?? null) !== ($linkStat['dev'] ?? null)
                || ($openedStat['ino'] ?? null) !== ($linkStat['ino'] ?? null)) {
                $this->fail('package_file_identity_changed', 'Exact-package file identity changed before the safe read.');
            }

            $bytes = stream_get_contents($handle);
            if ($bytes === false) {
                $this->fail('package_file_unreadable', 'A required exact-package file cannot be read safely.');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $bytes): array
    {
        $decoded = json_decode($bytes, true);
        if (! is_array($decoded)) {
            $this->fail('package_file_invalid_json', 'A required exact-package file is not a JSON object.');
        }

        return $decoded;
    }

    private function fail(string $code, string $message): never
    {
        throw new RuntimeException($code.': '.$message);
    }
}
