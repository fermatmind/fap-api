<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Contracts\Console\Kernel;

const CONTRACT_VERSION = 'career.search_entry_batch.cache_post_readback_diagnostic_probe.v1';
const EXPECTED_CANDIDATES = 50;
const EXPECTED_URLS = 100;

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

/** @param array<string, mixed> $payload */
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
    $walk(nestedData($payload, 'display_surface_v1.page.content'));

    return $count;
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

/** @return array{http: int, body: string, transport_failed: bool, cache_header: string} */
function fetchFreshPublicPayload(string $slug, string $locale, string $binding): array
{
    $url = 'https://api.fermatmind.com/api/v0.5/career/jobs/'
        .rawurlencode($slug)
        .'?locale='.rawurlencode($locale)
        .'&cache_readback='.substr($binding, 0, 16);
    $headers = [];
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('CURL_RUNTIME_UNAVAILABLE');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        },
    ]);
    $body = curl_exec($handle);
    $errno = curl_errno($handle);
    $http = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return [
        'http' => $http,
        'body' => is_string($body) ? $body : '{}',
        'transport_failed' => $body === false || $errno !== CURLE_OK,
        'cache_header' => strtolower((string) ($headers['x-fermat-public-read-cache'] ?? '')),
    ];
}

/** @param list<array<string, mixed>> $observations */
function summarize(array $observations, string $manifestSha256): array
{
    $count = static fn (callable $predicate): int => count(array_filter($observations, $predicate));
    $payloadSet = array_map(
        static fn (array $row): array => [
            'slug' => $row['slug'],
            'locale' => $row['locale'],
            'payload_sha256' => $row['payload_sha256'],
        ],
        $observations,
    );

    return [
        'manifest_sha256' => $manifestSha256,
        'slug_count' => count(array_unique(array_column($observations, 'slug'))),
        'url_count' => count($observations),
        'ready_active_count' => $count(static fn (array $row): bool => $row['classification'] === 'ready_active'),
        'http_200_count' => $count(static fn (array $row): bool => $row['http'] === 200),
        'transport_failure_count' => $count(static fn (array $row): bool => $row['transport_failed']),
        'bad_href_url_count' => $count(static fn (array $row): bool => $row['bad_href_count'] > 0),
        'low_module_url_count' => $count(static fn (array $row): bool => $row['module_count'] < 20),
        'fresh_cache_header_count' => $count(static fn (array $row): bool => $row['cache_header'] === 'fresh'),
        'payload_set_sha256' => hash('sha256', canonicalJson($payloadSet)),
    ];
}

$probeMode = trim((string) getenv('CAREER_CACHE_POST_READBACK_PROBE_MODE'));
$releaseSha = trim((string) getenv('EXPECTED_RELEASE_SHA'));
$releaseName = trim((string) getenv('EXPECTED_RELEASE_NAME'));
$manifestSha256 = trim((string) getenv('EXPECTED_MANIFEST_SHA256'));

try {
    if (! in_array($probeMode, ['runtime_cache', 'public_fresh'], true)) {
        throw new RuntimeException('INVALID_INPUT');
    }
    $deployPath = requiredEnv('DEPLOY_PATH', '#^/[A-Za-z0-9._/-]+$#D');
    if (str_contains($deployPath, '..')) {
        throw new RuntimeException('INVALID_INPUT');
    }
    $releaseSha = requiredEnv('EXPECTED_RELEASE_SHA', '/^[0-9a-f]{40}$/D');
    $releaseName = requiredEnv('EXPECTED_RELEASE_NAME', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D');
    $manifestSha256 = requiredEnv('EXPECTED_MANIFEST_SHA256', '/^[0-9a-f]{64}$/D');
    $failedReceiptSha256 = requiredEnv('EXPECTED_FAILED_RECEIPT_SHA256', '/^[0-9a-f]{64}$/D');
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
    $observations = [];

    if ($probeMode === 'runtime_cache') {
        $backendPath = $currentRelease.'/backend';
        chdir($backendPath);
        require $backendPath.'/vendor/autoload.php';
        $app = require $backendPath.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $connection = $app->make('db')->connection();
        if (! method_exists($connection, 'beforeExecuting')) {
            throw new RuntimeException('DATABASE_GUARD_UNAVAILABLE');
        }
        $connection->beforeExecuting(static function (string $query): void {
            throw new RuntimeException('DATABASE_QUERY_BLOCKED');
        });
        $cache = $app->make(PublicCareerAuthorityResponseCache::class);
        foreach ($slugs as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $readiness = $cache->jobDetailCacheReadiness($slug, $locale);
                $payload = is_array($readiness['payload'] ?? null) ? $readiness['payload'] : [];
                $content = nestedData($payload, 'display_surface_v1.page.content');
                $observations[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'classification' => (string) ($readiness['classification'] ?? 'unavailable'),
                    'http' => 0,
                    'transport_failed' => false,
                    'bad_href_count' => unsafeHrefCount($payload, $locale === 'en' ? '/en/' : '/zh/'),
                    'module_count' => is_array($content) ? count($content) : 0,
                    'cache_header' => '',
                    'payload_sha256' => hash('sha256', canonicalJson($payload)),
                ];
            }
        }
    } else {
        foreach ($slugs as $slug) {
            foreach (['en', 'zh-CN'] as $locale) {
                $response = fetchFreshPublicPayload($slug, $locale, $failedReceiptSha256);
                $decoded = json_decode($response['body'], true);
                $payload = is_array($decoded) ? $decoded : [];
                $content = nestedData($payload, 'display_surface_v1.page.content');
                $observations[] = [
                    'slug' => $slug,
                    'locale' => $locale,
                    'classification' => '',
                    'http' => $response['http'],
                    'transport_failed' => $response['transport_failed'],
                    'bad_href_count' => unsafeHrefCount($payload, $locale === 'en' ? '/en/' : '/zh/'),
                    'module_count' => is_array($content) ? count($content) : 0,
                    'cache_header' => $response['cache_header'],
                    'payload_sha256' => hash('sha256', $response['body']),
                ];
            }
        }
    }

    emit([
        'status' => 'PASS_PROBE_COMPLETE',
        'probe_mode' => $probeMode,
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        ...summarize($observations, $manifestSha256),
        'runtime_identity_sha256' => hash('sha256', canonicalJson([
            'probe_mode' => $probeMode,
            'release_sha' => $releaseSha,
            'release_name' => $releaseName,
            'manifest_sha256' => $manifestSha256,
            'cache_default' => $probeMode === 'runtime_cache' ? (string) config('cache.default') : null,
        ])),
    ]);
} catch (Throwable $throwable) {
    emit([
        'status' => 'FAIL_CLOSED',
        'probe_mode' => in_array($probeMode, ['runtime_cache', 'public_fresh'], true) ? $probeMode : null,
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'failure_category' => in_array($throwable->getMessage(), [
            'INVALID_INPUT',
            'ACTIVE_RELEASE_IDENTITY_DRIFT',
            'MANIFEST_DRIFT',
            'MANIFEST_CONTRACT_DRIFT',
            'DATABASE_GUARD_UNAVAILABLE',
            'DATABASE_QUERY_BLOCKED',
            'CURL_RUNTIME_UNAVAILABLE',
        ], true) ? $throwable->getMessage() : 'unexpected',
    ], 1);
}
