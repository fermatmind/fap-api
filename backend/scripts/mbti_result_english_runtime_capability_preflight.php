<?php

declare(strict_types=1);

use App\Services\Cms\MbtiResultEnglishRuntimeCapabilityPreflightService;
use Illuminate\Contracts\Console\Kernel;

const REQUIRED_ACTIVE_REVISION = '660280d00a57e58bd8bc76608e19de2492c03f53';

set_exception_handler(static function (Throwable $throwable): never {
    $errorCode = strtolower(trim((string) strtok($throwable->getMessage(), ':')));
    if (preg_match('/\A[a-z0-9_]{1,96}\z/', $errorCode) !== 1) {
        $errorCode = 'unexpected_error';
    }
    fwrite(STDERR, "mbti_result_runtime_capability_preflight_failed:$errorCode\n");
    exit(1);
});

$options = getopt('', ['backend-root:', 'source-backend-root:', 'control-plane-sha:', 'active-revision:']);
foreach (['backend-root', 'source-backend-root', 'control-plane-sha', 'active-revision'] as $required) {
    if (! isset($options[$required]) || ! is_string($options[$required]) || trim($options[$required]) === '') {
        throw new RuntimeException('missing_required_option: '.$required);
    }
}
if (preg_match('/\A[a-f0-9]{40}\z/', $options['control-plane-sha']) !== 1
    || ! hash_equals(REQUIRED_ACTIVE_REVISION, $options['active-revision'])) {
    throw new RuntimeException('invalid_or_unapproved_revision');
}

$backendRoot = rtrim($options['backend-root'], '/');
$sourceBackendRoot = rtrim($options['source-backend-root'], '/');
if (! is_file($backendRoot.'/artisan') || ! is_file($backendRoot.'/vendor/autoload.php')
    || ! is_file($sourceBackendRoot.'/app/Services/Cms/MbtiResultEnglishRuntimeCapabilityPreflightService.php')) {
    throw new RuntimeException('source_or_backend_not_bootstrapable');
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
fwrite(STDOUT, json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
