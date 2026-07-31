<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

use App\Models\CareerJobDisplayAsset;
use App\Models\Occupation;
use App\Services\Career\Bundles\CareerJobDisplaySurfaceBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Contracts\Console\Kernel;

const CONTRACT_VERSION = 'career.search_entry_batch.cache_thin_source_diagnostic_probe.v1';
const EXPECTED_CANDIDATES = 50;
const EXPECTED_URLS = 100;
const EXPECTED_THIN_URLS = 10;
const EXPECTED_COMPONENTS = 24;

/** @param array<string, mixed> $extra */
function emit(array $extra, int $exitCode = 0): never
{
    echo json_encode([
        'contract_version' => CONTRACT_VERSION,
        ...$extra,
        'server_write_count' => 0,
        'cache_write_count' => 0,
        'database_write_count' => 0,
        'cms_write_count' => 0,
        'publication_write_count' => 0,
        'indexability_write_count' => 0,
        'queue_dispatch_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_channel_action_count' => 0,
        'url_submission_count' => 0,
        'deploy_count' => 0,
        'rollback_count' => 0,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($exitCode);
}

function requiredEnv(string $name, string $pattern): string
{
    $value = trim((string) getenv($name));
    if ($value === '' || preg_match($pattern, $value) !== 1) {
        throw new RuntimeException('INVALID_INPUT');
    }

    return $value;
}

function canonicalJson(mixed $value): string
{
    if (is_array($value)) {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = json_decode(canonicalJson($item), true, 512, JSON_THROW_ON_ERROR);
        }
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function nestedData(array $payload, string $path): mixed
{
    $value = $payload;
    foreach (explode('.', $path) as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

/** @return list<string> */
function manifestSlugs(string $currentRelease, string $manifestSha256): array
{
    $manifestPath = $currentRelease.'/backend/content_packs/career/CAREER-SEARCH-ENTRY-QUALITY-BATCH-01/manifest.json';
    if (! is_file($manifestPath) || hash_file('sha256', $manifestPath) !== $manifestSha256) {
        throw new RuntimeException('MANIFEST_DRIFT');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $candidates = is_array($manifest['candidates'] ?? null) ? $manifest['candidates'] : [];
    $slugs = array_values(array_map(
        static fn (array $candidate): string => strtolower(trim((string) ($candidate['canonical_slug'] ?? ''))),
        $candidates,
    ));
    if (
        ($manifest['schema_version'] ?? null) !== 'career.search_entry_quality_batch_manifest.v1'
        || ($manifest['task_id'] ?? null) !== 'CAREER-SEARCH-ENTRY-QUALITY-BATCH-01'
        || count($slugs) !== EXPECTED_CANDIDATES
        || count(array_unique($slugs)) !== EXPECTED_CANDIDATES
        || in_array('', $slugs, true)
    ) {
        throw new RuntimeException('MANIFEST_CONTRACT_DRIFT');
    }

    return $slugs;
}

function installDatabaseWriteGuard(object $app): void
{
    $connection = $app->make('db')->connection();
    if (! method_exists($connection, 'beforeExecuting')) {
        throw new RuntimeException('DATABASE_GUARD_UNAVAILABLE');
    }
    $connection->beforeExecuting(static function (string $query): void {
        $normalized = strtolower(ltrim(preg_replace('/\s+/', ' ', $query) ?? $query));
        if (preg_match('/^(select|with|show|describe|desc|explain|pragma)\b/D', $normalized) !== 1) {
            throw new RuntimeException('DATABASE_WRITE_BLOCKED');
        }
    });
}

/** @param array<string, mixed> $payload */
function surfacePolicy(array $payload): string
{
    $policy = (string) nestedData(
        $payload,
        'display_surface_v1.implementation_contract.surface_policy',
    );

    return match ($policy) {
        'restricted_runtime_published_navigation_shell' => 'restricted_runtime_shell',
        '' => 'absent',
        default => 'other',
    };
}

/** @param array<string, mixed> $pages */
function normalizedPages(array $pages): array
{
    $localized = is_array($pages['page'] ?? null) ? $pages['page'] : $pages;

    return [
        'en' => is_array($localized['en'] ?? null) ? $localized['en'] : null,
        'zh' => is_array($localized['zh'] ?? null) ? $localized['zh'] : null,
    ];
}

/**
 * @param  list<array<string, mixed>>  $thinRows
 * @return array<string, mixed>
 */
function authorityGate(
    int $manifestPosition,
    string $slug,
    array $thinRows,
    CareerJobDisplaySurfaceBuilder $surfaceBuilder,
): array {
    $occupations = Occupation::query()
        ->with('crosswalks')
        ->where('canonical_slug', $slug)
        ->get();
    $occupationCount = $occupations->count();
    $occupation = $occupationCount === 1 ? $occupations->first() : null;

    $assets = CareerJobDisplayAsset::query()
        ->where('canonical_slug', $slug)
        ->where('surface_version', 'display.surface.v1')
        ->where('asset_version', 'v4.2')
        ->where('template_version', 'v4.2')
        ->where('status', 'ready_for_pilot')
        ->where('asset_type', 'career_job_public_display')
        ->get();
    $exactAssets = $occupation instanceof Occupation
        ? $assets->filter(
            static fn (CareerJobDisplayAsset $asset): bool => (string) $asset->occupation_id === (string) $occupation->id,
        )->values()
        : collect();
    $componentOrder24Count = $exactAssets->filter(
        static fn (CareerJobDisplayAsset $asset): bool => count(
            is_array($asset->component_order_json) ? array_values($asset->component_order_json) : [],
        ) === EXPECTED_COMPONENTS,
    )->count();
    $bilingualPageCount = $exactAssets->filter(static function (CareerJobDisplayAsset $asset): bool {
        $pages = normalizedPages(is_array($asset->page_payload_json) ? $asset->page_payload_json : []);

        return is_array($pages['en']) && is_array($pages['zh']);
    })->count();

    $crosswalkCounts = ['us_soc' => 0, 'onet_soc_2019' => 0];
    if ($occupation instanceof Occupation) {
        foreach ($occupation->crosswalks as $crosswalk) {
            $system = strtolower(trim((string) $crosswalk->source_system));
            if (array_key_exists($system, $crosswalkCounts) && trim((string) $crosswalk->source_code) !== '') {
                $crosswalkCounts[$system]++;
            }
        }
    }
    $surfaceReadyEn = $occupation instanceof Occupation
        && $surfaceBuilder->buildForOccupation($occupation, 'en') !== null;
    $surfaceReadyZh = $occupation instanceof Occupation
        && $surfaceBuilder->buildForOccupation($occupation, 'zh-CN') !== null;

    $classification = match (true) {
        $occupationCount !== 1 => 'occupation_identity_invalid',
        $exactAssets->count() === 0 => 'exact_display_asset_missing',
        $exactAssets->count() > 1 => 'exact_display_asset_duplicate',
        $componentOrder24Count !== 1 => 'component_order_invalid',
        $bilingualPageCount !== 1 => 'bilingual_pages_missing',
        $crosswalkCounts['us_soc'] !== 1 || $crosswalkCounts['onet_soc_2019'] !== 1 => 'crosswalk_authority_invalid',
        ! $surfaceReadyEn || ! $surfaceReadyZh => 'display_surface_gate_failed',
        count(array_filter(
            $thinRows,
            static fn (array $row): bool => $row['surface_policy'] === 'restricted_runtime_shell',
        )) === count($thinRows) => 'bundle_resolution_fell_back_to_runtime_shell',
        default => 'runtime_payload_thin_despite_full_surface',
    };

    return [
        'manifest_position' => $manifestPosition,
        'thin_locale_count' => count($thinRows),
        'thin_locales' => array_values(array_column($thinRows, 'locale')),
        'module_counts' => array_values(array_map(
            static fn (array $row): array => [
                'locale' => $row['locale'],
                'module_count' => $row['module_count'],
                'surface_policy' => $row['surface_policy'],
            ],
            $thinRows,
        )),
        'occupation_row_count' => $occupationCount,
        'exact_display_asset_row_count' => $exactAssets->count(),
        'component_order_24_row_count' => $componentOrder24Count,
        'bilingual_page_row_count' => $bilingualPageCount,
        'us_soc_crosswalk_count' => $crosswalkCounts['us_soc'],
        'onet_soc_2019_crosswalk_count' => $crosswalkCounts['onet_soc_2019'],
        'display_surface_ready_en' => $surfaceReadyEn,
        'display_surface_ready_zh' => $surfaceReadyZh,
        'classification' => $classification,
    ];
}

$probeMode = trim((string) getenv('CAREER_CACHE_THIN_SOURCE_PROBE_MODE'));
$releaseSha = trim((string) getenv('EXPECTED_RELEASE_SHA'));
$releaseName = trim((string) getenv('EXPECTED_RELEASE_NAME'));
$manifestSha256 = trim((string) getenv('EXPECTED_MANIFEST_SHA256'));

try {
    if (! in_array($probeMode, ['authority', 'runtime_cache'], true)) {
        throw new RuntimeException('INVALID_INPUT');
    }
    $deployPath = requiredEnv('DEPLOY_PATH', '#^/[A-Za-z0-9._/-]+$#D');
    if (str_contains($deployPath, '..')) {
        throw new RuntimeException('INVALID_INPUT');
    }
    $releaseSha = requiredEnv('EXPECTED_RELEASE_SHA', '/^[0-9a-f]{40}$/D');
    $releaseName = requiredEnv('EXPECTED_RELEASE_NAME', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D');
    $manifestSha256 = requiredEnv('EXPECTED_MANIFEST_SHA256', '/^[0-9a-f]{64}$/D');
    $expectedRuntimePayloadSet = requiredEnv(
        'EXPECTED_RUNTIME_PAYLOAD_SET_SHA256',
        '/^[0-9a-f]{64}$/D',
    );
    $currentRelease = realpath(rtrim($deployPath, '/').'/current');
    if (
        $currentRelease === false
        || basename($currentRelease) !== $releaseName
        || trim((string) file_get_contents($currentRelease.'/REVISION')) !== $releaseSha
        || file_exists(rtrim($deployPath, '/').'/.dep/deploy.lock')
    ) {
        throw new RuntimeException('ACTIVE_RELEASE_IDENTITY_DRIFT');
    }
    $slugs = manifestSlugs($currentRelease, $manifestSha256);
    $backendPath = $currentRelease.'/backend';
    chdir($backendPath);
    require $backendPath.'/vendor/autoload.php';
    $app = require $backendPath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    installDatabaseWriteGuard($app);

    $cache = $app->make(PublicCareerAuthorityResponseCache::class);
    $observations = [];
    foreach ($slugs as $index => $slug) {
        foreach (['en', 'zh-CN'] as $locale) {
            $readiness = $cache->jobDetailCacheReadiness($slug, $locale);
            $payload = is_array($readiness['payload'] ?? null) ? $readiness['payload'] : [];
            $content = nestedData($payload, 'display_surface_v1.page.content');
            $observations[] = [
                'manifest_position' => $index + 1,
                'slug' => $slug,
                'locale' => $locale,
                'classification' => (string) ($readiness['classification'] ?? 'unavailable'),
                'module_count' => is_array($content) ? count($content) : 0,
                'surface_policy' => surfacePolicy($payload),
                'payload_sha256' => hash('sha256', canonicalJson($payload)),
            ];
        }
    }
    $payloadSet = array_map(
        static fn (array $row): array => [
            'slug' => $row['slug'],
            'locale' => $row['locale'],
            'payload_sha256' => $row['payload_sha256'],
        ],
        $observations,
    );
    $payloadSetSha256 = hash('sha256', canonicalJson($payloadSet));
    $readyActiveCount = count(array_filter(
        $observations,
        static fn (array $row): bool => $row['classification'] === 'ready_active',
    ));
    $thinRows = array_values(array_filter(
        $observations,
        static fn (array $row): bool => $row['module_count'] < 20,
    ));
    $sanitizedThinRows = array_map(
        static fn (array $row): array => [
            'manifest_position' => $row['manifest_position'],
            'locale' => $row['locale'],
            'module_count' => $row['module_count'],
            'surface_policy' => $row['surface_policy'],
        ],
        $thinRows,
    );
    $thinTargetSetSha256 = hash('sha256', canonicalJson($sanitizedThinRows));
    $stateMatches = $payloadSetSha256 === $expectedRuntimePayloadSet
        && $readyActiveCount === EXPECTED_URLS
        && count($thinRows) === EXPECTED_THIN_URLS;
    if (! $stateMatches) {
        emit([
            'status' => 'HOLD_SOURCE_STATE_DRIFT',
            'probe_mode' => $probeMode,
            'release_sha' => $releaseSha,
            'release_name' => $releaseName,
            'manifest_sha256' => $manifestSha256,
            'runtime_payload_set_sha256' => $payloadSetSha256,
            'ready_active_count' => $readyActiveCount,
            'thin_url_count' => count($thinRows),
            'thin_target_set_sha256' => $thinTargetSetSha256,
            'thin_rows' => $sanitizedThinRows,
            'authority_rows' => [],
            'authority_state_sha256' => null,
            'database_read_execution' => false,
        ]);
    }

    $authorityRows = [];
    if ($probeMode === 'authority') {
        $surfaceBuilder = $app->make(CareerJobDisplaySurfaceBuilder::class);
        foreach (array_values(array_unique(array_column($thinRows, 'manifest_position'))) as $position) {
            $positionRows = array_values(array_filter(
                $thinRows,
                static fn (array $row): bool => $row['manifest_position'] === $position,
            ));
            $authorityRows[] = authorityGate(
                (int) $position,
                $slugs[(int) $position - 1],
                $positionRows,
                $surfaceBuilder,
            );
        }
    }
    $authorityStateSha256 = $probeMode === 'authority'
        ? hash('sha256', canonicalJson($authorityRows))
        : null;

    emit([
        'status' => 'PASS_PROBE_COMPLETE',
        'probe_mode' => $probeMode,
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'runtime_payload_set_sha256' => $payloadSetSha256,
        'ready_active_count' => $readyActiveCount,
        'thin_url_count' => count($thinRows),
        'thin_slug_count' => count(array_unique(array_column($thinRows, 'manifest_position'))),
        'thin_target_set_sha256' => $thinTargetSetSha256,
        'thin_rows' => $sanitizedThinRows,
        'authority_rows' => $authorityRows,
        'authority_state_sha256' => $authorityStateSha256,
        'runtime_identity_sha256' => hash('sha256', canonicalJson([
            'release_sha' => $releaseSha,
            'release_name' => $releaseName,
            'manifest_sha256' => $manifestSha256,
            'cache_default' => (string) config('cache.default'),
        ])),
        'database_read_execution' => $probeMode === 'authority',
    ]);
} catch (Throwable $throwable) {
    emit([
        'status' => 'FAIL_CLOSED',
        'probe_mode' => in_array($probeMode, ['authority', 'runtime_cache'], true) ? $probeMode : null,
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'failure_category' => in_array($throwable->getMessage(), [
            'INVALID_INPUT',
            'ACTIVE_RELEASE_IDENTITY_DRIFT',
            'MANIFEST_DRIFT',
            'MANIFEST_CONTRACT_DRIFT',
            'DATABASE_GUARD_UNAVAILABLE',
            'DATABASE_WRITE_BLOCKED',
        ], true) ? $throwable->getMessage() : 'unexpected',
    ], 1);
}
