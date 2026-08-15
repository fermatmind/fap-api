<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPublisher;
use App\Domain\Career\Display\CareerCurrentAuthorityPublisherFailure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

$env = static function (string $name): string {
    $value = getenv($name);

    return is_string($value) ? trim($value) : '';
};

$releaseSha = $env('CAREER_CURRENT_PUBLISH_RELEASE_SHA');
$releaseName = $env('CAREER_CURRENT_PUBLISH_RELEASE_NAME');
$backendRoot = $env('CAREER_CURRENT_PUBLISH_BACKEND_ROOT');
$assetsSha256 = $env('CAREER_CURRENT_PUBLISH_ASSETS_SHA256');
$operationKey = $env('CAREER_CURRENT_PUBLISH_OPERATION_KEY');
$workflowRunId = $env('CAREER_CURRENT_PUBLISH_WORKFLOW_RUN_ID');
$workflowRunAttempt = $env('CAREER_CURRENT_PUBLISH_WORKFLOW_RUN_ATTEMPT');
$fullScan = $env('CAREER_CURRENT_PUBLISH_FULL_SCAN') === '1';

$zeroCounts = [
    'database_update_count' => 0,
    'database_insert_count' => 0,
    'database_delete_count' => 0,
    'cache_candidate_write_count' => 0,
    'cache_pointer_activation_count' => 0,
    'occupation_write_count' => 0,
    'generation_write_count' => 0,
    'discoverability_write_count' => 0,
    'cms_write_count' => 0,
    'sitemap_write_count' => 0,
    'llms_write_count' => 0,
    'search_submission_count' => 0,
];
$receipt = [
    'contract_version' => CareerCurrentAuthorityPublisher::CONTRACT_VERSION,
    'status' => 'FAIL_CURRENT_AUTHORITY_PUBLISH',
    'safe_error_code' => null,
    'release_sha' => $releaseSha,
    'release_name_sha256' => hash('sha256', $releaseName),
    'assets_sha256' => $assetsSha256,
    'operation_key' => $operationKey,
    'workflow_run_id' => ctype_digit($workflowRunId) ? (int) $workflowRunId : null,
    'workflow_run_attempt' => ctype_digit($workflowRunAttempt) ? (int) $workflowRunAttempt : null,
    'full_scan' => $fullScan,
    'write_commit_state' => 'ambiguous',
    'writes_committed' => false,
    'idempotent_noop' => false,
    'package' => null,
    'authority' => null,
    'public_readback' => null,
    'manual_hold_verified' => false,
    'state_sha256' => null,
    'write_counts' => $zeroCounts,
    'observed_database_mutation_query_count' => 0,
    'automatic_retry_allowed' => false,
    'negative_guarantees' => [
        'occupation_changed' => false,
        'crosswalk_changed' => false,
        'generation_changed' => false,
        'discoverability_changed' => false,
        'search_channel_executed' => false,
        'task_3a_through_7b_executed' => false,
        'migration_executed' => false,
        'deployment_executed' => false,
    ],
];

$emit = static function (array $value): never {
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    exit(($value['status'] ?? '') === 'PASS_CURRENT_AUTHORITY_PUBLISH' ? 0 : 1);
};

if ($backendRoot === '' || ! is_file($backendRoot.'/vendor/autoload.php')) {
    $receipt['safe_error_code'] = 'CURRENT_PUBLISH_EXECUTION_CONTRACT_INVALID';
    $emit($receipt);
}

require $backendRoot.'/vendor/autoload.php';

try {
    $expectedOperationKey = hash(
        'sha256',
        'career-current-authority|'.$releaseSha.'|'.$assetsSha256,
    );
    if ($env('CAREER_CURRENT_PUBLISH_EXECUTE') !== '1'
        || preg_match('/\A[0-9a-f]{40}\z/', $releaseSha) !== 1
        || $releaseName === ''
        || ! hash_equals(CareerCurrentAuthorityPackage::ASSETS_SHA256, $assetsSha256)
        || ! hash_equals($expectedOperationKey, $operationKey)
        || ! ctype_digit($workflowRunId)
        || ! ctype_digit($workflowRunAttempt)) {
        throw new CareerCurrentAuthorityPublisherFailure('CURRENT_PUBLISH_EXECUTION_CONTRACT_INVALID');
    }

    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::listen(static function (QueryExecuted $query) use (&$receipt): void {
        if (preg_match('/\A(?:insert|update|delete|replace|alter|create|drop|truncate|rename)\b/', strtolower(ltrim($query->sql))) === 1) {
            $receipt['observed_database_mutation_query_count']++;
        }
    });

    /** @var CareerCurrentAuthorityPublisher $publisher */
    $publisher = $app->make(CareerCurrentAuthorityPublisher::class);
    $result = $publisher->execute($backendRoot, $fullScan);
    foreach (['package', 'authority', 'public_readback', 'manual_hold_verified', 'idempotent_noop', 'write_counts', 'state_sha256'] as $key) {
        $receipt[$key] = $result[$key];
    }

    if (($result['package']['career_count'] ?? null) !== 1046
        || ($result['package']['locale_page_count'] ?? null) !== 2092
        || ($result['package']['components_per_page'] ?? null) !== 26
        || ($result['authority']['target_count'] ?? null) !== 1046
        || ($result['authority']['unique_slug_count'] ?? null) !== 1046
        || ($result['authority']['component_26_count'] ?? null) !== 1046
        || ($result['manual_hold_verified'] ?? null) !== true
        || ($fullScan && ($result['public_readback']['verified_locale_page_count'] ?? null) !== 2092)
        || ($result['write_counts']['occupation_write_count'] ?? null) !== 0
        || ($result['write_counts']['generation_write_count'] ?? null) !== 0
        || ($result['write_counts']['discoverability_write_count'] ?? null) !== 0
        || ($result['write_counts']['search_submission_count'] ?? null) !== 0) {
        throw new CareerCurrentAuthorityPublisherFailure(
            'CURRENT_PUBLISH_READBACK_MISMATCH',
            null,
            ($result['idempotent_noop'] ?? false) ? 'confirmed_zero_write' : 'committed',
        );
    }

    $noop = $result['idempotent_noop'] === true;
    $receipt['write_commit_state'] = $noop ? 'confirmed_zero_write' : 'committed';
    $receipt['writes_committed'] = ! $noop;
    $receipt['status'] = 'PASS_CURRENT_AUTHORITY_PUBLISH';
} catch (Throwable $throwable) {
    $receipt['safe_error_code'] = $throwable instanceof CareerCurrentAuthorityPublisherFailure
        ? $throwable->safeCode
        : 'UNEXPECTED_CURRENT_PUBLISH_FAILURE';
    if ($throwable instanceof CareerCurrentAuthorityPublisherFailure) {
        $receipt['write_commit_state'] = $throwable->writeCommitState;
    }
}

$emit($receipt);
