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

$controlPlaneSha = $env('CAREER_DISPLAY_REPLACEMENT_CONTROL_PLANE_SHA');
$releaseSha = $env('CAREER_DISPLAY_REPLACEMENT_RELEASE_SHA');
$releaseName = $env('CAREER_DISPLAY_REPLACEMENT_RELEASE_NAME');
$backendRoot = $env('CAREER_DISPLAY_REPLACEMENT_BACKEND_ROOT');
$packageSha256 = $env('CAREER_DISPLAY_REPLACEMENT_PACKAGE_SHA256');
$workflowRunId = $env('CAREER_DISPLAY_REPLACEMENT_WORKFLOW_RUN_ID');
$workflowRunAttempt = $env('CAREER_DISPLAY_REPLACEMENT_WORKFLOW_RUN_ATTEMPT');

$receipt = [
    'contract_version' => 'career.1046.display_asset_replacement.v2',
    'mode' => 'execute',
    'status' => 'FAIL_DISPLAY_ASSET_REPLACEMENT',
    'failed_stage' => 'initialize',
    'safe_error_code' => null,
    'control_plane_sha' => $controlPlaneSha,
    'release_sha' => $releaseSha,
    'release_name_sha256' => hash('sha256', $releaseName),
    'workflow_run_id' => ctype_digit($workflowRunId) ? (int) $workflowRunId : null,
    'workflow_run_attempt' => ctype_digit($workflowRunAttempt) ? (int) $workflowRunAttempt : null,
    'package_sha256' => $packageSha256,
    'production_write_execution' => true,
    'write_commit_state' => 'ambiguous',
    'writes_committed' => false,
    'idempotent_noop' => false,
    'package' => null,
    'missing_base_package' => null,
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
    exit(($value['status'] ?? '') === 'PASS_EXECUTE_DISPLAY_ASSET_REPLACEMENT' ? 0 : 1);
};

if ($backendRoot === '' || ! is_file($backendRoot.'/vendor/autoload.php')) {
    $receipt['safe_error_code'] = 'EXECUTION_CONTRACT_INVALID';
    $emit($receipt);
}

require $backendRoot.'/vendor/autoload.php';

try {
    if ($env('CAREER_DISPLAY_REPLACEMENT_EXECUTE') !== '1'
        || preg_match('/\A[a-f0-9]{40}\z/', $controlPlaneSha) !== 1
        || $releaseSha !== $controlPlaneSha
        || $releaseName === ''
        || preg_match('/\A[a-f0-9]{64}\z/', $packageSha256) !== 1
        || ! ctype_digit($workflowRunId)
        || ! ctype_digit($workflowRunAttempt)) {
        throw new Career1046DisplayAssetReplacementFailure('EXECUTION_CONTRACT_INVALID');
    }

    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::listen(static function (QueryExecuted $query) use (&$receipt): void {
        if (preg_match('/\A(?:insert|update|delete|replace|alter|create|drop|truncate|rename)\b/', strtolower(ltrim($query->sql))) === 1) {
            $receipt['observed_database_mutation_query_count']++;
        }
    });

    /** @var Career1046DisplayAssetReplacement $replacement */
    $replacement = $app->make(Career1046DisplayAssetReplacement::class);
    $receipt['failed_stage'] = 'execute';
    $result = $replacement->execute($backendRoot, $packageSha256);
    foreach (['package', 'missing_base_package', 'authority', 'cache', 'state_sha256', 'write_counts', 'idempotent_noop'] as $key) {
        $receipt[$key] = $result[$key];
    }

    $noop = $result['idempotent_noop'] === true;
    $receipt['write_commit_state'] = $noop ? 'confirmed_zero_write' : 'committed';
    $receipt['writes_committed'] = ! $noop;
    $countsValid = $noop
        ? array_sum($result['write_counts']) === 0
        : (($result['write_counts']['database_update_count'] ?? null) === 1034
            && ($result['write_counts']['database_insert_count'] ?? null) === 12
            && ($result['write_counts']['cache_pointer_activation_count'] ?? null) === 2092);
    if (($result['package']['career_count'] ?? null) !== 1046
        || ($result['package']['locale_row_count'] ?? null) !== 2092
        || ($result['package']['content_block_count'] ?? null) !== 4184
        || ($result['package']['numeric_rating_statement_residue_count'] ?? null) !== 0
        || ($result['missing_base_package']['asset_count'] ?? null) !== 12
        || ($result['authority']['target_count'] ?? null) !== 1046
        || ($result['authority']['component_order_after_count'] ?? null) !== 1046
        || ($result['cache']['ready_active_count'] ?? null) !== 2092
        || ($result['cache']['component_26_count'] ?? null) !== 2092
        || ($result['cache']['content_match_count'] ?? null) !== 2092
        || ($result['cache']['career_ai_description_block_sha256'] ?? null) !== ($result['package']['career_ai_description_block_sha256'] ?? null)
        || ($result['cache']['career_path_block_sha256'] ?? null) !== ($result['package']['career_path_block_sha256'] ?? null)
        || ($result['cache']['display_block_aggregate_sha256'] ?? null) !== ($result['package']['display_block_aggregate_sha256'] ?? null)
        || ! $countsValid) {
        throw new Career1046DisplayAssetReplacementFailure(
            'EXECUTE_READBACK_MISMATCH',
            null,
            $noop ? 'confirmed_zero_write' : 'committed',
        );
    }

    $receipt['failed_stage'] = null;
    $receipt['status'] = 'PASS_EXECUTE_DISPLAY_ASSET_REPLACEMENT';
} catch (Throwable $throwable) {
    $receipt['safe_error_code'] = $throwable instanceof Career1046DisplayAssetReplacementFailure
        ? $throwable->safeCode
        : 'UNEXPECTED_CONTROL_FAILURE';
    if ($throwable instanceof Career1046DisplayAssetReplacementFailure) {
        $receipt['write_commit_state'] = $throwable->writeCommitState;
    }
}

$emit($receipt);
