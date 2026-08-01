<?php

declare(strict_types=1);

namespace App\Services\ContentImport;

use App\Models\MbtiCrossTypeComparisonAuthority;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MbtiComparisonEnglishPackageImporter
{
    public const PACKAGE_SHA256 = 'deecc8175fb43ba3730d6513b496a0ab6834459108e3b24e25550bbf40e001a2';

    public const MANIFEST_SHA256 = 'dcdd1a20448301c5cd00667727e6d4be7bf5090efd5ce5cf90a192a0224021ba';

    public const PACKAGE_ID = 'EN-PARITY-W1-MBTI-COMPARISON-ASSETS-W9-CORRECTION-07-2026-07-31';

    public const INVENTORY_PACKAGE_SHA256 = '8079465c6ec26820c99ca2be3f08346674e90509dee6d84fd610d5c6bbac2b85';

    public const APPROVAL_SHA256 = '42853f27ff4e921f0d91e8e50210620dd212fddf6fab7763ae82544087d02a8b';

    public const APPROVAL_REF = 'human-operator:w1-mbti-comparisons-draft-import:2026-08-01';

    private const APPROVAL_BYTES = 917;

    private const EXPECTED_FILE_BYTES = [
        'package_manifest.json' => 2626,
        'README.md' => 2333,
        'assets.json' => 115899,
        'translation_map.json' => 28014,
        'claim_boundary_report.json' => 5005,
        'editorial_review.json' => 6787,
    ];

    /**
     * @var list<string>
     */
    public const EXACT_SLUGS = [
        'enfp-vs-entp',
        'entj-vs-intj',
        'estj-vs-entj',
        'infj-vs-infp',
        'intj-vs-intp',
        'isfp-vs-infp',
        'istj-vs-isfj',
    ];

    public static function defaultPackageDirectory(): string
    {
        return base_path('content_assets/en-content-parity/W1-mbti/comparisons/w9-correction-deecc817');
    }

    public static function defaultApprovalPath(): string
    {
        return base_path('content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-COMPARISONS/draft-import-approval-2026-08-01.json');
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(string $packageDirectory, string $confirmedPackageSha256): array
    {
        return $this->validatedBundle($packageDirectory, $confirmedPackageSha256)['summary'];
    }

    /**
     * @return array<string, mixed>
     */
    public function importDraft(
        string $packageDirectory,
        string $confirmedPackageSha256,
        string $approvalPath,
        string $confirmedApprovalSha256,
    ): array {
        return DB::transaction(function () use (
            $packageDirectory,
            $confirmedPackageSha256,
            $approvalPath,
            $confirmedApprovalSha256,
        ): array {
            $bundle = $this->validatedBundle($packageDirectory, $confirmedPackageSha256);
            $approval = $this->validatedApproval($approvalPath, $confirmedApprovalSha256);
            $rows = [];

            foreach ($bundle['assets'] as $asset) {
                $payload = $asset['payload'];
                $slug = (string) $payload['comparison_slug'];
                $existing = MbtiCrossTypeComparisonAuthority::query()
                    ->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('locale', 'en')
                    ->where('slug', $slug)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof MbtiCrossTypeComparisonAuthority) {
                    $this->assertExistingTargetIsSafeDraft($existing);
                }

                $contentSha256 = $this->payloadSha256($payload);
                MbtiCrossTypeComparisonAuthority::query()
                    ->withoutGlobalScopes()
                    ->updateOrCreate(
                        ['org_id' => 0, 'locale' => 'en', 'slug' => $slug],
                        [
                            'comparison_type' => MbtiCrossTypeComparisonAuthority::COMPARISON_TYPE,
                            'left_type_code' => (string) $payload['left_type'],
                            'right_type_code' => (string) $payload['right_type'],
                            'title' => (string) $payload['title'],
                            'seo_title' => (string) $payload['seo_title'],
                            'seo_description' => (string) $payload['seo_description'],
                            'summary' => (string) $payload['summary'],
                            'content_payload_json' => $payload,
                            'claim_boundary' => (string) $payload['claim_boundary'],
                            'source_package_id' => self::PACKAGE_ID,
                            'source_sha256' => $contentSha256,
                            'authority_contract_version' => MbtiCrossTypeComparisonAuthority::AUTHORITY_CONTRACT_VERSION,
                            'readmodel_contract_version' => MbtiCrossTypeComparisonAuthority::READMODEL_CONTRACT_VERSION,
                            'review_status' => 'w9_passed_pending_editorial',
                            'publish_status' => 'draft',
                            'indexability_status' => 'blocked',
                            'is_public' => false,
                            'is_indexable' => false,
                            'sitemap_eligible' => false,
                            'llms_eligible' => false,
                            'search_submission_eligible' => false,
                            'published_at' => null,
                            'imported_at' => now(),
                        ],
                    );

                $rows[] = [
                    'slug' => $slug,
                    'action' => $existing instanceof MbtiCrossTypeComparisonAuthority ? 'updated_exact_inactive_draft' : 'created_inactive_draft',
                    'content_sha256' => $contentSha256,
                ];
            }

            $this->assertExactInactiveDraftReadback($rows);

            return [
                'artifact' => 'EN-PARITY-W1-MBTI-COMPARISON-DRAFT-IMPORT-RECEIPT',
                'schema_version' => 'fermatmind.en_parity.comparison_draft_import_receipt.v1',
                'status' => 'pass',
                'ok' => true,
                'mode' => 'write_inactive_draft',
                'dry_run_only' => false,
                'write_supported_in_this_pr' => true,
                'writes_committed' => true,
                'database_write_attempted' => true,
                'cms_write_attempted' => true,
                'publish_attempted' => false,
                'activation_attempted' => false,
                'indexability_attempted' => false,
                'sitemap_attempted' => false,
                'llms_attempted' => false,
                'search_submission_attempted' => false,
                'deploy_attempted' => false,
                'package' => $bundle['summary']['package'],
                'approval' => [
                    'approval_ref' => $approval['approval_ref'],
                    'approval_sha256' => self::APPROVAL_SHA256,
                    'subscope_id' => $approval['subscope_id'],
                    'gate' => $approval['gate'],
                    'verdict' => $approval['verdict'],
                ],
                'row_count' => count($rows),
                'created_count' => count(array_filter($rows, static fn (array $row): bool => $row['action'] === 'created_inactive_draft')),
                'updated_count' => count(array_filter($rows, static fn (array $row): bool => $row['action'] === 'updated_exact_inactive_draft')),
                'rows' => $rows,
                'readback' => [
                    'exact_row_count' => count($rows),
                    'english_draft_only' => true,
                    'public_row_count' => 0,
                    'indexable_row_count' => 0,
                    'sitemap_eligible_row_count' => 0,
                    'llms_eligible_row_count' => 0,
                    'search_submission_eligible_row_count' => 0,
                ],
                'errors' => [],
                'warnings' => [],
            ];
        }, 3);
    }

    /**
     * @return array{summary: array<string, mixed>, assets: list<array<string, mixed>>}
     */
    private function validatedBundle(string $packageDirectory, string $confirmedPackageSha256): array
    {
        $confirmedPackageSha256 = strtolower(trim($confirmedPackageSha256));
        if ($confirmedPackageSha256 !== self::PACKAGE_SHA256) {
            $this->fail('confirmed_package_sha256_mismatch', 'The confirmed package SHA-256 is not the frozen W1 comparison package.');
        }

        $manifestBytes = $this->readPackageFile($packageDirectory, 'package_manifest.json');
        $manifest = $this->decodeJson($manifestBytes);
        $validatedPackage = $this->validateManifestAndReadPackageFiles($packageDirectory, $manifest, $manifestBytes);
        $computedPackageSha256 = $validatedPackage['package_sha256'];

        if ($computedPackageSha256 !== self::PACKAGE_SHA256) {
            $this->fail('computed_package_sha256_mismatch', 'The package file chain does not reproduce the frozen W1 comparison package SHA-256.');
        }

        $assetsBytes = $validatedPackage['files']['assets.json'] ?? null;
        if (! is_string($assetsBytes)) {
            $this->fail('assets_file_not_declared', 'The frozen package manifest must declare assets.json.');
        }
        $assetsDocument = $this->decodeJson($assetsBytes);
        $this->validateTopLevelContracts($manifest, $assetsDocument);
        $rowPlans = $this->buildRowPlans($assetsDocument);

        $summary = [
            'artifact' => 'EN-PARITY-W1-MBTI-COMPARISON-IMPORTER-DRY-RUN-RECEIPT',
            'schema_version' => 'fermatmind.en_parity.comparison_import_dry_run_receipt.v1',
            'status' => 'pass',
            'ok' => true,
            'mode' => 'dry_run',
            'dry_run_only' => true,
            'write_supported_in_this_pr' => false,
            'writes_committed' => false,
            'database_write_attempted' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'activation_attempted' => false,
            'indexability_attempted' => false,
            'search_submission_attempted' => false,
            'package' => [
                'package_id' => self::PACKAGE_ID,
                'package_sha256' => self::PACKAGE_SHA256,
                'inventory_package_sha256' => self::INVENTORY_PACKAGE_SHA256,
                'asset_count' => 7,
                'source' => 'repository_frozen_w1_mbti_comparison_package',
                'reader_copy_in_receipt' => false,
                'local_path_in_receipt' => false,
            ],
            'target_contract' => [
                'authority' => 'MbtiCrossTypeComparisonAuthority',
                'table' => 'mbti_cross_type_comparison_authorities',
                'org_id' => 0,
                'target_locale' => 'en',
                'source_locale' => 'zh-CN',
                'comparison_type' => 'mbti_cross_type',
                'target_state' => 'inactive_draft',
                'source_locale_overwrite_allowed' => false,
                'public_release_allowed' => false,
                'indexability_release_allowed' => false,
            ],
            'replay_contract' => [
                'deterministic' => true,
                'identity_fields' => ['org_id', 'locale', 'slug'],
                'same_package_same_plan' => true,
                'duplicate_row_creation_allowed' => false,
                'write_execution_deferred_to_pr' => 'EN-PARITY-W1-MBTI-COMPARISON-DRAFT-IMPORT-01',
            ],
            'row_count' => count($rowPlans),
            'rows' => $rowPlans,
            'errors' => [],
            'warnings' => [],
        ];

        return [
            'summary' => $summary,
            'assets' => array_values($assetsDocument['assets']),
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validateManifestAndReadPackageFiles(string $packageDirectory, array $manifest, string $manifestBytes): array
    {
        if (! hash_equals(self::MANIFEST_SHA256, hash('sha256', $manifestBytes))) {
            $this->fail('manifest_sha256_mismatch', 'The package manifest bytes do not match the frozen W1 comparison manifest.');
        }

        if (($manifest['package_sha256'] ?? null) !== self::PACKAGE_SHA256) {
            $this->fail('manifest_package_sha256_mismatch', 'The manifest does not name the frozen W1 comparison package SHA-256.');
        }

        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === []) {
            $this->fail('manifest_files_missing', 'The package manifest file chain is missing.');
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
            $actualSha256 = hash('sha256', $fileBytes);
            if (! hash_equals($expectedSha256, $actualSha256)) {
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
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $assetsDocument
     */
    private function validateTopLevelContracts(array $manifest, array $assetsDocument): void
    {
        $expectedManifest = [
            'package_id' => self::PACKAGE_ID,
            'inventory_package_sha256' => self::INVENTORY_PACKAGE_SHA256,
            'status' => 'unpublished_candidate',
            'asset_count' => 7,
        ];
        foreach ($expectedManifest as $field => $expected) {
            if (($manifest[$field] ?? null) !== $expected) {
                $this->fail('manifest_contract_mismatch', 'The frozen manifest identity or lifecycle contract is invalid.');
            }
        }

        $manifestSlugs = $manifest['exact_slugs'] ?? null;
        if (! is_array($manifestSlugs) || array_values($manifestSlugs) !== self::EXACT_SLUGS) {
            $this->fail('manifest_slug_cohort_mismatch', 'The manifest must retain the exact ordered seven-slug cohort.');
        }

        $expectedAssets = [
            'package_id' => self::PACKAGE_ID,
            'inventory_package_sha256' => self::INVENTORY_PACKAGE_SHA256,
            'status' => 'unpublished_candidate',
            'asset_count' => 7,
        ];
        foreach ($expectedAssets as $field => $expected) {
            if (($assetsDocument[$field] ?? null) !== $expected) {
                $this->fail('assets_contract_mismatch', 'The assets document identity or lifecycle contract is invalid.');
            }
        }

        foreach (['cms_write_authorized', 'database_write_authorized', 'draft_import_authorized', 'production_import_authorized', 'publication_authorized', 'activation_authorized', 'seo_runtime_release_authorized', 'indexability_authorized', 'search_submission_authorized', 'deploy_authorized'] as $permission) {
            if (($manifest['permissions'][$permission] ?? null) !== false || ($assetsDocument['permissions'][$permission] ?? null) !== false) {
                $this->fail('package_permission_open', 'Every controlled package permission must remain false in the dry-run importer PR.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $assetsDocument
     * @return list<array<string, mixed>>
     */
    private function buildRowPlans(array $assetsDocument): array
    {
        $assets = $assetsDocument['assets'] ?? null;
        if (! is_array($assets) || count($assets) !== 7) {
            $this->fail('asset_count_mismatch', 'The exact package must contain seven comparison assets.');
        }

        $plans = [];
        $seenSlugs = [];
        $seenIdentities = [];
        foreach (array_values($assets) as $position => $asset) {
            if (! is_array($asset)) {
                $this->fail('asset_not_object', 'Every comparison asset must be an object.');
            }

            $payload = $asset['payload'] ?? null;
            $source = $asset['source'] ?? null;
            $publication = $asset['publication'] ?? null;
            if (! is_array($payload) || ! is_array($source) || ! is_array($publication)) {
                $this->fail('asset_contract_missing', 'Every comparison asset must include payload, source, and publication contracts.');
            }

            $slug = strtolower(trim((string) ($payload['comparison_slug'] ?? '')));
            $leftType = strtoupper(trim((string) ($payload['left_type'] ?? '')));
            $rightType = strtoupper(trim((string) ($payload['right_type'] ?? '')));
            $expectedSlug = self::EXACT_SLUGS[$position];
            if ($slug !== $expectedSlug || $slug !== strtolower($leftType.'-vs-'.$rightType)) {
                $this->fail('asset_slug_identity_mismatch', 'Comparison slug order and type identity must match the frozen cohort.');
            }
            if (isset($seenSlugs[$slug])) {
                $this->fail('asset_slug_duplicate', 'Comparison slugs must be unique.');
            }

            $rowId = trim((string) ($asset['row_id'] ?? ''));
            $stableIdentity = trim((string) ($asset['target_stable_asset_identity'] ?? ''));
            $translationIdentity = trim((string) ($asset['translation_pair_identity'] ?? ''));
            if ($rowId === '' || $stableIdentity !== 'mbti_cross_type_comparison_authorities:org0:en:'.$slug) {
                $this->fail('asset_target_identity_mismatch', 'Target stable identity must bind org 0, locale en, and the exact slug.');
            }
            if ($translationIdentity !== 'cohort:mbti:cross-type-comparison:'.$slug) {
                $this->fail('translation_pair_identity_mismatch', 'Translation pairing must bind the exact comparison slug.');
            }
            if (isset($seenIdentities[$stableIdentity])) {
                $this->fail('asset_identity_duplicate', 'Target stable identities must be unique.');
            }

            if (($source['locale'] ?? null) !== 'zh-CN' || ($source['authority'] ?? null) !== 'MbtiCrossTypeComparisonAuthority') {
                $this->fail('source_locale_authority_mismatch', 'Every source must be the zh-CN comparison authority.');
            }
            if (($payload['locale'] ?? null) !== 'en'
                || ($payload['comparison_type'] ?? null) !== 'mbti_cross_type'
                || ($payload['scale_code'] ?? null) !== 'MBTI'
                || ($payload['public_route_type'] ?? null) !== 'cross-type-comparison') {
                $this->fail('target_payload_contract_mismatch', 'Every target must be an English MBTI cross-type comparison.');
            }
            if (($payload['canonical_url'] ?? null) !== 'https://fermatmind.com/en/personality/'.$slug) {
                $this->fail('canonical_url_mismatch', 'Every target canonical must match its exact English public route.');
            }
            $this->assertPrivateFieldsExcluded($payload);
            if (($publication['status'] ?? null) !== 'unpublished_candidate'
                || ($publication['review_status'] ?? null) !== 'pending_independent_w9'
                || ($publication['indexability_status'] ?? null) !== 'blocked') {
                $this->fail('publication_gate_mismatch', 'Every target must remain an unpublished, W9-pending, indexability-blocked candidate.');
            }

            $plans[] = [
                'position' => $position + 1,
                'row_id' => $rowId,
                'translation_pair_identity' => $translationIdentity,
                'source' => [
                    'authority' => 'MbtiCrossTypeComparisonAuthority',
                    'lookup' => ['org_id' => 0, 'locale' => 'zh-CN', 'slug' => $slug],
                    'read_only' => true,
                    'overwrite_allowed' => false,
                ],
                'target' => [
                    'authority' => 'MbtiCrossTypeComparisonAuthority',
                    'lookup' => ['org_id' => 0, 'locale' => 'en', 'slug' => $slug],
                    'stable_asset_identity' => $stableIdentity,
                    'left_type_code' => $leftType,
                    'right_type_code' => $rightType,
                    'comparison_type' => 'mbti_cross_type',
                ],
                'locale_pairing' => [
                    'source_locale' => 'zh-CN',
                    'target_locale' => 'en',
                    'pairing_key' => $slug,
                    'deterministic' => true,
                ],
                'planned_state' => [
                    'review_status' => 'pending_independent_w9',
                    'publish_status' => 'draft',
                    'indexability_status' => 'blocked',
                    'is_public' => false,
                    'is_indexable' => false,
                    'sitemap_eligible' => false,
                    'llms_eligible' => false,
                    'search_submission_eligible' => false,
                    'published_at' => null,
                ],
                'idempotency_key' => 'mbti-comparison:org0:en:'.$slug,
                'action' => 'would_upsert_inactive_draft_en_target',
                'reader_copy_in_plan' => false,
                'write_executed' => false,
            ];

            $seenSlugs[$slug] = true;
            $seenIdentities[$stableIdentity] = true;
        }

        if (array_keys($seenSlugs) !== self::EXACT_SLUGS) {
            $this->fail('asset_slug_cohort_mismatch', 'The asset rows must retain the exact ordered seven-slug cohort.');
        }

        return $plans;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedApproval(string $approvalPath, string $confirmedApprovalSha256): array
    {
        $confirmedApprovalSha256 = strtolower(trim($confirmedApprovalSha256));
        if ($confirmedApprovalSha256 !== self::APPROVAL_SHA256) {
            $this->fail('confirmed_approval_sha256_mismatch', 'The confirmed approval SHA-256 is not the exact CONTROL artifact.');
        }

        $approvalBytes = $this->readExactFile($approvalPath, self::APPROVAL_BYTES, null, 'approval');
        if (! hash_equals(self::APPROVAL_SHA256, hash('sha256', $approvalBytes))) {
            $this->fail('approval_sha256_mismatch', 'The CONTROL approval artifact bytes do not match the authorized SHA-256.');
        }
        $approval = $this->decodeJson($approvalBytes);
        $expected = [
            'artifact_kind' => 'controlled_transition_approval',
            'schema_version' => 'fermatmind.en_content_parity_controlled_transition_approval.v1',
            'control_id' => 'EN-PARITY-CONTROL-BOOTSTRAP-01',
            'approval_owner' => 'human_operator',
            'approval_ref' => self::APPROVAL_REF,
            'producer_lane_id' => 'W1',
            'subscope_id' => 'W1-MBTI-COMPARISONS',
            'package_sha256' => self::PACKAGE_SHA256,
            'gate' => 'draft_imported',
            'verdict' => 'APPROVED',
        ];
        foreach ($expected as $field => $value) {
            if (($approval[$field] ?? null) !== $value) {
                $this->fail('approval_contract_mismatch', 'The CONTROL approval identity or gate contract is invalid.');
            }
        }

        $permissions = $approval['permissions'] ?? null;
        if (! is_array($permissions)) {
            $this->fail('approval_permissions_missing', 'The CONTROL approval permissions contract is missing.');
        }
        foreach ([
            'cms_write_authorized',
            'staging_write_authorized',
            'production_import_authorized',
            'public_release_authorized',
            'seo_runtime_release_authorized',
            'search_submission_authorized',
            'master_manifest_write_authorized',
        ] as $permission) {
            if (($permissions[$permission] ?? null) !== false) {
                $this->fail('approval_permission_boundary_open', 'The approval artifact must retain every global permission as false.');
            }
        }

        return $approval;
    }

    private function assertExistingTargetIsSafeDraft(MbtiCrossTypeComparisonAuthority $authority): void
    {
        if ((int) $authority->org_id !== 0
            || (string) $authority->locale !== 'en'
            || (string) $authority->source_package_id !== self::PACKAGE_ID
            || (string) $authority->publish_status !== 'draft'
            || (bool) $authority->is_public
            || (bool) $authority->is_indexable
            || (bool) $authority->sitemap_eligible
            || (bool) $authority->llms_eligible
            || (bool) $authority->search_submission_eligible
            || $authority->published_at !== null) {
            $this->fail('existing_target_collision', 'An English target identity is not an exact-package inactive draft.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertExactInactiveDraftReadback(array $rows): void
    {
        if (count($rows) !== 7 || array_column($rows, 'slug') !== self::EXACT_SLUGS) {
            $this->fail('draft_import_row_cohort_mismatch', 'The write result does not retain the exact seven-row cohort.');
        }

        $authorities = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->whereIn('slug', self::EXACT_SLUGS)
            ->orderBy('slug')
            ->get();
        if ($authorities->count() !== 7) {
            $this->fail('draft_import_readback_count_mismatch', 'The exact seven English draft rows were not readable after write.');
        }

        foreach ($authorities as $authority) {
            if (! $authority instanceof MbtiCrossTypeComparisonAuthority) {
                $this->fail('draft_import_readback_type_mismatch', 'An imported authority row has an unexpected type.');
            }
            $this->assertExistingTargetIsSafeDraft($authority);
            if ((string) $authority->review_status !== 'w9_passed_pending_editorial'
                || (string) $authority->indexability_status !== 'blocked') {
                $this->fail('draft_import_readback_state_mismatch', 'An imported authority row escaped the approved draft-only state.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadSha256(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertPrivateFieldsExcluded(array $payload): void
    {
        $forbidden = [
            'attempt', 'attempt_id', 'report', 'report_id', 'order', 'order_id', 'payment', 'payment_id',
            'account', 'account_id', 'answers', 'scores', 'recovery', 'recovery_token', 'private_url',
        ];
        $walk = function (array $value) use (&$walk, $forbidden): void {
            foreach ($value as $key => $nested) {
                if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                    $this->fail('private_field_present', 'A private result, order, payment, account, or recovery field is present.');
                }
                if (is_array($nested)) {
                    $walk($nested);
                }
            }
        };
        $walk($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function readPackageFile(string $packageDirectory, string $filename): string
    {
        $expectedBytes = self::EXPECTED_FILE_BYTES[$filename] ?? null;
        if (! is_int($expectedBytes)) {
            $this->fail('package_file_not_allowlisted', 'Only frozen exact-package filenames may be read.');
        }

        $path = rtrim($packageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$filename;

        return $this->readExactFile($path, $expectedBytes, $packageDirectory, 'package');
    }

    private function readExactFile(string $path, int $expectedBytes, ?string $boundaryDirectory, string $kind): string
    {
        if (is_link($path)) {
            $this->fail($kind.'_file_symlink_rejected', 'Exact evidence files must not be symbolic links.');
        }

        $linkStat = @lstat($path);
        if ($linkStat === false || (($linkStat['mode'] ?? 0) & 0170000) !== 0100000) {
            $this->fail($kind.'_file_missing', 'A required exact evidence file is missing or is not a regular file.');
        }
        if (($linkStat['nlink'] ?? null) !== 1) {
            $this->fail($kind.'_file_hardlink_rejected', 'Exact evidence files must have exactly one filesystem link.');
        }
        if (($linkStat['size'] ?? null) !== $expectedBytes) {
            $this->fail($kind.'_file_size_mismatch', 'An exact evidence file does not match its frozen byte length.');
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            $this->fail($kind.'_file_boundary_invalid', 'An exact evidence file does not resolve safely.');
        }
        if ($boundaryDirectory !== null) {
            $resolvedDirectory = realpath($boundaryDirectory);
            if ($resolvedDirectory === false
                || ! str_starts_with($resolvedPath, rtrim($resolvedDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                $this->fail($kind.'_file_boundary_invalid', 'Exact-package files must resolve inside the selected package directory.');
            }
        }

        $handle = @fopen($resolvedPath, 'rb');
        if ($handle === false) {
            $this->fail($kind.'_file_unreadable', 'An exact evidence file cannot be opened safely.');
        }

        try {
            $openedStat = fstat($handle);
            if ($openedStat === false
                || (($openedStat['mode'] ?? 0) & 0170000) !== 0100000
                || ($openedStat['nlink'] ?? null) !== 1
                || ($openedStat['size'] ?? null) !== $expectedBytes
                || ($openedStat['dev'] ?? null) !== ($linkStat['dev'] ?? null)
                || ($openedStat['ino'] ?? null) !== ($linkStat['ino'] ?? null)) {
                $this->fail($kind.'_file_identity_changed', 'Exact evidence file identity changed before the safe read.');
            }

            $bytes = stream_get_contents($handle, $expectedBytes + 1);
            if ($bytes === false) {
                $this->fail($kind.'_file_unreadable', 'An exact evidence file cannot be read safely.');
            }
            if (strlen($bytes) !== $expectedBytes) {
                $this->fail($kind.'_file_size_changed', 'Exact evidence file size changed during the bounded read.');
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
