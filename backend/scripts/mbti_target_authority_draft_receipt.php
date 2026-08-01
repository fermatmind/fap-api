<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const COMPARISON_PACKAGE_SHA256 = 'deecc8175fb43ba3730d6513b496a0ab6834459108e3b24e25550bbf40e001a2';
const COMPARISON_MANIFEST_SHA256 = 'dcdd1a20448301c5cd00667727e6d4be7bf5090efd5ce5cf90a192a0224021ba';
const COMPARISON_APPROVAL_SHA256 = '5455bc63ea094bb2adb11576d29a67f812a9189b6ddeadcebd0c20ac2dc5b5d6';
const RESULT_PACKAGE_SHA256 = '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3';
const RESULT_MANIFEST_SHA256 = '43f646a288c46b698d49f102eb7e7b611b66148f74cd459bd61ea9826d7c8bac';
const RESULT_APPROVAL_SHA256 = 'ba793884a5517f1194edab787c99a5be5159a2660954a15deb6cf0659544fa40';

set_exception_handler(static function (Throwable $throwable): never {
    fwrite(STDERR, "target_authority_receipt_failed\n");
    exit(1);
});

$options = getopt('', ['backend-root:', 'source-backend-root:', 'receipt-dir:', 'control-plane-sha:', 'active-revision:']);
foreach (['backend-root', 'source-backend-root', 'receipt-dir', 'control-plane-sha', 'active-revision'] as $required) {
    if (! isset($options[$required]) || ! is_string($options[$required]) || trim($options[$required]) === '') {
        throw new RuntimeException('missing_required_option: '.$required);
    }
}
if (preg_match('/\A[a-f0-9]{40}\z/', $options['control-plane-sha']) !== 1
    || preg_match('/\A[a-f0-9]{40}\z/', $options['active-revision']) !== 1) {
    throw new RuntimeException('invalid_sha_option');
}

$backendRoot = rtrim($options['backend-root'], '/');
$sourceBackendRoot = rtrim($options['source-backend-root'], '/');
$receiptDir = rtrim($options['receipt-dir'], '/');
if (! is_file($backendRoot.'/artisan') || ! is_file($backendRoot.'/vendor/autoload.php')) {
    throw new RuntimeException('authority_backend_not_bootstrapable');
}
if (! is_dir($sourceBackendRoot) || ! is_dir($receiptDir) && ! mkdir($receiptDir, 0700, true) && ! is_dir($receiptDir)) {
    throw new RuntimeException('source_or_receipt_directory_invalid');
}

require $backendRoot.'/vendor/autoload.php';
$app = require $backendRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @return array<string,mixed> */
function exactPackage(string $directory, string $manifestHash, string $packageHash): array
{
    $manifestPath = $directory.'/package_manifest.json';
    $bytes = exactFile($manifestPath);
    if (! hash_equals($manifestHash, hash('sha256', $bytes))) {
        throw new RuntimeException('package_manifest_sha256_mismatch');
    }
    $manifest = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ($manifest['package_sha256'] ?? null) !== $packageHash || ! is_array($manifest['files'] ?? null)) {
        throw new RuntimeException('package_manifest_contract_mismatch');
    }
    $chain = '';
    $files = [];
    foreach ($manifest['files'] as $entry) {
        if (! is_array($entry)) {
            throw new RuntimeException('package_file_entry_invalid');
        }
        $path = (string) ($entry['path'] ?? '');
        $expected = (string) ($entry['sha256'] ?? '');
        if ($path === '' || basename($path) !== $path || str_contains($path, '..') || preg_match('/\A[a-f0-9]{64}\z/', $expected) !== 1) {
            throw new RuntimeException('package_file_path_or_hash_invalid');
        }
        $content = exactFile($directory.'/'.$path);
        if (! hash_equals($expected, hash('sha256', $content))) {
            throw new RuntimeException('package_file_sha256_mismatch');
        }
        $chain .= $path."\0".$expected."\n";
        $files[$path] = $content;
    }
    if (! hash_equals($packageHash, hash('sha256', $chain))) {
        throw new RuntimeException('package_chain_sha256_mismatch');
    }

    return ['manifest' => $manifest, 'files' => $files];
}

function exactFile(string $path): string
{
    if (! is_file($path) || is_link($path)) {
        throw new RuntimeException('untrusted_input_file');
    }
    $bytes = file_get_contents($path);
    if (! is_string($bytes)) {
        throw new RuntimeException('input_file_unreadable');
    }

    return $bytes;
}

/** @return array<string,mixed> */
function exactApproval(string $path, string $expectedHash, string $subscopeId, string $packageHash): array
{
    $bytes = exactFile($path);
    if (! hash_equals($expectedHash, hash('sha256', $bytes))) {
        throw new RuntimeException('approval_sha256_mismatch');
    }
    $approval = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($approval)
        || ($approval['artifact_kind'] ?? null) !== 'controlled_target_authority_receipt_approval'
        || ($approval['schema_version'] ?? null) !== 'fermatmind.en_content_parity_controlled_target_authority_receipt_approval.v1'
        || ($approval['control_id'] ?? null) !== 'EN-PARITY-W1-MBTI-TARGET-AUTHORITY-RECEIPT-01'
        || ($approval['approval_owner'] ?? null) !== 'human_operator'
        || ($approval['subscope_id'] ?? null) !== $subscopeId
        || ($approval['package_sha256'] ?? null) !== $packageHash
        || ($approval['gate'] ?? null) !== 'target_authority_draft_receipt'
        || ($approval['verdict'] ?? null) !== 'APPROVED') {
        throw new RuntimeException('approval_contract_mismatch');
    }
    $permissions = $approval['permissions'] ?? null;
    if (! is_array($permissions)
        || ($permissions['target_authority_draft_write_authorized'] ?? null) !== true
        || ($permissions['target_authority_readback_authorized'] ?? null) !== true) {
        throw new RuntimeException('approval_write_permission_missing');
    }
    foreach (['publish_authorized', 'activation_authorized', 'active_pointer_authorized', 'indexability_authorized', 'sitemap_authorized', 'hreflang_authorized', 'llms_authorized', 'json_ld_authorized', 'search_submission_authorized', 'deployment_authorized', 'private_result_read_authorized', 'attempt_report_order_payment_read_authorized'] as $denied) {
        if (($permissions[$denied] ?? null) !== false) {
            throw new RuntimeException('approval_permission_boundary_open');
        }
    }

    return $approval;
}

function assertNoPrivateKeys(mixed $value): void
{
    $forbidden = array_fill_keys(['attempt_id', 'attempt_uuid', 'report_token', 'result_lookup_token', 'share_token', 'user_id', 'account_id', 'email', 'phone', 'user_scores', 'raw_scores', 'answers', 'orders', 'payments', 'recovery_data', 'secret', 'authorization'], true);
    $walk = static function (mixed $node) use (&$walk, $forbidden): void {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $child) {
            if (is_string($key) && isset($forbidden[strtolower($key)])) {
                throw new RuntimeException('private_field_present');
            }
            $walk($child);
        }
    };
    $walk($value);
}

/** @return array<string,mixed> */
function comparisonReceipt(array $bundle, array $approval, string $controlPlaneSha, string $activeRevision): array
{
    $assets = json_decode((string) ($bundle['files']['assets.json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $expectedSlugs = ['enfp-vs-entp', 'entj-vs-intj', 'estj-vs-entj', 'infj-vs-infp', 'intj-vs-intp', 'isfp-vs-infp', 'istj-vs-isfj'];
    if (! is_array($assets) || ($assets['asset_count'] ?? null) !== 7 || ! is_array($assets['assets'] ?? null) || count($assets['assets']) !== 7) {
        throw new RuntimeException('comparison_cohort_mismatch');
    }
    $rows = [];
    DB::transaction(function () use ($assets, $expectedSlugs, &$rows): void {
        foreach (array_values($assets['assets']) as $position => $asset) {
            $payload = is_array($asset) ? ($asset['payload'] ?? null) : null;
            if (! is_array($payload)) {
                throw new RuntimeException('comparison_payload_invalid');
            }
            assertNoPrivateKeys($payload);
            $slug = strtolower(trim((string) ($payload['comparison_slug'] ?? '')));
            if ($slug !== $expectedSlugs[$position] || ($payload['locale'] ?? null) !== 'en') {
                throw new RuntimeException('comparison_identity_mismatch');
            }
            $contentSha = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $existing = DB::table('mbti_cross_type_comparison_authorities')
                ->where(['org_id' => 0, 'locale' => 'en', 'slug' => $slug])
                ->lockForUpdate()->first();
            $values = [
                'comparison_type' => 'mbti_cross_type', 'left_type_code' => strtoupper((string) $payload['left_type']), 'right_type_code' => strtoupper((string) $payload['right_type']),
                'title' => (string) $payload['title'], 'seo_title' => (string) $payload['seo_title'], 'seo_description' => (string) $payload['seo_description'], 'summary' => (string) $payload['summary'],
                'content_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'claim_boundary' => (string) $payload['claim_boundary'],
                'source_package_id' => 'EN-PARITY-W1-MBTI-COMPARISON-ASSETS-W9-CORRECTION-07-2026-07-31', 'source_sha256' => $contentSha,
                'authority_contract_version' => 'mbti.cross_type_comparison.authority.v1', 'readmodel_contract_version' => 'mbti.cross_type_comparison.readmodel.v1',
                'review_status' => 'w9_passed_pending_editorial', 'publish_status' => 'draft', 'indexability_status' => 'blocked',
                'is_public' => false, 'is_indexable' => false, 'sitemap_eligible' => false, 'llms_eligible' => false, 'search_submission_eligible' => false, 'published_at' => null,
            ];
            $action = 'preserved_exact_inactive_draft';
            if ($existing === null) {
                DB::table('mbti_cross_type_comparison_authorities')->insert($values + ['org_id' => 0, 'locale' => 'en', 'slug' => $slug, 'imported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $action = 'created_exact_inactive_draft';
            } elseif ((string) $existing->source_sha256 !== $contentSha || (string) $existing->publish_status !== 'draft' || (bool) $existing->is_public || (bool) $existing->is_indexable || (bool) $existing->sitemap_eligible || (bool) $existing->llms_eligible || (bool) $existing->search_submission_eligible || $existing->published_at !== null) {
                throw new RuntimeException('comparison_existing_target_collision');
            }
            $rows[] = ['target_identity' => 'mbti_cross_type_comparison_authorities:org0:en:'.$slug, 'slug' => $slug, 'content_sha256' => $contentSha, 'action' => $action];
        }
    }, 3);
    if (array_column($rows, 'slug') !== $expectedSlugs || DB::table('mbti_cross_type_comparison_authorities')->where('org_id', 0)->where('locale', 'en')->whereIn('slug', $expectedSlugs)->count() !== 7) {
        throw new RuntimeException('comparison_readback_failed');
    }

    return ['schema_version' => 'fermatmind.en_parity.target_authority_draft_receipt.v1', 'subscope_id' => 'W1-MBTI-COMPARISONS', 'status' => 'PASS', 'control_plane_sha' => $controlPlaneSha, 'active_revision' => $activeRevision, 'package_sha256' => COMPARISON_PACKAGE_SHA256, 'approval_sha256' => COMPARISON_APPROVAL_SHA256, 'approval_ref' => $approval['approval_ref'], 'target' => ['table' => 'mbti_cross_type_comparison_authorities', 'org_id' => 0, 'locale' => 'en', 'state' => 'draft'], 'row_count' => 7, 'rows' => $rows, 'publish_attempted' => false, 'activation_attempted' => false, 'active_pointer_changed' => false, 'indexability_attempted' => false, 'deploy_attempted' => false, 'private_authority_read_attempted' => false];
}

/** @return array<string,mixed> */
function resultReceipt(array $bundle, array $approval, string $controlPlaneSha, string $activeRevision): array
{
    $assets = json_decode((string) ($bundle['files']['assets.json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    $inventory = json_decode((string) ($bundle['files']['inventory_reconciliation.json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($assets) || ! is_array($inventory) || ! is_array($assets['assets'] ?? null) || count($assets['assets']) !== 21 || ! is_array($inventory['rows'] ?? null) || count($inventory['rows']) !== 46) {
        throw new RuntimeException('result_cohort_mismatch');
    }
    $assetsByRow = [];
    foreach ($assets['assets'] as $asset) {
        if (! is_array($asset) || ! is_string($asset['row_id'] ?? null)) {
            throw new RuntimeException('result_asset_invalid');
        }
        assertNoPrivateKeys($asset);
        $assetsByRow[$asset['row_id']] = $asset;
    }
    $rows = [];
    foreach ($inventory['rows'] as $position => $row) {
        if (! is_array($row) || ! is_string($row['row_id'] ?? null) || ! is_string($row['disposition'] ?? null)) {
            throw new RuntimeException('result_inventory_row_invalid');
        }
        $draftRow = ['position' => $position + 1, 'row_id' => $row['row_id'], 'disposition' => $row['disposition'], 'authority_state' => $row['disposition'] === 'candidate_asset' ? 'inactive_draft' : 'unchanged_no_write'];
        if ($row['disposition'] === 'candidate_asset') {
            $asset = $assetsByRow[$row['row_id']] ?? null;
            if (! is_array($asset)) {
                throw new RuntimeException('result_candidate_mapping_missing');
            }
            $draftRow['asset_sha256'] = hash('sha256', json_encode($asset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $draftRow['asset'] = $asset;
        }
        $rows[] = $draftRow;
    }
    $draft = ['schema_version' => 'fermatmind.mbti.en_result_content_inactive_draft.v1', 'authority' => ['pack_id' => 'MBTI.global.en.default', 'region' => 'GLOBAL', 'locale' => 'en', 'content_package_version' => 'v0.3', 'state' => 'inactive_draft', 'runtime_available' => false, 'active_pointer_registered' => false], 'source' => ['package_id' => 'EN-PARITY-W1-MBTI-RESULT-ASSETS-2026-07-31', 'package_sha256' => RESULT_PACKAGE_SHA256, 'inventory_package_sha256' => '8079465c6ec26820c99ca2be3f08346674e90509dee6d84fd610d5c6bbac2b85', 'approval_ref' => $approval['approval_ref'], 'approval_sha256' => RESULT_APPROVAL_SHA256], 'counts' => ['total_rows' => 46, 'preserved_reference_rows' => 24, 'inactive_candidate_rows' => 21, 'fixture_validation_rows' => 1, 'authority_content_rows' => 21], 'permissions' => ['private_payload_read' => false, 'activation' => false, 'publication' => false, 'indexability' => false, 'sitemap' => false, 'llms' => false, 'search_submission' => false, 'deployment' => false], 'rows' => $rows];
    assertNoPrivateKeys($draft);
    $bytes = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $contentHash = hash('sha256', $bytes);
    $releaseId = substr(hash('sha256', 'EN-PARITY-W1-MBTI-TARGET-AUTHORITY-RECEIPT-01|'.RESULT_PACKAGE_SHA256.'|'.$contentHash), 0, 8).'-'.substr(hash('sha256', $contentHash), 0, 4).'-5'.substr(hash('sha256', $contentHash), 5, 3).'-a'.substr(hash('sha256', $contentHash), 9, 3).'-'.substr(hash('sha256', $contentHash), 12, 12);
    DB::transaction(function () use ($draft, $contentHash, $releaseId, $controlPlaneSha): void {
        if (DB::table('content_pack_activations')->where('pack_id', 'MBTI.global.en.default')->where('pack_version', 'v0.3')->exists()) {
            throw new RuntimeException('result_active_pointer_exists');
        }
        $existing = DB::table('content_pack_releases')->where('id', $releaseId)->lockForUpdate()->first();
        $release = ['action' => 'mbti_target_authority_draft_receipt', 'region' => 'GLOBAL', 'locale' => 'en', 'dir_alias' => 'MBTI-GLOBAL-en-v0.3', 'from_version_id' => null, 'to_version_id' => null, 'from_pack_id' => null, 'to_pack_id' => 'MBTI.global.en.default', 'status' => 'success', 'message' => 'Inactive English MBTI result authority receipt; runtime and activation held.', 'created_by' => 'human_operator_controlled_receipt', 'manifest_hash' => $contentHash, 'compiled_hash' => RESULT_PACKAGE_SHA256, 'content_hash' => $contentHash, 'pack_version' => 'v0.3', 'manifest_json' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'storage_path' => 'database/content_pack_releases/'.$releaseId, 'source_commit' => $controlPlaneSha, 'git_sha' => $controlPlaneSha, 'updated_at' => now()];
        if ($existing === null) {
            DB::table('content_pack_releases')->insert($release + ['id' => $releaseId, 'created_at' => now()]);
        } elseif ((string) $existing->content_hash !== $contentHash || (string) $existing->to_pack_id !== 'MBTI.global.en.default' || (string) $existing->pack_version !== 'v0.3') {
            throw new RuntimeException('result_existing_target_collision');
        }
        DB::table('content_release_manifests')->updateOrInsert(['manifest_hash' => $contentHash], ['content_pack_release_id' => $releaseId, 'schema_version' => 'fermatmind.mbti.en_result_content_inactive_draft.v1', 'storage_disk' => 'database', 'storage_path' => 'content_pack_releases/'.$releaseId, 'pack_id' => 'MBTI.global.en.default', 'pack_version' => 'v0.3', 'compiled_hash' => RESULT_PACKAGE_SHA256, 'content_hash' => $contentHash, 'source_commit' => $controlPlaneSha, 'payload_json' => json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()]);
    }, 3);
    $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
    $manifest = DB::table('content_release_manifests')->where('manifest_hash', $contentHash)->first();
    if ($release === null || $manifest === null || DB::table('content_pack_activations')->where('pack_id', 'MBTI.global.en.default')->where('pack_version', 'v0.3')->exists()) {
        throw new RuntimeException('result_readback_failed');
    }

    return ['schema_version' => 'fermatmind.en_parity.target_authority_draft_receipt.v1', 'subscope_id' => 'W1-MBTI-RESULT-CONTENT', 'status' => 'PASS', 'control_plane_sha' => $controlPlaneSha, 'active_revision' => $activeRevision, 'package_sha256' => RESULT_PACKAGE_SHA256, 'approval_sha256' => RESULT_APPROVAL_SHA256, 'approval_ref' => $approval['approval_ref'], 'target' => ['content_pack_release_id' => $releaseId, 'content_release_manifest_hash' => $contentHash, 'pack_id' => 'MBTI.global.en.default', 'region' => 'GLOBAL', 'locale' => 'en', 'pack_version' => 'v0.3', 'state' => 'inactive_draft'], 'row_count' => 46, 'authority_content_row_count' => 21, 'publish_attempted' => false, 'activation_attempted' => false, 'active_pointer_changed' => false, 'indexability_attempted' => false, 'deploy_attempted' => false, 'private_authority_read_attempted' => false];
}

$comparisonBundle = exactPackage($sourceBackendRoot.'/content_assets/en-content-parity/W1-mbti/comparisons/w9-correction-deecc817', COMPARISON_MANIFEST_SHA256, COMPARISON_PACKAGE_SHA256);
$comparisonApproval = exactApproval($sourceBackendRoot.'/content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-COMPARISONS/target-authority-draft-receipt-approval-2026-08-01.json', COMPARISON_APPROVAL_SHA256, 'W1-MBTI-COMPARISONS', COMPARISON_PACKAGE_SHA256);
$resultBundle = exactPackage($sourceBackendRoot.'/content_assets/en-content-parity/W1-mbti/result-content', RESULT_MANIFEST_SHA256, RESULT_PACKAGE_SHA256);
$resultApproval = exactApproval($sourceBackendRoot.'/content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-RESULT-CONTENT/target-authority-draft-receipt-approval-2026-08-01.json', RESULT_APPROVAL_SHA256, 'W1-MBTI-RESULT-CONTENT', RESULT_PACKAGE_SHA256);
$comparison = comparisonReceipt($comparisonBundle, $comparisonApproval, $options['control-plane-sha'], $options['active-revision']);
$result = resultReceipt($resultBundle, $resultApproval, $options['control-plane-sha'], $options['active-revision']);
foreach (['comparison' => $comparison, 'result' => $result] as $name => $receipt) {
    $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    file_put_contents($receiptDir.'/'.$name.'-target-authority-draft-receipt.json', $bytes, LOCK_EX);
    echo $name.'_receipt_sha256='.hash('sha256', $bytes)."\n";
}
