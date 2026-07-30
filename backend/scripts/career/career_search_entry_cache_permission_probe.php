<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

const CONTRACT_VERSION = 'career.search_entry_batch.cache_permission_probe.v1';
const EXPECTED_CANDIDATES = 50;
const EXPECTED_URLS = 100;
const MAX_FIRST_LEVEL_DIRECTORIES = 256;
const MAX_SECOND_LEVEL_DIRECTORIES = 65536;

/**
 * @param  array<string, mixed>  $extra
 */
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

/**
 * @return array{uid: int, gid: int}
 */
function expectedOwnerGroup(string $owner, string $group): array
{
    if (! function_exists('posix_getpwnam') || ! function_exists('posix_getgrnam')) {
        throw new RuntimeException('POSIX_IDENTITY_UNAVAILABLE');
    }
    $ownerRecord = posix_getpwnam($owner);
    $groupRecord = posix_getgrnam($group);
    if (! is_array($ownerRecord) || ! is_array($groupRecord)) {
        throw new RuntimeException('EXPECTED_IDENTITY_UNAVAILABLE');
    }

    return ['uid' => (int) $ownerRecord['uid'], 'gid' => (int) $groupRecord['gid']];
}

/**
 * @return array{capability_ok: bool, expected_owner_group: bool, group_write: bool, setgid: bool}
 */
function inspectDirectory(string $path, int $expectedUid, int $expectedGid): array
{
    $stat = @lstat($path);
    if (! is_array($stat) || ! is_dir($path) || is_link($path)) {
        throw new RuntimeException('DIRECTORY_STATE_UNAVAILABLE');
    }
    $mode = (int) $stat['mode'];

    return [
        'capability_ok' => is_readable($path) && is_writable($path) && is_executable($path),
        'expected_owner_group' => (int) $stat['uid'] === $expectedUid && (int) $stat['gid'] === $expectedGid,
        'group_write' => ($mode & 0020) === 0020,
        'setgid' => ($mode & 02000) === 02000,
    ];
}

/**
 * @return array{capability_ok: bool, expected_owner_group: bool, group_write: bool}
 */
function inspectFile(string $path, int $expectedUid, int $expectedGid): array
{
    $stat = @lstat($path);
    if (! is_array($stat) || ! is_file($path) || is_link($path)) {
        throw new RuntimeException('FILE_STATE_UNAVAILABLE');
    }
    $mode = (int) $stat['mode'];

    return [
        'capability_ok' => is_readable($path) && is_writable($path),
        'expected_owner_group' => (int) $stat['uid'] === $expectedUid && (int) $stat['gid'] === $expectedGid,
        'group_write' => ($mode & 0020) === 0020,
    ];
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

try {
    $deployPath = requiredEnv('DEPLOY_PATH', '#^/[A-Za-z0-9._/-]+$#D');
    if (str_contains($deployPath, '..')) {
        throw new RuntimeException('INVALID_INPUT');
    }
    $releaseSha = requiredEnv('EXPECTED_RELEASE_SHA', '/^[0-9a-f]{40}$/D');
    $releaseName = requiredEnv('EXPECTED_RELEASE_NAME', '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D');
    $manifestSha256 = requiredEnv('EXPECTED_MANIFEST_SHA256', '/^[0-9a-f]{64}$/D');
    $identityRole = requiredEnv('PROBE_IDENTITY_ROLE', '/^(deploy_runner|php_runtime)$/D');
    $expectedOwner = requiredEnv('EXPECTED_SHARED_OWNER', '/^[A-Za-z_][A-Za-z0-9_.-]{0,31}$/D');
    $expectedGroup = requiredEnv('EXPECTED_SHARED_GROUP', '/^[A-Za-z_][A-Za-z0-9_.-]{0,31}$/D');
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

    $directoryCapabilityFailureCount = 0;
    $directoryOwnerGroupMismatchCount = 0;
    $directoryGroupWriteMissingCount = 0;
    $directorySetgidMissingCount = 0;
    $chainCapabilityFailureCount = 0;
    $chainPolicyMismatchCount = 0;
    $hashDirectoryCapabilityFailureCount = 0;
    $hashDirectoryPolicyMismatchCount = 0;
    $repairCandidates = [];
    $chainPaths = [
        $storageRoot,
        $storageRoot.'/framework',
        $storageRoot.'/framework/cache',
        $cacheRoot,
    ];
    foreach ($chainPaths as $chainIndex => $path) {
        $state = inspectDirectory($path, $ids['uid'], $ids['gid']);
        $directoryCapabilityFailureCount += $state['capability_ok'] ? 0 : 1;
        $directoryOwnerGroupMismatchCount += $state['expected_owner_group'] ? 0 : 1;
        $directoryGroupWriteMissingCount += $state['group_write'] ? 0 : 1;
        $directorySetgidMissingCount += $state['setgid'] ? 0 : 1;
        $chainCapabilityFailureCount += $state['capability_ok'] ? 0 : 1;
        $chainPolicyMismatchCount += (
            $state['expected_owner_group'] && $state['group_write'] && $state['setgid']
        ) ? 0 : 1;
        if (
            ! $state['capability_ok']
            || ! $state['expected_owner_group']
            || ! $state['group_write']
            || ! $state['setgid']
        ) {
            $repairCandidates[] = 'chain-directory:'.$chainIndex;
        }
    }

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
        $state = inspectDirectory($firstPath, $ids['uid'], $ids['gid']);
        $directoryCapabilityFailureCount += $state['capability_ok'] ? 0 : 1;
        $directoryOwnerGroupMismatchCount += $state['expected_owner_group'] ? 0 : 1;
        $directoryGroupWriteMissingCount += $state['group_write'] ? 0 : 1;
        $directorySetgidMissingCount += $state['setgid'] ? 0 : 1;
        $hashDirectoryCapabilityFailureCount += $state['capability_ok'] ? 0 : 1;
        $hashDirectoryPolicyMismatchCount += (
            $state['expected_owner_group'] && $state['group_write'] && $state['setgid']
        ) ? 0 : 1;
        if (
            ! $state['capability_ok']
            || ! $state['expected_owner_group']
            || ! $state['group_write']
            || ! $state['setgid']
        ) {
            $repairCandidates[] = 'hash-directory:'.$firstName;
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
            $state = inspectDirectory($secondPath, $ids['uid'], $ids['gid']);
            $directoryCapabilityFailureCount += $state['capability_ok'] ? 0 : 1;
            $directoryOwnerGroupMismatchCount += $state['expected_owner_group'] ? 0 : 1;
            $directoryGroupWriteMissingCount += $state['group_write'] ? 0 : 1;
            $directorySetgidMissingCount += $state['setgid'] ? 0 : 1;
            $hashDirectoryCapabilityFailureCount += $state['capability_ok'] ? 0 : 1;
            $hashDirectoryPolicyMismatchCount += (
                $state['expected_owner_group'] && $state['group_write'] && $state['setgid']
            ) ? 0 : 1;
            if (
                ! $state['capability_ok']
                || ! $state['expected_owner_group']
                || ! $state['group_write']
                || ! $state['setgid']
            ) {
                $repairCandidates[] = 'hash-directory:'.$firstName.'/'.$secondName;
            }
        }
    }

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
    $targetPaths = exactCachePaths($cacheRoot, $keys);
    $existingTargetFileCount = 0;
    $targetFileCapabilityFailureCount = 0;
    $targetFileOwnerGroupMismatchCount = 0;
    $targetFileGroupWriteMissingCount = 0;
    foreach ($targetPaths as $path) {
        if (! file_exists($path) && ! is_link($path)) {
            continue;
        }
        $existingTargetFileCount++;
        $state = inspectFile($path, $ids['uid'], $ids['gid']);
        $targetFileCapabilityFailureCount += $state['capability_ok'] ? 0 : 1;
        $targetFileOwnerGroupMismatchCount += $state['expected_owner_group'] ? 0 : 1;
        $targetFileGroupWriteMissingCount += $state['group_write'] ? 0 : 1;
        if (! $state['capability_ok'] || ! $state['expected_owner_group'] || ! $state['group_write']) {
            $repairCandidates[] = 'stable-key-file:'.substr(hash('sha256', $path), 0, 32);
        }
    }
    sort($repairCandidates, SORT_STRING);
    $repairCandidates = array_values(array_unique($repairCandidates));

    emit([
        'status' => 'PASS_PERMISSION_PROBE_COMPLETE',
        'identity_role' => $identityRole,
        'release_sha' => $releaseSha,
        'release_name' => $releaseName,
        'manifest_sha256' => $manifestSha256,
        'candidate_count' => count($slugs),
        'bilingual_url_count' => count($slugs) * 2,
        'fixed_cache_chain_directory_count' => count($chainPaths),
        'first_level_hash_directory_count' => $firstLevelCount,
        'second_level_hash_directory_count' => $secondLevelCount,
        'unexpected_cache_entry_count' => $unexpectedEntryCount,
        'directory_scan_complete' => true,
        'directory_capability_failure_count' => $directoryCapabilityFailureCount,
        'directory_owner_group_mismatch_count' => $directoryOwnerGroupMismatchCount,
        'directory_group_write_missing_count' => $directoryGroupWriteMissingCount,
        'directory_setgid_missing_count' => $directorySetgidMissingCount,
        'fixed_cache_chain_capability_failure_count' => $chainCapabilityFailureCount,
        'fixed_cache_chain_policy_mismatch_count' => $chainPolicyMismatchCount,
        'hash_directory_capability_failure_count' => $hashDirectoryCapabilityFailureCount,
        'hash_directory_policy_mismatch_count' => $hashDirectoryPolicyMismatchCount,
        'exact_stable_cache_key_count' => count($keys),
        'existing_target_file_count' => $existingTargetFileCount,
        'target_file_capability_failure_count' => $targetFileCapabilityFailureCount,
        'target_file_owner_group_mismatch_count' => $targetFileOwnerGroupMismatchCount,
        'target_file_group_write_missing_count' => $targetFileGroupWriteMissingCount,
        'repair_candidate_count' => count($repairCandidates),
        'repair_candidate_set_sha256' => hash(
            'sha256',
            json_encode($repairCandidates, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ),
    ]);
} catch (Throwable $throwable) {
    emit([
        'status' => 'HOLD_PERMISSION_PROBE_INCOMPLETE',
        'identity_role' => isset($identityRole) ? $identityRole : 'unknown',
        'failure_category' => in_array($throwable->getMessage(), [
            'ACTIVE_RELEASE_IDENTITY_DRIFT',
            'CACHE_DIRECTORY_SCAN_BOUND_EXCEEDED',
            'CACHE_DIRECTORY_SCAN_UNAVAILABLE',
            'CACHE_ROOT_UNAVAILABLE',
            'DIRECTORY_STATE_UNAVAILABLE',
            'EXPECTED_IDENTITY_UNAVAILABLE',
            'FILE_STATE_UNAVAILABLE',
            'MANIFEST_CONTRACT_DRIFT',
            'MANIFEST_DRIFT',
            'POSIX_IDENTITY_UNAVAILABLE',
        ], true) ? $throwable->getMessage() : 'UNEXPECTED_DIAGNOSTIC_FAILURE',
    ], 1);
}
