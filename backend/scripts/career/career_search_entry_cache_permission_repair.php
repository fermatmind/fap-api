<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

const CONTRACT_VERSION = 'career.search_entry_batch.cache_permission_repair.v1';
const EXPECTED_CANDIDATES = 50;
const MAX_FIRST_LEVEL_DIRECTORIES = 256;
const MAX_SECOND_LEVEL_DIRECTORIES = 65536;
const DIRECTORY_MODE = 02775;
const FILE_MODE = 0664;

/**
 * @param  array<string, mixed>  $extra
 */
function emit(array $extra, int $exitCode = 0): never
{
    echo json_encode([
        'contract_version' => CONTRACT_VERSION,
        ...$extra,
        'cache_payload_write_count' => 0,
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

function requiredIntegerEnv(string $name, int $minimum, int $maximum): int
{
    $value = requiredEnv($name, '/^(0|[1-9][0-9]*)$/D');
    $integer = (int) $value;
    if ($integer < $minimum || $integer > $maximum) {
        throw new RuntimeException('INVALID_INPUT');
    }

    return $integer;
}

/**
 * @return array{uid: int, gid: int}
 */
function expectedOwnerGroup(string $owner, string $group): array
{
    if (
        ! function_exists('posix_geteuid')
        || ! function_exists('posix_getpwnam')
        || ! function_exists('posix_getgrnam')
        || posix_geteuid() !== 0
    ) {
        throw new RuntimeException('ROOT_IDENTITY_REQUIRED');
    }
    $ownerRecord = posix_getpwnam($owner);
    $groupRecord = posix_getgrnam($group);
    if (! is_array($ownerRecord) || ! is_array($groupRecord)) {
        throw new RuntimeException('EXPECTED_IDENTITY_UNAVAILABLE');
    }

    return ['uid' => (int) $ownerRecord['uid'], 'gid' => (int) $groupRecord['gid']];
}

/**
 * @return array{dev: int, ino: int, mode: int, uid: int, gid: int}
 */
function nodeStat(string $path, bool $directory): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (
        ! is_array($stat)
        || is_link($path)
        || ($directory && ! is_dir($path))
        || (! $directory && ! is_file($path))
    ) {
        throw new RuntimeException($directory ? 'DIRECTORY_STATE_UNAVAILABLE' : 'FILE_STATE_UNAVAILABLE');
    }

    return [
        'dev' => (int) $stat['dev'],
        'ino' => (int) $stat['ino'],
        'mode' => (int) $stat['mode'],
        'uid' => (int) $stat['uid'],
        'gid' => (int) $stat['gid'],
    ];
}

/**
 * @param  array{dev: int, ino: int, mode: int, uid: int, gid: int}  $stat
 */
function directoryNeedsRepair(array $stat, int $uid, int $gid): bool
{
    return $stat['uid'] !== $uid
        || $stat['gid'] !== $gid
        || ($stat['mode'] & 0020) !== 0020
        || ($stat['mode'] & 02000) !== 02000;
}

/**
 * @param  array{dev: int, ino: int, mode: int, uid: int, gid: int}  $stat
 */
function directoryDesiredPolicyMatches(array $stat, int $uid, int $gid): bool
{
    return $stat['uid'] === $uid
        && $stat['gid'] === $gid
        && ($stat['mode'] & 07777) === DIRECTORY_MODE;
}

/**
 * @param  array{dev: int, ino: int, mode: int, uid: int, gid: int}  $stat
 */
function fileNeedsRepair(array $stat, int $uid, int $gid): bool
{
    return $stat['uid'] !== $uid
        || $stat['gid'] !== $gid
        || ($stat['mode'] & 0020) !== 0020;
}

/**
 * @param  array{dev: int, ino: int, mode: int, uid: int, gid: int}  $stat
 */
function fileDesiredPolicyMatches(array $stat, int $uid, int $gid): bool
{
    return $stat['uid'] === $uid
        && $stat['gid'] === $gid
        && ($stat['mode'] & 0777) === FILE_MODE;
}

/**
 * @param  list<string>  $keys
 * @return list<string>
 */
function exactCachePaths(string $cacheRoot, array $keys): array
{
    $paths = [];
    foreach ($keys as $key) {
        $hash = sha1($key);
        $paths[] = $cacheRoot.'/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
    }

    return array_values(array_unique($paths));
}

/**
 * @param  list<string>  $slugs
 * @return list<string>
 */
function stableCacheKeys(array $slugs): array
{
    $keys = [];
    foreach ($slugs as $slug) {
        foreach (['en', 'zh-CN'] as $locale) {
            $base = "career:public-authority:job-detail:v3:{$slug}:{$locale}";
            $keys[] = "{$base}:active";
            $keys[] = "{$base}:lkg";
            $keys[] = "{$base}:negative";
            $keys[] = "career:public-authority:job-detail:v1:{$slug}:{$locale}";
        }
    }

    return $keys;
}

/**
 * @param  list<array{label: string, path: string, directory: bool, stat: array{dev: int, ino: int, mode: int, uid: int, gid: int}}>  $candidates
 */
function candidateSetSha256(array $candidates): string
{
    $labels = array_column($candidates, 'label');
    sort($labels, SORT_STRING);

    return hash('sha256', json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  list<string>  $stablePaths
 */
function payloadSetSha256(array $stablePaths): string
{
    $payloads = [];
    foreach ($stablePaths as $path) {
        if (! file_exists($path) && ! is_link($path)) {
            continue;
        }
        nodeStat($path, false);
        $payloadSha256 = hash_file('sha256', $path);
        if (! is_string($payloadSha256)) {
            throw new RuntimeException('FILE_PAYLOAD_UNAVAILABLE');
        }
        $payloads[] = [
            'index' => substr(hash('sha256', $path), 0, 32),
            'payload_sha256' => $payloadSha256,
        ];
    }
    usort($payloads, static fn (array $left, array $right): int => $left['index'] <=> $right['index']);

    return hash('sha256', json_encode($payloads, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @return array{
 *   candidates: list<array{label: string, path: string, directory: bool, stat: array{dev: int, ino: int, mode: int, uid: int, gid: int}}>,
 *   first_level_count: int,
 *   second_level_count: int,
 *   stable_file_count: int,
 *   stable_paths: list<string>
 * }
 */
function repairState(string $storageRoot, string $cacheRoot, array $slugs, int $uid, int $gid): array
{
    foreach ([
        $storageRoot,
        $storageRoot.'/framework',
        $storageRoot.'/framework/cache',
        $cacheRoot,
    ] as $path) {
        if (directoryNeedsRepair(nodeStat($path, true), $uid, $gid)) {
            throw new RuntimeException('FIXED_CACHE_CHAIN_DRIFT');
        }
    }

    $candidates = [];
    $firstLevelCount = 0;
    $secondLevelCount = 0;
    $unexpectedEntryCount = 0;
    $firstEntries = @scandir($cacheRoot);
    if (! is_array($firstEntries)) {
        throw new RuntimeException('CACHE_DIRECTORY_SCAN_UNAVAILABLE');
    }
    foreach ($firstEntries as $firstName) {
        if ($firstName === '.' || $firstName === '..') {
            continue;
        }
        $firstPath = $cacheRoot.'/'.$firstName;
        if (preg_match('/^[0-9a-f]{2}$/D', $firstName) !== 1 || ! is_dir($firstPath) || is_link($firstPath)) {
            $unexpectedEntryCount++;

            continue;
        }
        $firstLevelCount++;
        if ($firstLevelCount > MAX_FIRST_LEVEL_DIRECTORIES) {
            throw new RuntimeException('CACHE_DIRECTORY_SCAN_BOUND_EXCEEDED');
        }
        $stat = nodeStat($firstPath, true);
        if (directoryNeedsRepair($stat, $uid, $gid)) {
            $candidates[] = [
                'label' => 'hash-directory:'.$firstName,
                'path' => $firstPath,
                'directory' => true,
                'stat' => $stat,
            ];
        }

        $secondEntries = @scandir($firstPath);
        if (! is_array($secondEntries)) {
            throw new RuntimeException('CACHE_DIRECTORY_SCAN_UNAVAILABLE');
        }
        foreach ($secondEntries as $secondName) {
            if ($secondName === '.' || $secondName === '..') {
                continue;
            }
            $secondPath = $firstPath.'/'.$secondName;
            if (preg_match('/^[0-9a-f]{2}$/D', $secondName) !== 1 || ! is_dir($secondPath) || is_link($secondPath)) {
                $unexpectedEntryCount++;

                continue;
            }
            $secondLevelCount++;
            if ($secondLevelCount > MAX_SECOND_LEVEL_DIRECTORIES) {
                throw new RuntimeException('CACHE_DIRECTORY_SCAN_BOUND_EXCEEDED');
            }
            $stat = nodeStat($secondPath, true);
            if (directoryNeedsRepair($stat, $uid, $gid)) {
                $candidates[] = [
                    'label' => 'hash-directory:'.$firstName.'/'.$secondName,
                    'path' => $secondPath,
                    'directory' => true,
                    'stat' => $stat,
                ];
            }
        }
    }
    if ($unexpectedEntryCount !== 0) {
        throw new RuntimeException('UNEXPECTED_CACHE_ENTRY');
    }

    $stablePaths = exactCachePaths($cacheRoot, stableCacheKeys($slugs));
    $stableFileCount = 0;
    foreach ($stablePaths as $path) {
        if (! file_exists($path) && ! is_link($path)) {
            continue;
        }
        $stableFileCount++;
        $stat = nodeStat($path, false);
        if (fileNeedsRepair($stat, $uid, $gid)) {
            $candidates[] = [
                'label' => 'stable-key-file:'.substr(hash('sha256', $path), 0, 32),
                'path' => $path,
                'directory' => false,
                'stat' => $stat,
            ];
        }
    }

    return [
        'candidates' => $candidates,
        'first_level_count' => $firstLevelCount,
        'second_level_count' => $secondLevelCount,
        'stable_file_count' => $stableFileCount,
        'stable_paths' => $stablePaths,
    ];
}

$completedCount = 0;
$completedDirectoryCount = 0;
$completedFileCount = 0;
$writeState = 'none';

try {
    $deployPath = requiredEnv('DEPLOY_PATH', '#^/[A-Za-z0-9._/-]+$#D');
    if (str_contains($deployPath, '..')) {
        throw new RuntimeException('INVALID_INPUT');
    }
    $releaseSha = requiredEnv('EXPECTED_RELEASE_SHA', '/^[0-9a-f]{40}$/D');
    $releaseName = requiredEnv('EXPECTED_RELEASE_NAME', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D');
    $manifestSha256 = requiredEnv('EXPECTED_MANIFEST_SHA256', '/^[0-9a-f]{64}$/D');
    $expectedOwner = requiredEnv('EXPECTED_SHARED_OWNER', '/^[A-Za-z_][A-Za-z0-9_.-]{0,31}$/D');
    $expectedGroup = requiredEnv('EXPECTED_SHARED_GROUP', '/^[A-Za-z_][A-Za-z0-9_.-]{0,31}$/D');
    $expectedRepairCount = requiredIntegerEnv('EXPECTED_REPAIR_CANDIDATE_COUNT', 1, 70000);
    $expectedRepairSetSha256 = requiredEnv('EXPECTED_REPAIR_CANDIDATE_SET_SHA256', '/^[0-9a-f]{64}$/D');
    $expectedStableFileCount = requiredIntegerEnv('EXPECTED_STABLE_FILE_COUNT', 1, 400);
    if (requiredEnv('PERMISSION_REPAIR_APPLY', '/^true$/D') !== 'true') {
        throw new RuntimeException('EXPLICIT_REPAIR_REQUIRED');
    }
    $ids = expectedOwnerGroup($expectedOwner, $expectedGroup);

    $currentRelease = realpath(rtrim($deployPath, '/').'/current');
    if (
        $currentRelease === false
        || basename($currentRelease) !== $releaseName
        || trim((string) @file_get_contents($currentRelease.'/REVISION')) !== $releaseSha
        || file_exists(rtrim($deployPath, '/').'/.dep/deploy.lock')
    ) {
        throw new RuntimeException('ACTIVE_RELEASE_IDENTITY_DRIFT');
    }
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
        || ($manifest['expected_candidate_count'] ?? null) !== EXPECTED_CANDIDATES
        || count($slugs) !== EXPECTED_CANDIDATES
        || count(array_unique($slugs)) !== EXPECTED_CANDIDATES
        || in_array('', $slugs, true)
    ) {
        throw new RuntimeException('MANIFEST_CONTRACT_DRIFT');
    }

    $storageRoot = realpath($currentRelease.'/backend/storage');
    $cacheRoot = realpath($currentRelease.'/backend/storage/framework/cache/data');
    if (
        $storageRoot === false
        || $cacheRoot === false
        || ! str_starts_with($cacheRoot.'/', rtrim($storageRoot, '/').'/')
    ) {
        throw new RuntimeException('CACHE_ROOT_UNAVAILABLE');
    }

    $before = repairState($storageRoot, $cacheRoot, $slugs, $ids['uid'], $ids['gid']);
    $beforeCandidates = $before['candidates'];
    $beforeSetSha256 = candidateSetSha256($beforeCandidates);
    if (
        count($beforeCandidates) !== $expectedRepairCount
        || ! hash_equals($expectedRepairSetSha256, $beforeSetSha256)
        || $before['stable_file_count'] !== $expectedStableFileCount
    ) {
        throw new RuntimeException('REPAIR_SET_DRIFT');
    }
    $beforePayloadSetSha256 = payloadSetSha256($before['stable_paths']);

    foreach ($beforeCandidates as $candidate) {
        $latest = nodeStat($candidate['path'], $candidate['directory']);
        if (
            $latest['dev'] !== $candidate['stat']['dev']
            || $latest['ino'] !== $candidate['stat']['ino']
            || $latest['uid'] !== $candidate['stat']['uid']
            || $latest['gid'] !== $candidate['stat']['gid']
            || $latest['mode'] !== $candidate['stat']['mode']
        ) {
            throw new RuntimeException('REPAIR_TARGET_DRIFT');
        }
        if (
            ! chown($candidate['path'], $ids['uid'])
            || ! chgrp($candidate['path'], $ids['gid'])
            || ! chmod($candidate['path'], $candidate['directory'] ? DIRECTORY_MODE : FILE_MODE)
        ) {
            throw new RuntimeException('PERMISSION_METADATA_WRITE_FAILED');
        }
        $verified = nodeStat($candidate['path'], $candidate['directory']);
        $verifiedPolicy = $candidate['directory']
            ? directoryDesiredPolicyMatches($verified, $ids['uid'], $ids['gid'])
            : fileDesiredPolicyMatches($verified, $ids['uid'], $ids['gid']);
        if (! $verifiedPolicy) {
            throw new RuntimeException('PERMISSION_METADATA_VERIFY_FAILED');
        }
        $completedCount++;
        $completedDirectoryCount += $candidate['directory'] ? 1 : 0;
        $completedFileCount += $candidate['directory'] ? 0 : 1;
        $writeState = 'partial';
    }

    $after = repairState($storageRoot, $cacheRoot, $slugs, $ids['uid'], $ids['gid']);
    $afterPayloadSetSha256 = payloadSetSha256($after['stable_paths']);
    if (
        $after['candidates'] !== []
        || $after['stable_file_count'] !== $expectedStableFileCount
        || ! hash_equals($beforePayloadSetSha256, $afterPayloadSetSha256)
    ) {
        throw new RuntimeException('POST_REPAIR_VERIFY_FAILED');
    }
    $writeState = 'committed';

    emit([
        'status' => 'PASS_PERMISSION_REPAIR_COMMITTED',
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'repair_candidate_count' => $expectedRepairCount,
        'repair_candidate_set_sha256' => $beforeSetSha256,
        'first_level_hash_directory_count' => $before['first_level_count'],
        'second_level_hash_directory_count' => $before['second_level_count'],
        'stable_file_count' => $before['stable_file_count'],
        'permission_metadata_write_count' => $completedCount,
        'permission_directory_write_count' => $completedDirectoryCount,
        'permission_file_write_count' => $completedFileCount,
        'pre_repair_payload_set_sha256' => $beforePayloadSetSha256,
        'post_repair_payload_set_sha256' => $afterPayloadSetSha256,
        'payload_unchanged' => true,
        'post_repair_candidate_count' => 0,
        'write_state' => $writeState,
    ]);
} catch (Throwable $throwable) {
    emit([
        'status' => 'FAIL_PERMISSION_REPAIR',
        'failure_category' => in_array($throwable->getMessage(), [
            'ACTIVE_RELEASE_IDENTITY_DRIFT',
            'CACHE_DIRECTORY_SCAN_BOUND_EXCEEDED',
            'CACHE_DIRECTORY_SCAN_UNAVAILABLE',
            'CACHE_ROOT_UNAVAILABLE',
            'DIRECTORY_STATE_UNAVAILABLE',
            'EXPECTED_IDENTITY_UNAVAILABLE',
            'EXPLICIT_REPAIR_REQUIRED',
            'FILE_PAYLOAD_UNAVAILABLE',
            'FILE_STATE_UNAVAILABLE',
            'FIXED_CACHE_CHAIN_DRIFT',
            'MANIFEST_CONTRACT_DRIFT',
            'MANIFEST_DRIFT',
            'PERMISSION_METADATA_VERIFY_FAILED',
            'PERMISSION_METADATA_WRITE_FAILED',
            'POST_REPAIR_VERIFY_FAILED',
            'REPAIR_SET_DRIFT',
            'REPAIR_TARGET_DRIFT',
            'ROOT_IDENTITY_REQUIRED',
            'UNEXPECTED_CACHE_ENTRY',
        ], true) ? $throwable->getMessage() : 'UNEXPECTED_REPAIR_FAILURE',
        'permission_metadata_write_count' => $completedCount,
        'permission_directory_write_count' => $completedDirectoryCount,
        'permission_file_write_count' => $completedFileCount,
        'write_state' => $writeState,
    ], 1);
}
