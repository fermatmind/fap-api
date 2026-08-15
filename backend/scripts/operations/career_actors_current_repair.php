<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerActorsCurrentRepair;
use App\Domain\Career\Display\CareerActorsCurrentRepairFailure;
use Illuminate\Contracts\Console\Kernel;

$env = static function (string $name): string {
    $value = getenv($name);

    return is_string($value) ? trim($value) : '';
};

$backendRoot = $env('CAREER_ACTORS_REPAIR_BACKEND_ROOT');
$releaseSha = $env('CAREER_ACTORS_REPAIR_RELEASE_SHA');
$releaseName = $env('CAREER_ACTORS_REPAIR_RELEASE_NAME');
$workflowRunId = $env('CAREER_ACTORS_REPAIR_WORKFLOW_RUN_ID');
$workflowRunAttempt = $env('CAREER_ACTORS_REPAIR_WORKFLOW_RUN_ATTEMPT');

if ($env('CAREER_ACTORS_REPAIR_EXECUTE') !== '1'
    || ! is_file($backendRoot.'/vendor/autoload.php')
    || preg_match('/\A[a-f0-9]{40}\z/', $releaseSha) !== 1
    || $releaseName === ''
    || ! ctype_digit($workflowRunId)
    || ! ctype_digit($workflowRunAttempt)) {
    fwrite(STDERR, "ACTORS_REPAIR_EXECUTION_CONTRACT_INVALID\n");
    exit(1);
}

try {
    require $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    /** @var CareerActorsCurrentRepair $repair */
    $repair = $app->make(CareerActorsCurrentRepair::class);
    $receipt = $repair->execute($backendRoot, [
        'release_sha' => $releaseSha,
        'release_name_sha256' => hash('sha256', $releaseName),
        'workflow_run_id' => (int) $workflowRunId,
        'workflow_run_attempt' => (int) $workflowRunAttempt,
    ]);
    echo json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $failure) {
    echo json_encode([
        'contract_version' => CareerActorsCurrentRepair::CONTRACT_VERSION,
        'status' => 'FAIL_ACTORS_CURRENT_REPAIR',
        'safe_error_code' => $failure instanceof CareerActorsCurrentRepairFailure
            ? $failure->safeCode
            : 'UNEXPECTED_ACTORS_REPAIR_FAILURE',
        'write_commit_state' => $failure instanceof CareerActorsCurrentRepairFailure
            ? $failure->writeCommitState
            : 'ambiguous',
        'automatic_retry_allowed' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
