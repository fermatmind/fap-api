<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

use App\Services\Analytics\CareerConversionClosureBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Career\Review\CareerSearchEntryQualityBatchPlanner;
use Illuminate\Contracts\Console\Kernel;

const CONTRACT_VERSION = 'career.search_entry_batch.cache_refresh.resume.v1';
const EXPECTED_CANDIDATES = 50;
const EXPECTED_URLS = 100;
const EXPECTED_REVIEW_TARGETS = 300;
const MAX_BATCH_TARGETS = 10;
const MAX_BATCH_SLUGS = 5;
const OFFLINE_BUILD_BUDGET_MS = 5000;
const POST_AUTHORITY_MANIFEST_POSITIONS = [30, 33, 34, 40, 42];

/**
 * @param  array<string, mixed>  $extra
 */
function receipt(array $extra): array
{
    return [
        'contract_version' => CONTRACT_VERSION,
        'cache_write_count' => 0,
        ...$extra,
        'database_write_count' => 0,
        'cms_write_count' => 0,
        'publication_write_count' => 0,
        'indexability_write_count' => 0,
        'queue_dispatch_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_channel_action_count' => 0,
        'url_submission_count' => 0,
        'non_target_write_count' => 0,
        'deploy_count' => 0,
        'rollback_count' => 0,
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function emit(array $payload, int $exitCode = 0): never
{
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
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

function optionalEnv(string $name): string
{
    return trim((string) getenv($name));
}

function integerEnv(string $name, int $minimum, int $maximum): int
{
    $value = requiredEnv($name, '/^(0|[1-9][0-9]*)$/D');
    $integer = (int) $value;
    if ($integer < $minimum || $integer > $maximum) {
        throw new RuntimeException('INVALID_INPUT');
    }

    return $integer;
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

/**
 * @param  array<string, mixed>  $payload
 */
function unsafeHrefCount(array $payload, string $prefix): int
{
    $count = 0;
    $walk = static function (mixed $node) use (&$walk, &$count, $prefix): void {
        if (! is_array($node)) {
            return;
        }
        if (is_string($node['href'] ?? null)) {
            $href = $node['href'];
            if (str_contains($href, ' | ') || (str_starts_with($href, '/') && ! str_starts_with($href, $prefix))) {
                $count++;
            }
        }
        foreach ($node as $item) {
            $walk($item);
        }
    };
    $walk(data_get($payload, 'display_surface_v1.page.content'));

    return $count;
}

/**
 * @return array{http_code: int, body: string, transport_failed: bool}
 */
function fetchPublicPayload(string $slug, string $locale): array
{
    $url = 'https://api.fermatmind.com/api/v0.5/career/jobs/'
        .rawurlencode($slug).'?locale='.rawurlencode($locale);
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('CURL_INIT_FAILED');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($body !== false && $errno === CURLE_OK) {
            return [
                'http_code' => $httpCode,
                'body' => (string) $body,
                'transport_failed' => false,
            ];
        }
        if ($attempt === 1) {
            sleep(1);
        }
    }

    return [
        'http_code' => 0,
        'body' => '{}',
        'transport_failed' => true,
    ];
}

/**
 * @param  list<string>  $slugs
 * @return array{
 *   summary: array<string, int|string>,
 *   observations: list<array<string, mixed>>,
 *   affected: list<array<string, mixed>>
 * }
 */
function publicSnapshot(array $slugs, string $manifestSha256): array
{
    $observations = [];
    foreach ($slugs as $slug) {
        foreach (['en', 'zh-CN'] as $locale) {
            $response = fetchPublicPayload($slug, $locale);
            $decoded = json_decode($response['body'], true);
            $payload = is_array($decoded) ? $decoded : [];
            $localePath = $locale === 'en' ? 'en' : 'zh';
            $content = data_get($payload, 'display_surface_v1.page.content');
            $observations[] = [
                'slug' => $slug,
                'locale' => $locale,
                'http' => $response['http_code'],
                'transport_failed' => $response['transport_failed'],
                'canonical_ok' => data_get($payload, 'seo_contract.canonical_path')
                    === "/{$localePath}/career/jobs/{$slug}",
                'robots_ok' => data_get($payload, 'seo_contract.robots_policy') === 'index,follow',
                'locale_ok' => data_get($payload, 'display_surface_v1.page.locale') === $locale,
                'bad_href_count' => unsafeHrefCount($payload, "/{$localePath}/"),
                'module_count' => is_array($content) ? count($content) : 0,
                'payload_sha256' => hash('sha256', $response['body']),
            ];
        }
    }

    $count = static fn (callable $predicate): int => count(array_filter($observations, $predicate));
    $payloadSet = array_map(
        static fn (array $row): array => [
            'slug' => $row['slug'],
            'locale' => $row['locale'],
            'payload_sha256' => $row['payload_sha256'],
        ],
        $observations,
    );
    $summary = [
        'manifest_sha256' => $manifestSha256,
        'slug_count' => count(array_unique(array_column($observations, 'slug'))),
        'url_count' => count($observations),
        'http_200_count' => $count(static fn (array $row): bool => $row['http'] === 200),
        'transport_failure_count' => $count(static fn (array $row): bool => $row['transport_failed']),
        'non_200_response_count' => $count(
            static fn (array $row): bool => ! $row['transport_failed'] && $row['http'] !== 200,
        ),
        'canonical_ok_count' => $count(static fn (array $row): bool => $row['canonical_ok']),
        'robots_ok_count' => $count(static fn (array $row): bool => $row['robots_ok']),
        'locale_ok_count' => $count(static fn (array $row): bool => $row['locale_ok']),
        'bad_href_url_count' => $count(static fn (array $row): bool => $row['bad_href_count'] > 0),
        'low_module_url_count' => $count(static fn (array $row): bool => $row['module_count'] < 20),
        'payload_set_sha256' => hash(
            'sha256',
            json_encode($payloadSet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ),
    ];
    $affected = array_values(array_filter(
        $observations,
        static fn (array $row): bool => $row['bad_href_count'] > 0 || $row['module_count'] < 20,
    ));

    return [
        'summary' => $summary,
        'observations' => $observations,
        'affected' => $affected,
    ];
}

/**
 * @param  list<array<string, mixed>>  $targets
 * @return list<list<array<string, mixed>>>
 */
function targetBatches(array $targets): array
{
    $batches = [];
    $current = [];
    $currentSlugs = [];
    foreach ($targets as $target) {
        $slug = (string) $target['slug'];
        $nextSlugCount = count(array_unique([...$currentSlugs, $slug]));
        if ($current !== [] && (count($current) >= MAX_BATCH_TARGETS || $nextSlugCount > MAX_BATCH_SLUGS)) {
            $batches[] = $current;
            $current = [];
            $currentSlugs = [];
        }
        $current[] = $target;
        $currentSlugs[] = $slug;
    }
    if ($current !== []) {
        $batches[] = $current;
    }

    return $batches;
}

/**
 * @param  list<array<string, mixed>>  $targets
 */
function targetSetSha256(array $targets): string
{
    $state = array_map(
        static fn (array $row): array => [
            'slug' => $row['slug'],
            'locale' => $row['locale'],
            'payload_sha256' => $row['payload_sha256'],
            'bad_href' => $row['bad_href_count'] > 0,
            'thin_module' => $row['module_count'] < 20,
        ],
        $targets,
    );

    return hash(
        'sha256',
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  list<array<string, mixed>>  $candidates
 * @return list<array{manifest_position: int, slug: string, locale: string}>
 */
function postAuthorityTargets(array $candidates): array
{
    $targets = [];
    foreach (POST_AUTHORITY_MANIFEST_POSITIONS as $position) {
        $candidate = $candidates[$position - 1] ?? null;
        $slug = is_array($candidate) ? trim((string) ($candidate['canonical_slug'] ?? '')) : '';
        if ($slug === '') {
            throw new RuntimeException('POST_AUTHORITY_TARGET_DRIFT');
        }
        foreach (['en', 'zh-CN'] as $locale) {
            $targets[] = [
                'manifest_position' => $position,
                'slug' => $slug,
                'locale' => $locale,
            ];
        }
    }

    if (
        count($targets) !== 10
        || count(array_unique(array_column($targets, 'slug'))) !== 5
    ) {
        throw new RuntimeException('POST_AUTHORITY_TARGET_DRIFT');
    }

    return $targets;
}

/**
 * @param  list<array{manifest_position: int, slug: string, locale: string}>  $targets
 */
function postAuthorityTargetSetSha256(array $targets): string
{
    return hash(
        'sha256',
        json_encode($targets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  array<string, int|string>  $summary
 */
function assertCompletePublicSnapshot(array $summary): void
{
    if (! publicSnapshotIsComplete($summary)) {
        throw new RuntimeException('PUBLIC_SNAPSHOT_INCOMPLETE');
    }
}

/**
 * @param  array<string, int|string>  $summary
 */
function publicSnapshotIsComplete(array $summary): bool
{
    return ! (
        $summary['slug_count'] !== EXPECTED_CANDIDATES
        || $summary['url_count'] !== EXPECTED_URLS
        || $summary['http_200_count'] !== EXPECTED_URLS
        || $summary['transport_failure_count'] !== 0
        || $summary['non_200_response_count'] !== 0
        || $summary['canonical_ok_count'] !== EXPECTED_URLS
        || $summary['robots_ok_count'] !== EXPECTED_URLS
        || $summary['locale_ok_count'] !== EXPECTED_URLS
    );
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

function assertActiveReleaseInterfaces(): void
{
    if (! function_exists('curl_init')) {
        throw new RuntimeException('CURL_RUNTIME_UNAVAILABLE');
    }
    foreach ([
        [PublicCareerAuthorityResponseCache::class, 'warmJobDetailPayloadForOfflineBootstrap', 3],
        [CareerConversionClosureBuilder::class, 'buildForSubjectSlugs', 1],
        [CareerSearchEntryQualityBatchPlanner::class, 'build', 0],
    ] as [$class, $method, $parameters]) {
        if (! class_exists($class)) {
            throw new RuntimeException('ACTIVE_RELEASE_INTERFACE_DRIFT');
        }
        $reflection = new ReflectionMethod($class, $method);
        if (! $reflection->isPublic() || $reflection->getNumberOfParameters() !== $parameters) {
            throw new RuntimeException('ACTIVE_RELEASE_INTERFACE_DRIFT');
        }
    }
    $cache = new ReflectionClass(PublicCareerAuthorityResponseCache::class);
    if (
        ! $cache->hasConstant('JOB_DETAIL_OFFLINE_BOOTSTRAP_BUILD_BUDGET_MS')
        || $cache->getConstant('JOB_DETAIL_OFFLINE_BOOTSTRAP_BUILD_BUDGET_MS') !== OFFLINE_BUILD_BUDGET_MS
    ) {
        throw new RuntimeException('ACTIVE_RELEASE_INTERFACE_DRIFT');
    }
}

$mode = optionalEnv('CAREER_CACHE_RESUME_MODE');
$releaseSha = optionalEnv('EXPECTED_RELEASE_SHA');
$releaseName = optionalEnv('EXPECTED_RELEASE_NAME');
$manifestSha256 = optionalEnv('EXPECTED_MANIFEST_SHA256');
$baselinePayloadSetSha256 = optionalEnv('EXPECTED_BASELINE_PAYLOAD_SET_SHA256');
$expectedBadHrefCount = 0;
$expectedLowModuleCount = 0;
$cacheRefreshTargetCount = 0;
$completedBatchCount = 0;
$failedStage = null;
$failureCategory = null;
$failedTargetIndexSha256 = null;
$buildMsTotal = 0.0;
$buildMsMax = 0.0;
$postSummary = null;
$postReadbackStateSha256 = null;
$qualityPackageSha256 = null;
$reviewPackageSha256 = null;
$reviewTargetSetSha256 = null;
$preWriteQualityPackageSha256 = null;
$preWriteReviewPackageSha256 = null;
$preWriteReviewTargetSetSha256 = null;
$preSummary = null;

try {
    if (! in_array($mode, ['diagnose', 'preflight', 'execute', 'post_authority_execute'], true)) {
        throw new RuntimeException('INVALID_MODE');
    }
    $deployPath = requiredEnv('DEPLOY_PATH', '#^/[A-Za-z0-9._/-]+$#D');
    if (str_contains($deployPath, '..')) {
        throw new RuntimeException('INVALID_DEPLOY_PATH');
    }
    $releaseSha = requiredEnv('EXPECTED_RELEASE_SHA', '/^[0-9a-f]{40}$/D');
    $releaseName = requiredEnv('EXPECTED_RELEASE_NAME', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D');
    $manifestSha256 = requiredEnv('EXPECTED_MANIFEST_SHA256', '/^[0-9a-f]{64}$/D');
    if ($mode !== 'post_authority_execute') {
        $baselinePayloadSetSha256 = requiredEnv(
            'EXPECTED_BASELINE_PAYLOAD_SET_SHA256',
            '/^[0-9a-f]{64}$/D',
        );
        $expectedBadHrefCount = integerEnv('EXPECTED_BAD_HREF_URL_COUNT', 1, EXPECTED_URLS);
        $expectedLowModuleCount = integerEnv('EXPECTED_LOW_MODULE_URL_COUNT', 0, EXPECTED_URLS);
    }

    $currentRelease = realpath(rtrim($deployPath, '/').'/current');
    if (
        $currentRelease === false
        || basename($currentRelease) !== $releaseName
        || trim((string) file_get_contents($currentRelease.'/REVISION')) !== $releaseSha
        || file_exists(rtrim($deployPath, '/').'/.dep/deploy.lock')
    ) {
        throw new RuntimeException('ACTIVE_RELEASE_IDENTITY_DRIFT');
    }
    $backendPath = $currentRelease.'/backend';
    $manifestPath = $backendPath.'/content_packs/career/CAREER-SEARCH-ENTRY-QUALITY-BATCH-01/manifest.json';
    if (
        ! is_file($manifestPath)
        || hash_file('sha256', $manifestPath) !== $manifestSha256
    ) {
        throw new RuntimeException('MANIFEST_DRIFT');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $candidates = array_values(
        is_array($manifest['candidates'] ?? null) ? $manifest['candidates'] : [],
    );
    $slugs = array_values(array_map(
        static fn (array $candidate): string => (string) ($candidate['canonical_slug'] ?? ''),
        $candidates,
    ));
    if (
        ($manifest['schema_version'] ?? null) !== 'career.search_entry_quality_batch_manifest.v1'
        || ($manifest['task_id'] ?? null) !== 'CAREER-SEARCH-ENTRY-QUALITY-BATCH-01'
        || ($manifest['expected_candidate_count'] ?? null) !== EXPECTED_CANDIDATES
        || count($slugs) !== EXPECTED_CANDIDATES
        || count(array_unique($slugs)) !== EXPECTED_CANDIDATES
    ) {
        throw new RuntimeException('MANIFEST_CONTRACT_DRIFT');
    }

    chdir($backendPath);
    require $backendPath.'/vendor/autoload.php';
    $app = require $backendPath.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    assertActiveReleaseInterfaces();
    installDatabaseWriteGuard($app);

    $preSnapshot = publicSnapshot($slugs, $manifestSha256);
    $preSummary = $preSnapshot['summary'];
    if ($mode === 'post_authority_execute') {
        assertCompletePublicSnapshot($preSummary);
        $targets = postAuthorityTargets($candidates);
        $targetSetSha256 = postAuthorityTargetSetSha256($targets);
        $batches = targetBatches($targets);
        $expectedTargetSet = requiredEnv(
            'EXPECTED_RESUME_TARGET_SET_SHA256',
            '/^[0-9a-f]{64}$/D',
        );
        if (
            ! hash_equals($expectedTargetSet, $targetSetSha256)
            || count($targets) !== 10
            || count($batches) !== 1
        ) {
            throw new RuntimeException('POST_AUTHORITY_TARGET_DRIFT');
        }
        $preflightStateSha256 = hash('sha256', canonicalJson([
            'authority_repair_receipt_sha256' => requiredEnv(
                'EXPECTED_AUTHORITY_REPAIR_RECEIPT_SHA256',
                '/^[0-9a-f]{64}$/D',
            ),
            'manifest_sha256' => $manifestSha256,
            'manifest_positions' => POST_AUTHORITY_MANIFEST_POSITIONS,
            'payload_set_sha256' => $preSummary['payload_set_sha256'],
            'target_set_sha256' => $targetSetSha256,
        ]));
        $preWriteQuality = $app->make(CareerSearchEntryQualityBatchPlanner::class)->build();
        if (
            ($preWriteQuality['candidate_count'] ?? null) !== EXPECTED_CANDIDATES
            || ($preWriteQuality['bilingual_url_count'] ?? null) !== EXPECTED_URLS
            || ($preWriteQuality['target_count'] ?? null) !== EXPECTED_REVIEW_TARGETS
        ) {
            throw new RuntimeException('POST_AUTHORITY_QUALITY_DRIFT');
        }
        $preWriteQualityPackageSha256 = (string) ($preWriteQuality['quality_package_sha256'] ?? '');
        $preWriteReviewPackageSha256 = (string) ($preWriteQuality['package_sha256'] ?? '');
        $preWriteReviewTargetSetSha256 = (string) ($preWriteQuality['target_set_sha256'] ?? '');
        foreach ([
            $preWriteQualityPackageSha256,
            $preWriteReviewPackageSha256,
            $preWriteReviewTargetSetSha256,
        ] as $hash) {
            if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                throw new RuntimeException('POST_AUTHORITY_QUALITY_DRIFT');
            }
        }
    } elseif ($mode === 'diagnose') {
        $snapshotComplete = publicSnapshotIsComplete($preSummary);
        $baselineMatch = $snapshotComplete
            && $preSummary['payload_set_sha256'] === $baselinePayloadSetSha256
            && $preSummary['bad_href_url_count'] === $expectedBadHrefCount
            && $preSummary['low_module_url_count'] === $expectedLowModuleCount;
        $status = ! $snapshotComplete
            ? 'HOLD_DIAGNOSTIC_SNAPSHOT_INCOMPLETE'
            : ($baselineMatch
                ? 'PASS_DIAGNOSTIC_COMPLETE_BASELINE_MATCH'
                : 'PASS_DIAGNOSTIC_COMPLETE_STATE_DRIFT');
        $diagnosticStateSha256 = hash('sha256', canonicalJson([
            'manifest_sha256' => $manifestSha256,
            'summary' => $preSummary,
            'snapshot_complete' => $snapshotComplete,
            'recovery_baseline_match' => $baselineMatch,
        ]));
        emit(receipt([
            'mode' => 'diagnose',
            'status' => $status,
            'release_sha' => $releaseSha,
            'release_name' => $releaseName,
            'manifest_sha256' => $manifestSha256,
            'diagnostic_state_sha256' => $diagnosticStateSha256,
            'observed_payload_set_sha256' => $preSummary['payload_set_sha256'],
            'observed_slug_count' => $preSummary['slug_count'],
            'observed_url_count' => $preSummary['url_count'],
            'observed_http_200_count' => $preSummary['http_200_count'],
            'observed_transport_failure_count' => $preSummary['transport_failure_count'],
            'observed_non_200_response_count' => $preSummary['non_200_response_count'],
            'observed_canonical_ok_count' => $preSummary['canonical_ok_count'],
            'observed_robots_ok_count' => $preSummary['robots_ok_count'],
            'observed_locale_ok_count' => $preSummary['locale_ok_count'],
            'observed_bad_href_url_count' => $preSummary['bad_href_url_count'],
            'observed_low_module_url_count' => $preSummary['low_module_url_count'],
            'snapshot_complete' => $snapshotComplete,
            'recovery_baseline_match' => $baselineMatch,
            'write_state' => 'none',
            'production_write_execution' => false,
            'cache_refresh_target_count' => 0,
            'completed_batch_count' => 0,
            'per_target_retry_limit' => 0,
        ]));
    } else {
        assertCompletePublicSnapshot($preSummary);
        if (
            $preSummary['payload_set_sha256'] !== $baselinePayloadSetSha256
            || $preSummary['bad_href_url_count'] !== $expectedBadHrefCount
            || $preSummary['low_module_url_count'] !== $expectedLowModuleCount
        ) {
            throw new RuntimeException('RECOVERY_STATE_DRIFT');
        }
        $targets = $preSnapshot['affected'];
        $targetSetSha256 = targetSetSha256($targets);
        $batches = targetBatches($targets);
        if ($targets === [] || $batches === []) {
            throw new RuntimeException('RECOVERY_STATE_DRIFT');
        }
        $preflightStateSha256 = hash('sha256', canonicalJson([
            'manifest_sha256' => $manifestSha256,
            'payload_set_sha256' => $preSummary['payload_set_sha256'],
            'bad_href_url_count' => $preSummary['bad_href_url_count'],
            'low_module_url_count' => $preSummary['low_module_url_count'],
            'resume_target_count' => count($targets),
            'resume_batch_count' => count($batches),
            'resume_target_set_sha256' => $targetSetSha256,
        ]));

        if ($mode === 'preflight') {
            emit(receipt([
                'mode' => 'preflight',
                'status' => 'PASS_PREFLIGHT_RESUME_REQUIRED',
                'release_sha' => $releaseSha,
                'release_name' => $releaseName,
                'manifest_sha256' => $manifestSha256,
                'preflight_state_sha256' => $preflightStateSha256,
                'pre_refresh_payload_set_sha256' => $preSummary['payload_set_sha256'],
                'resume_target_set_sha256' => $targetSetSha256,
                'candidate_count' => EXPECTED_CANDIDATES,
                'bilingual_url_count' => EXPECTED_URLS,
                'bad_href_url_count' => $preSummary['bad_href_url_count'],
                'low_module_url_count' => $preSummary['low_module_url_count'],
                'resume_target_count' => count($targets),
                'resume_batch_count' => count($batches),
                'max_batch_target_count' => MAX_BATCH_TARGETS,
                'max_batch_slug_count' => MAX_BATCH_SLUGS,
                'offline_build_budget_ms' => OFFLINE_BUILD_BUDGET_MS,
                'per_target_retry_limit' => 0,
                'write_state' => 'none',
                'production_write_execution' => false,
                'cache_refresh_target_count' => 0,
                'completed_batch_count' => 0,
            ]));
        }

        $expectedPreflightState = requiredEnv('EXPECTED_PREFLIGHT_STATE_SHA256', '/^[0-9a-f]{64}$/D');
        $expectedTargetSet = requiredEnv('EXPECTED_RESUME_TARGET_SET_SHA256', '/^[0-9a-f]{64}$/D');
        $expectedTargetCount = integerEnv('EXPECTED_RESUME_TARGET_COUNT', 1, EXPECTED_URLS);
        $expectedBatchCount = integerEnv('EXPECTED_RESUME_BATCH_COUNT', 1, EXPECTED_URLS);
        if (
            ! hash_equals($expectedPreflightState, $preflightStateSha256)
            || ! hash_equals($expectedTargetSet, $targetSetSha256)
            || $expectedTargetCount !== count($targets)
            || $expectedBatchCount !== count($batches)
        ) {
            throw new RuntimeException('BOUND_PREFLIGHT_STATE_DRIFT');
        }
    }

    $conversion = $app->make(CareerConversionClosureBuilder::class);
    $cache = $app->make(PublicCareerAuthorityResponseCache::class);
    foreach ($batches as $batchIndex => $batch) {
        $batchSlugs = array_values(array_unique(array_column($batch, 'slug')));
        if (count($batch) > MAX_BATCH_TARGETS || count($batchSlugs) > MAX_BATCH_SLUGS) {
            throw new RuntimeException('BATCH_BOUNDARY_DRIFT');
        }
        $closures = $conversion->buildForSubjectSlugs($batchSlugs);
        foreach ($batch as $targetIndex => $target) {
            $slug = (string) $target['slug'];
            $locale = (string) $target['locale'];
            $closure = $closures[$slug] ?? null;
            if (! is_array($closure)) {
                $failedStage = 'precompute_conversion_closure';
                $failureCategory = 'unexpected';
                throw new RuntimeException('SAFE_EXECUTE_FAILURE');
            }
            $result = $cache->warmJobDetailPayloadForOfflineBootstrap($slug, $locale, $closure);
            $buildMs = round((float) ($result['build_ms'] ?? 0.0), 3);
            $buildMsTotal = round($buildMsTotal + $buildMs, 3);
            $buildMsMax = max($buildMsMax, $buildMs);
            if (($result['status'] ?? null) !== 'cached') {
                $failedStage = in_array(
                    $result['failure_stage'] ?? null,
                    ['build_detail_payload', 'publish_cache_payload'],
                    true,
                ) ? $result['failure_stage'] : 'build_detail_payload';
                $failureCategory = in_array(
                    $result['error_category'] ?? null,
                    [
                        'build_budget_exceeded',
                        'cache_publish_failed',
                        'database_permanent_read',
                        'database_transient_read',
                        'payload_not_cached',
                        'unexpected',
                    ],
                    true,
                ) ? $result['error_category'] : 'unexpected';
                $failedTargetIndexSha256 = hash(
                    'sha256',
                    $targetSetSha256.'|'.($batchIndex * MAX_BATCH_TARGETS + $targetIndex),
                );
                throw new RuntimeException('SAFE_EXECUTE_FAILURE');
            }
            $cacheRefreshTargetCount++;
        }
        $completedBatchCount++;
        sleep(2);
    }

    $postSnapshot = publicSnapshot($slugs, $manifestSha256);
    $postSummary = $postSnapshot['summary'];
    assertCompletePublicSnapshot($postSummary);
    $postReadbackStateSha256 = hash('sha256', canonicalJson([
        'summary' => $postSummary,
        'observations' => $postSnapshot['observations'],
    ]));
    if (
        $postSummary['bad_href_url_count'] !== 0
        || $postSummary['low_module_url_count'] !== 0
    ) {
        $failedStage = 'post_refresh_public_readback';
        $failureCategory = 'quality_incomplete';
        throw new RuntimeException('SAFE_EXECUTE_FAILURE');
    }

    $quality = $app->make(CareerSearchEntryQualityBatchPlanner::class)->build();
    if (
        ($quality['candidate_count'] ?? null) !== EXPECTED_CANDIDATES
        || ($quality['bilingual_url_count'] ?? null) !== EXPECTED_URLS
        || ($quality['target_count'] ?? null) !== EXPECTED_REVIEW_TARGETS
    ) {
        $failedStage = 'post_refresh_exact_quality_package';
        $failureCategory = 'quality_incomplete';
        throw new RuntimeException('SAFE_EXECUTE_FAILURE');
    }
    $qualityPackageSha256 = (string) ($quality['quality_package_sha256'] ?? '');
    $reviewPackageSha256 = (string) ($quality['package_sha256'] ?? '');
    $reviewTargetSetSha256 = (string) ($quality['target_set_sha256'] ?? '');
    foreach ([$qualityPackageSha256, $reviewPackageSha256, $reviewTargetSetSha256] as $hash) {
        if (preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
            $failedStage = 'post_refresh_exact_quality_package';
            $failureCategory = 'quality_incomplete';
            throw new RuntimeException('SAFE_EXECUTE_FAILURE');
        }
    }
    if (
        $mode === 'post_authority_execute'
        && (
            ! hash_equals((string) $preWriteQualityPackageSha256, $qualityPackageSha256)
            || ! hash_equals((string) $preWriteReviewPackageSha256, $reviewPackageSha256)
            || ! hash_equals((string) $preWriteReviewTargetSetSha256, $reviewTargetSetSha256)
        )
    ) {
        $failedStage = 'post_refresh_exact_quality_package';
        $failureCategory = 'quality_incomplete';
        throw new RuntimeException('SAFE_EXECUTE_FAILURE');
    }

    emit(receipt([
        'mode' => $mode,
        'status' => $mode === 'post_authority_execute'
            ? 'PASS_POST_AUTHORITY_EXECUTE_AND_READBACK'
            : 'PASS_EXECUTE_AND_READBACK',
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'preflight_state_sha256' => $preflightStateSha256,
        'pre_refresh_payload_set_sha256' => $preSummary['payload_set_sha256'],
        'resume_target_set_sha256' => $targetSetSha256,
        'post_refresh_payload_set_sha256' => $postSummary['payload_set_sha256'],
        'post_refresh_readback_state_sha256' => $postReadbackStateSha256,
        'quality_package_sha256' => $qualityPackageSha256,
        'review_package_sha256' => $reviewPackageSha256,
        'review_target_set_sha256' => $reviewTargetSetSha256,
        'candidate_count' => EXPECTED_CANDIDATES,
        'bilingual_url_count' => EXPECTED_URLS,
        'review_target_count' => EXPECTED_REVIEW_TARGETS,
        'pre_bad_href_url_count' => $preSummary['bad_href_url_count'],
        'pre_low_module_url_count' => $preSummary['low_module_url_count'],
        'post_bad_href_url_count' => 0,
        'post_low_module_url_count' => 0,
        'resume_target_count' => count($targets),
        'resume_batch_count' => count($batches),
        'max_batch_target_count' => MAX_BATCH_TARGETS,
        'max_batch_slug_count' => MAX_BATCH_SLUGS,
        'offline_build_budget_ms' => OFFLINE_BUILD_BUDGET_MS,
        'per_target_retry_limit' => 0,
        'write_state' => $mode === 'post_authority_execute' ? 'committed_verified' : 'committed',
        'production_write_execution' => true,
        'cache_write_count' => $cacheRefreshTargetCount,
        'cache_refresh_target_count' => $cacheRefreshTargetCount,
        'completed_batch_count' => $completedBatchCount,
        'build_ms_total' => $buildMsTotal,
        'build_ms_max' => round($buildMsMax, 3),
        'failure_stage' => null,
        'failure_category' => null,
        'failed_target_index_sha256' => null,
    ]));
} catch (Throwable $throwable) {
    $safeInputFailure = in_array(
        $throwable->getMessage(),
        [
            'INVALID_MODE',
            'INVALID_INPUT',
            'INVALID_DEPLOY_PATH',
            'ACTIVE_RELEASE_IDENTITY_DRIFT',
            'MANIFEST_DRIFT',
            'MANIFEST_CONTRACT_DRIFT',
            'PUBLIC_SNAPSHOT_INCOMPLETE',
            'RECOVERY_STATE_DRIFT',
            'BOUND_PREFLIGHT_STATE_DRIFT',
            'DATABASE_GUARD_UNAVAILABLE',
            'DATABASE_WRITE_BLOCKED',
            'CURL_RUNTIME_UNAVAILABLE',
            'ACTIVE_RELEASE_INTERFACE_DRIFT',
            'BATCH_BOUNDARY_DRIFT',
            'POST_AUTHORITY_TARGET_DRIFT',
            'POST_AUTHORITY_QUALITY_DRIFT',
        ],
        true,
    ) ? $throwable->getMessage() : null;
    $failedStage ??= $cacheRefreshTargetCount > 0 ? 'execute_resume_batch' : 'pre_write_validation';
    $failureCategory ??= $safeInputFailure ?? 'unexpected';
    $writeState = $cacheRefreshTargetCount === 0
        ? 'none'
        : ($postSummary === null ? 'partial' : 'committed_unverified');

    emit(receipt([
        'mode' => in_array(
            $mode,
            ['diagnose', 'preflight', 'execute', 'post_authority_execute'],
            true,
        ) ? $mode : null,
        'status' => $cacheRefreshTargetCount === 0 ? 'FAIL_CLOSED' : 'FAIL_PARTIAL',
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'failed_stage' => $failedStage,
        'failure_category' => $failureCategory,
        'failed_target_index_sha256' => $failedTargetIndexSha256,
        'write_state' => $writeState,
        'production_write_execution' => $cacheRefreshTargetCount > 0,
        'cache_write_count' => $cacheRefreshTargetCount,
        'cache_refresh_target_count' => $cacheRefreshTargetCount,
        'completed_batch_count' => $completedBatchCount,
        'build_ms_total' => $buildMsTotal,
        'build_ms_max' => round($buildMsMax, 3),
        'post_refresh_readback_state_sha256' => $postReadbackStateSha256,
        'quality_package_sha256' => $qualityPackageSha256,
        'review_package_sha256' => $reviewPackageSha256,
        'review_target_set_sha256' => $reviewTargetSetSha256,
        'per_target_retry_limit' => 0,
        'observed_payload_set_sha256' => is_array($preSummary)
            ? ($preSummary['payload_set_sha256'] ?? null)
            : null,
        'observed_url_count' => is_array($preSummary) ? ($preSummary['url_count'] ?? 0) : 0,
        'observed_http_200_count' => is_array($preSummary) ? ($preSummary['http_200_count'] ?? 0) : 0,
        'observed_transport_failure_count' => is_array($preSummary)
            ? ($preSummary['transport_failure_count'] ?? 0)
            : 0,
        'observed_non_200_response_count' => is_array($preSummary)
            ? ($preSummary['non_200_response_count'] ?? 0)
            : 0,
        'observed_canonical_ok_count' => is_array($preSummary)
            ? ($preSummary['canonical_ok_count'] ?? 0)
            : 0,
        'observed_robots_ok_count' => is_array($preSummary) ? ($preSummary['robots_ok_count'] ?? 0) : 0,
        'observed_locale_ok_count' => is_array($preSummary) ? ($preSummary['locale_ok_count'] ?? 0) : 0,
        'observed_bad_href_url_count' => is_array($preSummary)
            ? ($preSummary['bad_href_url_count'] ?? 0)
            : 0,
        'observed_low_module_url_count' => is_array($preSummary)
            ? ($preSummary['low_module_url_count'] ?? 0)
            : 0,
    ]), 1);
}
