<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerCurrentAuthorityParity;
use Illuminate\Contracts\Console\Kernel;

$env = static fn (string $name, string $default = ''): string => is_string(getenv($name))
    ? trim((string) getenv($name))
    : $default;

$backendRoot = $env('CAREER_PARITY_BACKEND_ROOT', dirname(__DIR__, 2));
$releaseSha = $env('CAREER_PARITY_RELEASE_SHA');
$activeSha = $env('CAREER_PARITY_ACTIVE_SHA');
$mode = $env('CAREER_PARITY_MODE', 'package');
$redisMode = $env('CAREER_PARITY_REDIS_MODE', 'none');
$receiptPath = $env('CAREER_PARITY_RECEIPT_PATH');

$emit = static function (array $receipt, string $receiptPath): never {
    $json = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    if ($receiptPath !== '') {
        $directory = dirname($receiptPath);
        $temporary = $receiptPath.'.tmp';
        if (! is_dir($directory)
            || is_link($directory)
            || is_link($receiptPath)
            || file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
            || ! rename($temporary, $receiptPath)) {
            @unlink($temporary);
            throw new RuntimeException('CAREER_PARITY_RECEIPT_WRITE_FAILED');
        }
        chmod($receiptPath, 0600);
    }
    echo $json;
    exit(($receipt['status'] ?? null) === 'pass' ? 0 : 1);
};

$failure = static function (string $code, string $releaseSha) use ($emit, $mode, $receiptPath): never {
    $emit([
        'contract_version' => $mode === 'package'
            ? 'career.current_authority_package_scan.v1'
            : 'career.current_authority_parity.v2',
        'status' => 'fail',
        'safe_error_code' => preg_match('/\A[A-Z0-9_]+\z/', $code) === 1 ? $code : 'CAREER_PARITY_FAILED',
        'mode' => $mode,
        'release_sha' => $releaseSha,
        'write_counts' => [
            'database_write_count' => 0,
            'cache_write_count' => 0,
            'discoverability_write_count' => 0,
            'search_write_count' => 0,
        ],
    ], $receiptPath);
};

if (! is_file($backendRoot.'/vendor/autoload.php')) {
    $failure('CAREER_PARITY_EXECUTION_CONTRACT_INVALID', $releaseSha);
}
require $backendRoot.'/vendor/autoload.php';

try {
    $app = require $backendRoot.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    /** @var CareerCurrentAuthorityParity $parity */
    $parity = $app->make(CareerCurrentAuthorityParity::class);
    $emit($parity->run($backendRoot, $mode, $redisMode, $releaseSha, $activeSha), $receiptPath);
} catch (Throwable $throwable) {
    $code = preg_match('/\A[A-Z0-9_]+\z/', $throwable->getMessage()) === 1
        ? $throwable->getMessage()
        : 'CAREER_PARITY_FAILED';
    $failure($code, $releaseSha);
}
