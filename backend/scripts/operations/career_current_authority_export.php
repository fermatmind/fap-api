<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerCurrentAuthorityExporter;
use App\Domain\Career\Display\CareerCurrentAuthorityExportFailure;
use Illuminate\Contracts\Console\Kernel;

$env = static function (string $name): string {
    $value = getenv($name);

    return is_string($value) ? trim($value) : '';
};

$backendRoot = $env('CAREER_CURRENT_EXPORT_BACKEND_ROOT');
$releaseSha = $env('CAREER_CURRENT_EXPORT_RELEASE_SHA');
$expectedProjectionSha256 = $env('CAREER_CURRENT_EXPORT_EXPECTED_PROJECTION_SHA256');
$releaseName = $env('CAREER_CURRENT_EXPORT_RELEASE_NAME');
$workflowRunId = $env('CAREER_CURRENT_EXPORT_WORKFLOW_RUN_ID');
$workflowRunAttempt = $env('CAREER_CURRENT_EXPORT_WORKFLOW_RUN_ATTEMPT');

if ($env('CAREER_CURRENT_EXPORT_EXECUTE') !== '1'
    || ! is_file($backendRoot.'/vendor/autoload.php')
    || preg_match('/\A[a-f0-9]{40}\z/', $releaseSha) !== 1
    || preg_match('/\A[a-f0-9]{64}\z/', $expectedProjectionSha256) !== 1
    || $releaseName === ''
    || ! ctype_digit($workflowRunId)
    || ! ctype_digit($workflowRunAttempt)) {
    fwrite(STDERR, "EXPORT_EXECUTION_CONTRACT_INVALID\n");
    exit(1);
}

try {
    require $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    /** @var CareerCurrentAuthorityExporter $exporter */
    $exporter = $app->make(CareerCurrentAuthorityExporter::class);
    $documents = $exporter->export([
        'release_sha' => $releaseSha,
        'expected_projection_sha256' => $expectedProjectionSha256,
        'release_name_sha256' => hash('sha256', $releaseName),
        'workflow_run_id' => (int) $workflowRunId,
        'workflow_run_attempt' => (int) $workflowRunAttempt,
    ]);

    echo json_encode(
        ['kind' => 'meta', 'manifest' => $documents['manifest'], 'receipt' => $documents['receipt']],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    )."\n";
    echo $documents['assets_jsonl'];
} catch (Throwable $failure) {
    echo json_encode([
        'kind' => 'meta',
        'receipt' => [
            'contract_version' => CareerCurrentAuthorityExporter::CONTRACT_VERSION,
            'status' => 'FAIL_CURRENT_AUTHORITY_EXPORT',
            'safe_error_code' => $failure instanceof CareerCurrentAuthorityExportFailure
                ? $failure->safeCode
                : 'UNEXPECTED_EXPORT_FAILURE',
            'production_read_only' => true,
            'automatic_retry_allowed' => false,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
