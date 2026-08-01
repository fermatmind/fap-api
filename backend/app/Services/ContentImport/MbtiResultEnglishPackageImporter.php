<?php

declare(strict_types=1);

namespace App\Services\ContentImport;

use DomainException;

final class MbtiResultEnglishPackageImporter
{
    private bool $authorityWriteAttempted = false;

    public const PACKAGE_SHA256 = '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3';

    public const MANIFEST_SHA256 = '43f646a288c46b698d49f102eb7e7b611b66148f74cd459bd61ea9826d7c8bac';

    public const PACKAGE_ID = 'EN-PARITY-W1-MBTI-RESULT-ASSETS-2026-07-31';

    public const INVENTORY_PACKAGE_SHA256 = '8079465c6ec26820c99ca2be3f08346674e90509dee6d84fd610d5c6bbac2b85';

    public const APPROVAL_SHA256 = '17ae71733abe77bd7e75f4492374879c1888c4f5f3f671f53b003b6b878152e2';

    public const APPROVAL_REF = 'human-operator:w1-mbti-result-content-draft-import:2026-08-01';

    private const ASSETS_SHA256 = '9bf660a7cb99c925db7b5bc96896eee82c85e47e80414add2b1bbc98f0f71113';

    private const RECONCILIATION_SHA256 = '20fb311db62ef5a5abf227d704dc9a006091574cfedcc49b517dcc701f350e29';

    private const APPROVAL_BYTES = 923;

    private const AUTHORITY_FILES = [
        'manifest.json' => [742, 'e7aec232468edfa593c3bad3bf8634232f1c4f1b806a39886afbe6d9c84c46ff'],
        'version.json' => [281, 'cce8114f2ed2a9b2a29a7ebd7e0a08a39e1a6d53fa0a7dba144d8594f4218003'],
        'commercial_spec.json' => [770, 'ff560a5c8dd8aa830b00804193cfb36b5bdaaf454bc0d29ce63cfdf1dbbef49d'],
    ];

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

    public static function defaultApprovalPath(): string
    {
        return base_path('content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-RESULT-CONTENT/draft-import-approval-2026-08-01.json');
    }

    public static function defaultAuthorityDirectory(): string
    {
        return base_path('../content_packages/default/GLOBAL/en/MBTI-GLOBAL-en-v0.3');
    }

    public static function defaultDraftPath(): string
    {
        return self::defaultAuthorityDirectory().'/drafts/en-parity-w1-mbti-result-content-v1.json';
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
     * @return array<string, mixed>
     */
    public function importDraft(
        string $packageDirectory,
        string $confirmedPackageSha256,
        string $approvalPath,
        string $confirmedApprovalSha256,
        ?string $authorityDirectory = null,
    ): array {
        $this->authorityWriteAttempted = false;
        $this->assertWriteEnvironmentAllowed();
        $plan = $this->plan($packageDirectory, $confirmedPackageSha256);
        $approval = $this->validatedApproval($approvalPath, $confirmedApprovalSha256);
        $authorityDirectory ??= self::defaultAuthorityDirectory();
        $this->validateInactiveAuthorityPrerequisite($authorityDirectory);

        $assetsBytes = $this->readPackageFile($packageDirectory, 'assets.json');
        $reconciliationBytes = $this->readPackageFile($packageDirectory, 'inventory_reconciliation.json');
        if (! hash_equals(self::ASSETS_SHA256, hash('sha256', $assetsBytes))
            || ! hash_equals(self::RECONCILIATION_SHA256, hash('sha256', $reconciliationBytes))) {
            $this->fail('write_source_sha256_mismatch', 'The write source no longer matches the exact validated package files.');
        }
        $assets = $this->decodeJson($assetsBytes)['assets'] ?? null;
        $inventoryRows = $this->decodeJson($reconciliationBytes)['rows'] ?? null;
        if (! is_array($assets) || count($assets) !== 21 || ! is_array($inventoryRows) || count($inventoryRows) !== 46) {
            $this->fail('write_cohort_mismatch', 'The inactive authority write must retain the exact 46-row and 21-candidate cohort.');
        }
        $assetsByRow = [];
        foreach ($assets as $asset) {
            if (! is_array($asset) || ! is_string($asset['row_id'] ?? null)) {
                $this->fail('write_asset_invalid', 'Every inactive authority candidate must retain a valid row identity.');
            }
            $assetsByRow[$asset['row_id']] = $asset;
        }

        $draftRows = [];
        foreach ($inventoryRows as $position => $row) {
            if (! is_array($row)) {
                $this->fail('write_inventory_row_invalid', 'Every inactive authority reconciliation row must be an object.');
            }
            $rowId = (string) ($row['row_id'] ?? '');
            $disposition = (string) ($row['disposition'] ?? '');
            $draftRow = [
                'position' => $position + 1,
                'row_id' => $rowId,
                'disposition' => $disposition,
                'authority_state' => $disposition === 'candidate_asset' ? 'inactive_draft' : 'unchanged_no_write',
            ];
            if ($disposition === 'candidate_asset') {
                $asset = $assetsByRow[$rowId] ?? null;
                if (! is_array($asset)) {
                    $this->fail('write_candidate_mapping_missing', 'Every candidate row must map to one exact package asset.');
                }
                $draftRow['asset_sha256'] = hash('sha256', $this->canonicalJson($asset));
                $draftRow['asset'] = $asset;
            }
            $draftRows[] = $draftRow;
        }

        $authority = [
            'schema_version' => 'fermatmind.mbti.en_result_content_inactive_draft.v1',
            'authority' => [
                'pack_id' => 'MBTI.global.en.default',
                'region' => 'GLOBAL',
                'locale' => 'en',
                'content_package_version' => 'v0.3',
                'state' => 'inactive_draft',
                'runtime_available' => false,
                'active_pointer_registered' => false,
            ],
            'source' => [
                'package_id' => self::PACKAGE_ID,
                'package_sha256' => self::PACKAGE_SHA256,
                'inventory_package_sha256' => self::INVENTORY_PACKAGE_SHA256,
                'approval_ref' => self::APPROVAL_REF,
                'approval_sha256' => self::APPROVAL_SHA256,
            ],
            'counts' => [
                'total_rows' => 46,
                'preserved_reference_rows' => 24,
                'inactive_candidate_rows' => 21,
                'fixture_validation_rows' => 1,
                'authority_content_rows' => 21,
            ],
            'permissions' => [
                'private_payload_read' => false,
                'activation' => false,
                'publication' => false,
                'indexability' => false,
                'sitemap' => false,
                'llms' => false,
                'search_submission' => false,
                'deployment' => false,
            ],
            'rows' => $draftRows,
        ];
        $this->assertPrivateFieldsExcluded($authority);
        $bytes = (string) json_encode($authority, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        $authoritySha256 = hash('sha256', $bytes);
        $created = $this->writeAuthorityFile($bytes, $authorityDirectory);

        return [
            'artifact' => 'EN-PARITY-W1-MBTI-RESULT-DRAFT-IMPORT-RECEIPT',
            'schema_version' => 'fermatmind.en_parity.result_draft_import_receipt.v1',
            'status' => 'pass',
            'ok' => true,
            'mode' => 'write_inactive_draft',
            'dry_run_only' => false,
            'write_supported_in_this_pr' => true,
            'writes_committed' => $created,
            'database_write_attempted' => false,
            'cms_write_attempted' => false,
            'content_authority_write_attempted' => $this->authorityWriteAttempted,
            'private_payload_read_attempted' => false,
            'attempt_or_report_accessed' => false,
            'activation_attempted' => false,
            'publish_attempted' => false,
            'indexability_attempted' => false,
            'sitemap_attempted' => false,
            'llms_attempted' => false,
            'search_submission_attempted' => false,
            'deploy_attempted' => false,
            'package' => $plan['package'],
            'approval' => [
                'approval_ref' => $approval['approval_ref'],
                'approval_sha256' => self::APPROVAL_SHA256,
                'subscope_id' => $approval['subscope_id'],
                'gate' => $approval['gate'],
                'verdict' => $approval['verdict'],
            ],
            'authority' => [
                'pack_id' => 'MBTI.global.en.default',
                'state' => 'inactive_draft',
                'authority_sha256' => $authoritySha256,
                'created_count' => $created ? 21 : 0,
                'preserved_count' => $created ? 24 : 46,
                'candidate_count' => 21,
                'active_pointer_changed' => false,
                'runtime_registered' => false,
                'local_path_in_receipt' => false,
                'reader_copy_in_receipt' => false,
            ],
            'row_count' => 46,
            'errors' => [],
            'warnings' => [],
        ];
    }

    public function authorityWriteAttempted(): bool
    {
        return $this->authorityWriteAttempted;
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

            $bytes = stream_get_contents($handle, $expectedBytes + 1);
            if ($bytes === false) {
                $this->fail('package_file_unreadable', 'A required exact-package file cannot be read safely.');
            }
            if (strlen($bytes) !== $expectedBytes) {
                $this->fail('package_file_size_changed', 'Exact-package file size changed during the bounded read.');
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

    /** @return array<string, mixed> */
    private function validatedApproval(string $path, string $confirmedSha256): array
    {
        if (strtolower(trim($confirmedSha256)) !== self::APPROVAL_SHA256) {
            $this->fail('confirmed_approval_sha256_mismatch', 'The confirmed approval SHA-256 is not the exact result draft-import approval.');
        }
        $bytes = $this->readExactFile($path, self::APPROVAL_BYTES, self::APPROVAL_SHA256, 'approval');
        $approval = $this->decodeJson($bytes);
        $expected = [
            'schema_version' => 'fermatmind.en_content_parity_controlled_transition_approval.v1',
            'approval_owner' => 'human_operator',
            'approval_ref' => self::APPROVAL_REF,
            'subscope_id' => 'W1-MBTI-RESULT-CONTENT',
            'package_sha256' => self::PACKAGE_SHA256,
            'gate' => 'draft_imported',
            'verdict' => 'APPROVED',
        ];
        foreach ($expected as $key => $value) {
            if (($approval[$key] ?? null) !== $value) {
                $this->fail('approval_contract_mismatch', 'The result draft-import approval identity or gate is invalid.');
            }
        }
        $permissions = $approval['permissions'] ?? null;
        $expectedPermissions = [
            'cms_write_authorized', 'staging_write_authorized', 'production_import_authorized',
            'public_release_authorized', 'seo_runtime_release_authorized', 'search_submission_authorized',
            'master_manifest_write_authorized',
        ];
        if (! is_array($permissions) || array_keys($permissions) !== $expectedPermissions) {
            $this->fail('approval_permissions_missing', 'The exact approval permissions contract is missing or reordered.');
        }
        foreach ($expectedPermissions as $permission) {
            if (($permissions[$permission] ?? null) !== false) {
                $this->fail('approval_permission_boundary_open', 'Every global approval permission must remain false.');
            }
        }

        return $approval;
    }

    private function validateInactiveAuthorityPrerequisite(string $authorityDirectory): void
    {
        if (is_link($authorityDirectory) || realpath($authorityDirectory) === false) {
            $this->fail('authority_directory_boundary_invalid', 'The inactive content-pack authority directory must be a real directory.');
        }
        foreach (self::AUTHORITY_FILES as $filename => [$bytes, $sha256]) {
            $this->readExactFile($authorityDirectory.'/'.$filename, $bytes, $sha256, 'authority');
        }
        $manifest = $this->decodeJson($this->readExactFile(
            $authorityDirectory.'/manifest.json', 742, self::AUTHORITY_FILES['manifest.json'][1], 'authority'
        ));
        if (($manifest['pack_id'] ?? null) !== 'MBTI.global.en.default'
            || ($manifest['region'] ?? null) !== 'GLOBAL'
            || ($manifest['locale'] ?? null) !== 'en'
            || ($manifest['lifecycle']['state'] ?? null) !== 'inactive_draft'
            || ($manifest['lifecycle']['runtime_available'] ?? null) !== false
            || ($manifest['lifecycle']['active_pointer_registered'] ?? null) !== false
            || ($manifest['fallback'] ?? null) !== []) {
            $this->fail('authority_prerequisite_mismatch', 'The exact inactive GLOBAL/en content-pack authority prerequisite is not intact.');
        }
    }

    private function writeAuthorityFile(string $bytes, string $authorityDirectory): bool
    {
        $draftDirectory = rtrim($authorityDirectory, DIRECTORY_SEPARATOR).'/drafts';
        if (is_link($draftDirectory)) {
            $this->fail('authority_draft_directory_symlink_rejected', 'The inactive draft directory must not be a symlink.');
        }
        if (! is_dir($draftDirectory)) {
            $this->authorityWriteAttempted = true;
            if (! mkdir($draftDirectory, 0755, false) && ! is_dir($draftDirectory)) {
                $this->fail('authority_draft_directory_create_failed', 'The inactive draft authority directory could not be created.');
            }
        }
        $target = $draftDirectory.'/en-parity-w1-mbti-result-content-v1.json';
        if (is_link($target)) {
            $this->fail('authority_target_symlink_rejected', 'The inactive draft authority target must not be a symlink.');
        }
        if (is_file($target)) {
            try {
                $this->readExactFile($target, strlen($bytes), hash('sha256', $bytes), 'authority_target');
            } catch (DomainException) {
                $this->fail('authority_target_collision', 'An existing inactive result authority differs from the exact approved package.');
            }

            return false;
        }

        $this->authorityWriteAttempted = true;
        $temporary = $draftDirectory.'/.result-content-'.bin2hex(random_bytes(12)).'.tmp';
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            $this->fail('authority_temporary_create_failed', 'The inactive authority temporary file could not be created.');
        }
        $writeComplete = false;
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                $this->fail('authority_write_failed', 'The inactive authority file could not be written completely.');
            }
            $writeComplete = true;
        } finally {
            fclose($handle);
            if (! $writeComplete) {
                @unlink($temporary);
            }
        }
        if (! link($temporary, $target)) {
            @unlink($temporary);
            $this->fail('authority_commit_failed', 'The inactive authority file could not be atomically committed.');
        }
        @unlink($temporary);

        return true;
    }

    private function readExactFile(string $path, int $expectedBytes, string $expectedSha256, string $kind): string
    {
        if (is_link($path)) {
            $this->fail($kind.'_file_symlink_rejected', 'Exact evidence files must not be symbolic links.');
        }
        $stat = @lstat($path);
        if ($stat === false || (($stat['mode'] ?? 0) & 0170000) !== 0100000 || ($stat['nlink'] ?? null) !== 1 || ($stat['size'] ?? null) !== $expectedBytes) {
            $this->fail($kind.'_file_identity_mismatch', 'An exact evidence file does not match its frozen identity.');
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->fail($kind.'_file_unreadable', 'An exact evidence file cannot be read safely.');
        }
        try {
            $opened = fstat($handle);
            $bytes = stream_get_contents($handle, $expectedBytes + 1);
            if ($opened === false || ($opened['dev'] ?? null) !== ($stat['dev'] ?? null) || ($opened['ino'] ?? null) !== ($stat['ino'] ?? null)
                || ! is_string($bytes) || strlen($bytes) !== $expectedBytes || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
                $this->fail($kind.'_file_sha256_mismatch', 'An exact evidence file no longer matches its approved bytes.');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    private function canonicalJson(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function assertPrivateFieldsExcluded(array $value): void
    {
        $forbidden = ['attempt_id', 'report_token', 'result_lookup_token', 'share_token', 'user_id', 'account_id', 'email', 'phone', 'user_scores', 'raw_scores', 'answers', 'orders', 'payments', 'recovery_data', 'secret', 'authorization'];
        $walk = function (array $nested) use (&$walk, $forbidden): void {
            foreach ($nested as $key => $child) {
                if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                    $this->fail('private_field_present', 'A private result, identity, payment, or recovery field is present.');
                }
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($value);
    }

    private function assertWriteEnvironmentAllowed(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->fail('environment_write_not_authorized', 'This approval does not authorize staging or production authority execution.');
        }
    }

    private function fail(string $code, string $message): never
    {
        throw new DomainException($code.': '.$message);
    }
}
