<?php

declare(strict_types=1);

use App\Domain\Career\Publish\CareerGenerationAuthorityLoader;
use Illuminate\Contracts\Console\Kernel;

const CAREER_RUNTIME_DIAGNOSTIC_CONTRACT = 'career.production_runtime_authority_diagnostic.v1';
const CAREER_RUNTIME_DIAGNOSTIC_GENERATION = 'career-current-342-30-bootstrap-v1';
const CAREER_RUNTIME_DIAGNOSTIC_POINTER_SHA256 = '1ebfd2826be9d3b63d810d33050034e3d424c95b3db81fa49b0822c5e6b2ec08';
const CAREER_RUNTIME_DIAGNOSTIC_PROJECTION_SHA256 = '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6';
const CAREER_RUNTIME_DIAGNOSTIC_LEDGER_SHA256 = '975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e';

final class CareerRuntimeAuthorityDiagnosticFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

$receipt = [
    'contract_version' => CAREER_RUNTIME_DIAGNOSTIC_CONTRACT,
    'status' => 'FAIL_RUNTIME_AUTHORITY_DIAGNOSTIC',
    'failed_stage' => 'initialize',
    'control_plane_sha' => safeShaEnv('CAREER_RUNTIME_DIAGNOSTIC_CONTROL_PLANE_SHA', 40),
    'active_revision' => safeShaEnv('CAREER_RUNTIME_DIAGNOSTIC_ACTIVE_REVISION', 40),
    'active_release_name_sha256' => safeShaEnv('CAREER_RUNTIME_DIAGNOSTIC_RELEASE_NAME_SHA256'),
    'pointer_apply_run_id' => 31593321673,
    'pointer_apply_artifact_digest' => 'sha256:101508066a741afd44d29b4c28bd866b1fa3d4772dfda14c71da71c320c545c7',
    'pointer_apply_receipt_sha256' => 'e0898f6fbb438495319cf0acc8bd6f808eba18c3f3beeb4a4e7d690312c27bc4',
    'generation_id' => CAREER_RUNTIME_DIAGNOSTIC_GENERATION,
    'pointer_document_sha256' => CAREER_RUNTIME_DIAGNOSTIC_POINTER_SHA256,
    'projection_sha256' => CAREER_RUNTIME_DIAGNOSTIC_PROJECTION_SHA256,
    'ledger_sha256' => CAREER_RUNTIME_DIAGNOSTIC_LEDGER_SHA256,
    'runtime_user_is_www_data' => false,
    'authority_root_readable' => false,
    'active_pointer_readable' => false,
    'immutable_pointer_readable' => false,
    'projection_readable' => false,
    'ledger_readable' => false,
    'loader_strict_readable' => false,
    'slug_count' => 0,
    'locale_row_count' => 0,
    'published_slug_count' => 0,
    'published_locale_row_count' => 0,
    'database_write_count' => 0,
    'cms_write_count' => 0,
    'cache_write_count' => 0,
    'pointer_write_count' => 0,
    'artifact_write_count' => 0,
    'permission_write_count' => 0,
    'publication_write_count' => 0,
    'discoverability_write_count' => 0,
    'migration_count' => 0,
    'deployment_count' => 0,
    'restart_count' => 0,
    'writes_committed' => false,
];

try {
    if (! function_exists('posix_geteuid') || ! function_exists('posix_getpwuid')) {
        throw new CareerRuntimeAuthorityDiagnosticFailure('RUNTIME_IDENTITY_UNAVAILABLE');
    }
    $runtimeIdentity = posix_getpwuid(posix_geteuid());
    if (! is_array($runtimeIdentity) || ($runtimeIdentity['name'] ?? null) !== 'www-data') {
        throw new CareerRuntimeAuthorityDiagnosticFailure('RUNTIME_IDENTITY_INVALID');
    }
    $receipt['runtime_user_is_www_data'] = true;

    $backendRoot = requiredBackendRoot();
    $privateRoot = $backendRoot.'/storage/app/private';
    $authorityRoot = $privateRoot.'/career_generation_authority';
    assertReadableDirectory($authorityRoot, 'AUTHORITY_ROOT_UNREADABLE');
    $receipt['authority_root_readable'] = true;

    $activePath = $authorityRoot.'/active-generation.json';
    $active = readBoundJson($authorityRoot, $activePath, CAREER_RUNTIME_DIAGNOSTIC_POINTER_SHA256, 'ACTIVE_POINTER');
    $receipt['active_pointer_readable'] = true;
    $payload = $active['payload'] ?? null;
    if (! is_array($payload)
        || ($active['schema_version'] ?? null) !== 'career.generation_pointer.v1'
        || ($payload['generation_id'] ?? null) !== CAREER_RUNTIME_DIAGNOSTIC_GENERATION) {
        throw new CareerRuntimeAuthorityDiagnosticFailure('ACTIVE_POINTER_CONTRACT_INVALID');
    }

    $immutablePath = $authorityRoot.'/generations/'.CAREER_RUNTIME_DIAGNOSTIC_GENERATION.'/generation-pointer.json';
    readBoundJson($authorityRoot, $immutablePath, CAREER_RUNTIME_DIAGNOSTIC_POINTER_SHA256, 'IMMUTABLE_POINTER');
    $receipt['immutable_pointer_readable'] = true;

    foreach ([
        'projection' => [CAREER_RUNTIME_DIAGNOSTIC_PROJECTION_SHA256, 'career_runtime_publish_projection', 'career-runtime-publish-projection.json'],
        'ledger' => [CAREER_RUNTIME_DIAGNOSTIC_LEDGER_SHA256, 'career_release_ledger', 'career-full-release-ledger.json'],
    ] as $key => [$expectedSha, $family, $filename]) {
        $relativePath = $payload['artifacts'][$key]['path'] ?? null;
        if (! is_string($relativePath)
            || preg_match('#^'.preg_quote($family, '#').'/[A-Za-z0-9][A-Za-z0-9._-]{0,127}/'.preg_quote($filename, '#').'$#D', $relativePath) !== 1) {
            throw new CareerRuntimeAuthorityDiagnosticFailure(strtoupper($key).'_DESCRIPTOR_INVALID');
        }
        readBoundJson($privateRoot, $privateRoot.'/'.$relativePath, $expectedSha, strtoupper($key));
        $receipt[$key.'_readable'] = true;
    }

    require_once $backendRoot.'/vendor/autoload.php';
    $app = require $backendRoot.'/bootstrap/app.php';
    if (! is_object($app) || ! method_exists($app, 'make')) {
        throw new CareerRuntimeAuthorityDiagnosticFailure('APPLICATION_BOOTSTRAP_INVALID');
    }
    $app->make(Kernel::class)->bootstrap();
    try {
        $loaded = $app->make(CareerGenerationAuthorityLoader::class)->loadStrict();
    } catch (Throwable $failure) {
        $safeCode = $failure->getMessage();
        if (preg_match('/^career_generation_[a-z0-9_]{1,120}$/D', $safeCode) !== 1) {
            $safeCode = 'career_generation_unclassified_failure';
        }
        throw new CareerRuntimeAuthorityDiagnosticFailure(strtoupper($safeCode));
    }
    $receipt['loader_strict_readable'] = true;

    $items = $loaded['projection']['items'] ?? null;
    if (! is_array($items)) {
        throw new CareerRuntimeAuthorityDiagnosticFailure('LOADER_PROJECTION_ITEMS_INVALID');
    }
    $slugs = [];
    $rows = [];
    $publishedSlugs = [];
    $publishedRows = [];
    foreach ($items as $item) {
        if (! is_array($item)) {
            throw new CareerRuntimeAuthorityDiagnosticFailure('LOADER_PROJECTION_ITEM_INVALID');
        }
        $slug = (string) ($item['slug'] ?? '');
        $locale = (string) ($item['locale'] ?? '');
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1 || ! in_array($locale, ['en', 'zh-CN'], true)) {
            throw new CareerRuntimeAuthorityDiagnosticFailure('LOADER_PROJECTION_IDENTITY_INVALID');
        }
        $slugs[$slug] = true;
        $rows[$slug.'|'.$locale] = true;
        if (($item['runtime_publish_state'] ?? null) === 'published') {
            $publishedSlugs[$slug] = true;
            $publishedRows[$slug.'|'.$locale] = true;
        }
    }
    $receipt['slug_count'] = count($slugs);
    $receipt['locale_row_count'] = count($rows);
    $receipt['published_slug_count'] = count($publishedSlugs);
    $receipt['published_locale_row_count'] = count($publishedRows);
    if ($receipt['slug_count'] !== 342 || $receipt['locale_row_count'] !== 684
        || $receipt['published_slug_count'] !== 30 || $receipt['published_locale_row_count'] !== 60) {
        throw new CareerRuntimeAuthorityDiagnosticFailure('LOADER_AUTHORITY_COUNT_MISMATCH');
    }

    $receipt['status'] = 'PASS_RUNTIME_AUTHORITY_READABLE';
    $receipt['failed_stage'] = null;
    emitDiagnostic($receipt);
    exit(0);
} catch (CareerRuntimeAuthorityDiagnosticFailure $failure) {
    $receipt['failed_stage'] = $failure->safeCode;
    emitDiagnostic($receipt);
    exit(1);
} catch (Throwable) {
    $receipt['failed_stage'] = 'UNEXPECTED_DIAGNOSTIC_FAILURE';
    emitDiagnostic($receipt);
    exit(1);
}

function requiredBackendRoot(): string
{
    $value = trim((string) getenv('CAREER_RUNTIME_DIAGNOSTIC_BACKEND_ROOT'));
    $real = $value !== '' ? realpath($value) : false;
    if (! is_string($real) || ! str_ends_with($real, '/backend') || is_link($value) || ! is_dir($real)) {
        throw new CareerRuntimeAuthorityDiagnosticFailure('BACKEND_ROOT_INVALID');
    }

    return $real;
}

function assertReadableDirectory(string $path, string $failure): void
{
    if (is_link($path) || ! is_dir($path) || ! is_readable($path) || ! is_executable($path)) {
        throw new CareerRuntimeAuthorityDiagnosticFailure($failure);
    }
}

/** @return array<string, mixed> */
function readBoundJson(string $root, string $path, string $expectedSha256, string $stage): array
{
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    if (! is_string($rootReal) || ! is_string($pathReal) || is_link($path)
        || ! str_starts_with($pathReal, $rootReal.'/') || ! is_file($pathReal) || ! is_readable($pathReal)) {
        throw new CareerRuntimeAuthorityDiagnosticFailure($stage.'_UNREADABLE');
    }
    $bytes = file_get_contents($pathReal);
    if (! is_string($bytes) || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
        throw new CareerRuntimeAuthorityDiagnosticFailure($stage.'_HASH_MISMATCH');
    }
    try {
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new CareerRuntimeAuthorityDiagnosticFailure($stage.'_JSON_INVALID');
    }
    if (! is_array($decoded)) {
        throw new CareerRuntimeAuthorityDiagnosticFailure($stage.'_JSON_INVALID');
    }

    return $decoded;
}

function safeShaEnv(string $name, int $length = 64): string
{
    $value = trim((string) getenv($name));

    return preg_match('/^[0-9a-f]{'.$length.'}$/D', $value) === 1 ? $value : str_repeat('0', $length);
}

/** @param array<string, mixed> $receipt */
function emitDiagnostic(array $receipt): void
{
    fwrite(STDOUT, json_encode(
        $receipt,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ).PHP_EOL);
}
