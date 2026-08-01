<?php

declare(strict_types=1);

use App\Services\Cms\MbtiResultEnglishRuntimeCapabilityPreflightService;
use Illuminate\Contracts\Console\Kernel;

const REQUIRED_ACTIVE_REVISION = '660280d00a57e58bd8bc76608e19de2492c03f53';

function mbtiResultRuntimeCapabilityPreflightDiscardOutputBuffers(): string
{
    $output = '';

    while (ob_get_level() > 0) {
        $buffer = ob_get_clean();
        if (is_string($buffer)) {
            $output .= $buffer;
        }
    }

    return $output;
}

function mbtiResultRuntimeCapabilityPreflightFail(string $errorCode): never
{
    mbtiResultRuntimeCapabilityPreflightDiscardOutputBuffers();
    fwrite(STDERR, "mbti_result_runtime_capability_preflight_failed:$errorCode\n");
    exit(1);
}

$executorCompleted = false;
ob_start();

register_shutdown_function(static function () use (&$executorCompleted): void {
    if ($executorCompleted) {
        return;
    }

    $lastError = error_get_last();
    if (is_array($lastError) && in_array($lastError['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        mbtiResultRuntimeCapabilityPreflightDiscardOutputBuffers();
        fwrite(STDERR, "mbti_result_runtime_capability_preflight_failed:executor_bootstrap_failed\n");
    }
});

set_exception_handler(static function (Throwable $throwable): never {
    mbtiResultRuntimeCapabilityPreflightFail('executor_runtime_failed');
});

set_error_handler(static function (): never {
    throw new ErrorException('executor_runtime_failed');
});

$options = getopt('', ['backend-root:', 'source-backend-root:', 'control-plane-sha:', 'active-revision:']);
foreach (['backend-root', 'source-backend-root', 'control-plane-sha', 'active-revision'] as $required) {
    if (! isset($options[$required]) || ! is_string($options[$required]) || trim($options[$required]) === '') {
        mbtiResultRuntimeCapabilityPreflightFail('executor_invalid_input');
    }
}
if (preg_match('/\A[a-f0-9]{40}\z/', $options['control-plane-sha']) !== 1
    || ! hash_equals(REQUIRED_ACTIVE_REVISION, $options['active-revision'])) {
    mbtiResultRuntimeCapabilityPreflightFail('executor_invalid_input');
}

$backendRoot = rtrim($options['backend-root'], '/');
$sourceBackendRoot = rtrim($options['source-backend-root'], '/');
if (! is_file($backendRoot.'/artisan') || ! is_file($backendRoot.'/vendor/autoload.php')
    || ! is_file($sourceBackendRoot.'/app/Services/Cms/MbtiResultEnglishRuntimeCapabilityPreflightService.php')) {
    mbtiResultRuntimeCapabilityPreflightFail('executor_bootstrap_failed');
}

require $backendRoot.'/vendor/autoload.php';
require $sourceBackendRoot.'/app/Services/Cms/MbtiResultEnglishRuntimeCapabilityPreflightService.php';
$app = require $backendRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$receipt = $app->make(MbtiResultEnglishRuntimeCapabilityPreflightService::class)->inspect(
    $sourceBackendRoot.'/content_assets/en-content-parity/CONTROL-approvals/W1-MBTI-RESULT-CONTENT/runtime-capability-preflight-approval-2026-08-02.json',
    MbtiResultEnglishRuntimeCapabilityPreflightService::APPROVAL_SHA256,
);
$receipt['control_plane_sha'] = $options['control-plane-sha'];
$receipt['active_revision'] = $options['active-revision'];

$bufferedOutput = mbtiResultRuntimeCapabilityPreflightDiscardOutputBuffers();
if ($bufferedOutput !== '') {
    mbtiResultRuntimeCapabilityPreflightFail('executor_stdout_contaminated');
}
if (! is_array($receipt)) {
    mbtiResultRuntimeCapabilityPreflightFail('executor_receipt_encode_failed');
}

try {
    $encodedReceipt = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $decodedReceipt = json_decode($encodedReceipt, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    mbtiResultRuntimeCapabilityPreflightFail('executor_receipt_encode_failed');
}
if (! is_array($decodedReceipt)) {
    mbtiResultRuntimeCapabilityPreflightFail('executor_receipt_encode_failed');
}

$stdout = $encodedReceipt.PHP_EOL;
if (fwrite(STDOUT, $stdout) !== strlen($stdout)) {
    mbtiResultRuntimeCapabilityPreflightFail('executor_receipt_encode_failed');
}

$executorCompleted = true;
