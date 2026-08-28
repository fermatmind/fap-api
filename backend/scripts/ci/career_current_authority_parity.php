<?php

declare(strict_types=1);

use App\Domain\Career\Display\CareerCurrentAuthorityParity;
use Illuminate\Contracts\Console\Kernel;

$env = static fn (string $name, string $default = ''): string => is_string(getenv($name))
    ? trim((string) getenv($name))
    : $default;

$backendRoot = $env('CAREER_PARITY_BACKEND_ROOT', dirname(__DIR__, 2));
$releaseSha = $env('CAREER_PARITY_RELEASE_SHA');
$redisMode = $env('CAREER_PARITY_REDIS_MODE', 'none');
$requireDatabase = $env('CAREER_PARITY_REQUIRE_DATABASE') === '1';

$failure = static function (string $code, string $releaseSha): never {
    echo json_encode([
        'contract_version' => CareerCurrentAuthorityParity::CONTRACT_VERSION,
        'status' => 'fail',
        'safe_error_code' => preg_match('/\A[A-Z0-9_]+\z/', $code) === 1 ? $code : 'CAREER_PARITY_FAILED',
        'release_sha' => $releaseSha,
        'write_counts' => [
            'database_write_count' => 0,
            'cache_write_count' => 0,
            'discoverability_write_count' => 0,
            'search_write_count' => 0,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    exit(1);
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
    echo json_encode(
        $parity->run($backendRoot, $requireDatabase, $redisMode, $releaseSha),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    )."\n";
} catch (Throwable $throwable) {
    $code = preg_match('/\A[A-Z0-9_]+\z/', $throwable->getMessage()) === 1
        ? $throwable->getMessage()
        : 'CAREER_PARITY_FAILED';
    $failure($code, $releaseSha);
}
