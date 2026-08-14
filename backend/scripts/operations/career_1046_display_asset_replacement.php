<?php

declare(strict_types=1);

use App\Domain\Career\Display\Career1046DisplayAssetReplacement;
use App\Domain\Career\Display\Career1046DisplayAssetReplacementFailure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

$env = static function (string $name): string {
    $value = getenv($name);

    return is_string($value) ? trim($value) : '';
};

$mode = $argv[1] ?? '';
$controlPlaneSha = $env('CAREER_DISPLAY_REPLACEMENT_CONTROL_PLANE_SHA');
$releaseSha = $env('CAREER_DISPLAY_REPLACEMENT_RELEASE_SHA');
$releaseName = $env('CAREER_DISPLAY_REPLACEMENT_RELEASE_NAME');
$backendRoot = $env('CAREER_DISPLAY_REPLACEMENT_BACKEND_ROOT');
$packageSha256 = $env('CAREER_DISPLAY_REPLACEMENT_PACKAGE_SHA256');
$preflightReceiptSha256 = $env('CAREER_DISPLAY_REPLACEMENT_PREFLIGHT_RECEIPT_SHA256');
$preflightStateSha256 = $env('CAREER_DISPLAY_REPLACEMENT_PREFLIGHT_STATE_SHA256');
$workflowRunId = $env('CAREER_DISPLAY_REPLACEMENT_WORKFLOW_RUN_ID');
$workflowRunAttempt = $env('CAREER_DISPLAY_REPLACEMENT_WORKFLOW_RUN_ATTEMPT');
$applyAuthorized = $env('CAREER_DISPLAY_REPLACEMENT_APPLY_AUTHORIZED') === '1';

$receipt = [
    'contract_version' => Career1046DisplayAssetReplacement::CONTRACT_VERSION,
    'mode' => $mode,
    'status' => 'FAIL_DISPLAY_ASSET_REPLACEMENT',
    'failed_stage' => 'initialize',
    'safe_error_code' => null,
    'control_plane_sha' => $controlPlaneSha,
    'release_sha' => $releaseSha,
    'release_name_sha256' => hash('sha256', $releaseName),
    'workflow_run_id' => ctype_digit($workflowRunId) ? (int) $workflowRunId : null,
    'workflow_run_attempt' => ctype_digit($workflowRunAttempt) ? (int) $workflowRunAttempt : null,
    'package_sha256' => $packageSha256,
    'preflight_receipt_sha256' => $preflightReceiptSha256 !== '' ? $preflightReceiptSha256 : null,
    'preflight_state_sha256' => $preflightStateSha256 !== '' ? $preflightStateSha256 : null,
    'production_write_execution' => $mode === 'apply',
    'write_commit_state' => $mode === 'apply' ? 'ambiguous' : 'confirmed_zero_write',
    'writes_committed' => false,
    'package' => null,
    'authority' => null,
    'cache' => null,
    'state_sha256' => null,
    'write_counts' => [
        'database_update_count' => 0,
        'database_insert_count' => 0,
        'database_delete_count' => 0,
        'cache_candidate_write_count' => 0,
        'cache_pointer_activation_count' => 0,
        'cms_write_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_submission_count' => 0,
        'generation_pointer_write_count' => 0,
    ],
    'observed_database_mutation_query_count' => 0,
    'automatic_retry_allowed' => false,
    'negative_guarantees' => [
        'task_3a_executed' => false,
        'task_3b_executed' => false,
        'task_4b_through_7b_executed' => false,
        'sitemap_or_llms_release_executed' => false,
        'search_channel_executed' => false,
        'generation_root_changed' => false,
        'cms_changed' => false,
        'migration_executed' => false,
        'deploy_executed' => false,
    ],
];

$emit = static function (array $value): never {
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    exit(($value['status'] ?? '') === 'PASS_PREFLIGHT_DISPLAY_ASSET_REPLACEMENT'
        || ($value['status'] ?? '') === 'PASS_APPLY_DISPLAY_ASSET_REPLACEMENT' ? 0 : 1);
};

try {
    if ($env('CAREER_DISPLAY_REPLACEMENT_EXECUTE') !== '1'
        || ! in_array($mode, ['preflight', 'apply'], true)
        || preg_match('/\A[a-f0-9]{40}\z/', $controlPlaneSha) !== 1
        || $releaseSha !== $controlPlaneSha
        || $releaseName === ''
        || $backendRoot === ''
        || preg_match('/\A[a-f0-9]{64}\z/', $packageSha256) !== 1
        || ! ctype_digit($workflowRunId)
        || ! ctype_digit($workflowRunAttempt)) {
        throw new Career1046DisplayAssetReplacementFailure('EXECUTION_CONTRACT_INVALID');
    }
    if ($mode === 'preflight' && ($applyAuthorized || $preflightReceiptSha256 !== '' || $preflightStateSha256 !== '')) {
        throw new Career1046DisplayAssetReplacementFailure('PREFLIGHT_WRITE_AUTHORITY_REFUSED');
    }
    if ($mode === 'apply' && (! $applyAuthorized
        || preg_match('/\A[a-f0-9]{64}\z/', $preflightReceiptSha256) !== 1
        || preg_match('/\A[a-f0-9]{64}\z/', $preflightStateSha256) !== 1)) {
        throw new Career1046DisplayAssetReplacementFailure('APPLY_AUTHORITY_INVALID');
    }

    require $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::listen(static function (QueryExecuted $query) use (&$receipt): void {
        $sql = strtolower(ltrim($query->sql));
        if (preg_match('/\A(?:insert|update|delete|replace|alter|create|drop|truncate|rename)\b/', $sql) === 1) {
            $receipt['observed_database_mutation_query_count']++;
        }
    });

    /** @var Career1046DisplayAssetReplacement $replacement */
    $replacement = $app->make(Career1046DisplayAssetReplacement::class);
    $receipt['failed_stage'] = $mode === 'preflight' ? 'preflight' : 'apply';
    $result = $mode === 'preflight'
        ? $replacement->preflight($backendRoot, $packageSha256)
        : $replacement->apply($backendRoot, $packageSha256, $preflightStateSha256);

    if ($mode === 'preflight' && $receipt['observed_database_mutation_query_count'] !== 0) {
        throw new Career1046DisplayAssetReplacementFailure('PREFLIGHT_DATABASE_WRITE_OBSERVED');
    }
    $receipt['package'] = $result['package'];
    $receipt['authority'] = $result['authority'];
    $receipt['cache'] = $result['cache'];
    $receipt['state_sha256'] = $result['state_sha256'];
    $receipt['write_counts'] = $result['write_counts'];
    if ($mode === 'apply') {
        // apply() returns only after the database and all active pointers commit.
        // Preserve that truth even if a later receipt-contract assertion fails.
        $receipt['write_commit_state'] = 'committed';
        $receipt['writes_committed'] = true;
    }
    if (($result['package']['career_count'] ?? null) !== 1046
        || ($result['package']['locale_row_count'] ?? null) !== 2092
        || ($result['package']['content_block_count'] ?? null) !== 4184
        || ($result['authority']['target_count'] ?? null) !== 1046
        || ($result['authority']['component_order_after_count'] ?? null) !== 1046) {
        throw new Career1046DisplayAssetReplacementFailure('RESULT_COUNT_MISMATCH');
    }

    $receipt['failed_stage'] = null;
    if ($mode === 'preflight') {
        $receipt['status'] = 'PASS_PREFLIGHT_DISPLAY_ASSET_REPLACEMENT';
        $receipt['write_commit_state'] = 'confirmed_zero_write';
    } else {
        if (($result['cache']['ready_active_count'] ?? null) !== 2092
            || ($result['cache']['component_26_count'] ?? null) !== 2092
            || ($result['cache']['content_match_count'] ?? null) !== 2092
            || ($result['write_counts']['database_insert_count'] ?? null) !== 0
            || ($result['write_counts']['database_delete_count'] ?? null) !== 0
            || ($result['write_counts']['cache_pointer_activation_count'] ?? null) !== 2092) {
            throw new Career1046DisplayAssetReplacementFailure('APPLY_READBACK_MISMATCH');
        }
        $receipt['status'] = 'PASS_APPLY_DISPLAY_ASSET_REPLACEMENT';
    }
} catch (Throwable $throwable) {
    $receipt['safe_error_code'] = $throwable instanceof Career1046DisplayAssetReplacementFailure
        ? $throwable->safeCode
        : 'UNEXPECTED_CONTROL_FAILURE';
}

$emit($receipt);
