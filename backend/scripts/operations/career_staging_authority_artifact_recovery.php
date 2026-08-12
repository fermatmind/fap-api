<?php

declare(strict_types=1);

const CAREER_RECOVERY_CONTRACT = 'career.staging_authority_artifact_recovery.control.v1';
const CAREER_RECOVERY_GENERATION = 'career-current-342-30-bootstrap-v1';
const CAREER_RECOVERY_POINTER_SCHEMA = 'career.generation_pointer.v1';
const CAREER_RECOVERY_PROJECTION_KIND = 'career_runtime_publish_projection';
const CAREER_RECOVERY_PROJECTION_VERSION = 'career.runtime_publish_projection.v1';
const CAREER_RECOVERY_LEDGER_KIND = 'career_full_release_ledger';
const CAREER_RECOVERY_PROJECTION_FILE = 'career-runtime-publish-projection.json';
const CAREER_RECOVERY_LEDGER_FILE = 'career-full-release-ledger.json';
const CAREER_RECOVERY_PROJECTION_SHA256 = '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6';
const CAREER_RECOVERY_LEDGER_SHA256 = '975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e';
const CAREER_RECOVERY_SLUG_SET_SHA256 = '8b328b2e002875a9f92d4c406981f3c3724f066ee817d2d5bd1a61915e1eddf5';
const CAREER_RECOVERY_LOCALE_ROW_SET_SHA256 = '607926991fa51c74d6d6c9606ab3b7f8f35918996006a39c68963c16765d5697';
const CAREER_RECOVERY_MAX_BYTES = 64_000_000;

final class CareerRecoveryFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

$mode = trim((string) ($argv[1] ?? ''));
$writeState = [
    'candidate_file_write_count' => 0,
    'artifact_write_count' => 0,
    'directory_write_count' => 0,
    'write_state' => 'none',
    'writes_committed' => false,
];

try {
    $privateRoot = absoluteDirectoryEnv('CAREER_RECOVERY_PRIVATE_ROOT');
    if ($mode === 'inspect-production') {
        emit(productionReceipt(inspectProduction($privateRoot), $writeState));
    } elseif ($mode === 'export-projection' || $mode === 'export-ledger') {
        $state = inspectProduction($privateRoot);
        $key = $mode === 'export-projection' ? 'projection' : 'ledger';
        $bytes = file_get_contents($state[$key]['path']);
        if (! is_string($bytes) || fwrite(STDOUT, $bytes) !== strlen($bytes)) {
            throw new CareerRecoveryFailure('PRODUCTION_EXPORT_FAILED');
        }
    } elseif ($mode === 'inspect-staging') {
        emit(stagingReceipt(inspectStaging($privateRoot), $writeState));
    } elseif ($mode === 'import-projection' || $mode === 'import-ledger') {
        $family = $mode === 'import-projection' ? 'projection' : 'ledger';
        importArtifact($privateRoot, $family, $writeState);
        emit(stagingReceipt(inspectStaging($privateRoot), $writeState));
    } else {
        throw new CareerRecoveryFailure('MODE_INVALID');
    }
    exit(0);
} catch (CareerRecoveryFailure $failure) {
    if (! str_starts_with($mode, 'export-')) {
        emit(failureReceipt($mode, $failure->safeCode, $writeState));
    }
    exit(1);
} catch (Throwable) {
    if (($writeState['artifact_write_count'] ?? 0) > 0) {
        $writeState['write_state'] = 'indeterminate';
    }
    if (! str_starts_with($mode, 'export-')) {
        emit(failureReceipt($mode, 'UNEXPECTED_CONTROL_FAILURE', $writeState));
    }
    exit(1);
}

/** @return array<string, mixed> */
function inspectProduction(string $privateRoot): array
{
    $authorityRoot = $privateRoot.'/career_generation_authority';
    assertContainedDirectory($privateRoot, $authorityRoot);
    $activePath = $authorityRoot.'/active-generation.json';
    $active = readJsonFile($privateRoot, $activePath, 'ACTIVE_POINTER_INVALID');
    validatePointer($active);
    $generation = $active['payload']['generation_id'];
    $immutablePath = $authorityRoot.'/generations/'.$generation.'/generation-pointer.json';
    $immutable = readJsonFile($privateRoot, $immutablePath, 'IMMUTABLE_POINTER_INVALID');
    if (! hash_equals((string) hash_file('sha256', $activePath), (string) hash_file('sha256', $immutablePath))) {
        throw new CareerRecoveryFailure('POINTER_IDENTITY_MISMATCH');
    }
    validatePointer($immutable);

    $projection = artifactFromPointer($privateRoot, $active, 'projection');
    $ledger = artifactFromPointer($privateRoot, $active, 'ledger');
    validateAuthorityPair($projection['state'], $ledger['state']);

    return [
        'pointer_sha256' => hash_file('sha256', $activePath),
        'projection' => $projection,
        'ledger' => $ledger,
    ];
}

/** @param array<string, mixed> $pointer */
function validatePointer(array $pointer): void
{
    if (($pointer['schema_version'] ?? null) !== CAREER_RECOVERY_POINTER_SCHEMA
        || ($pointer['payload']['generation_id'] ?? null) !== CAREER_RECOVERY_GENERATION
        || ($pointer['payload']['artifact_format'] ?? null) !== 'legacy_exact_bytes_v1') {
        throw new CareerRecoveryFailure('POINTER_CONTRACT_INVALID');
    }
    $payload = $pointer['payload'] ?? null;
    if (! is_array($payload)
        || ! hash_equals((string) ($pointer['payload_sha256'] ?? ''), hash('sha256', canonicalJson($payload)))) {
        throw new CareerRecoveryFailure('POINTER_PAYLOAD_HASH_INVALID');
    }
    if (($payload['authority']['target_slug_set_sha256'] ?? null) !== CAREER_RECOVERY_SLUG_SET_SHA256
        || ($payload['authority']['target_locale_row_set_sha256'] ?? null) !== CAREER_RECOVERY_LOCALE_ROW_SET_SHA256
        || ($payload['counts']['public_slug_count'] ?? null) !== 30
        || ($payload['counts']['public_locale_row_count'] ?? null) !== 60) {
        throw new CareerRecoveryFailure('POINTER_AUTHORITY_INVALID');
    }
    foreach (['projection' => CAREER_RECOVERY_PROJECTION_SHA256, 'ledger' => CAREER_RECOVERY_LEDGER_SHA256] as $key => $sha) {
        if (($payload['artifacts'][$key]['sha256'] ?? null) !== $sha) {
            throw new CareerRecoveryFailure('POINTER_ARTIFACT_HASH_INVALID');
        }
    }
}

/** @param array<string, mixed> $pointer @return array{path:string,path_sha256:string,sha256:string,bytes:int,state:array<string,mixed>} */
function artifactFromPointer(string $privateRoot, array $pointer, string $key): array
{
    $relative = $pointer['payload']['artifacts'][$key]['path'] ?? null;
    $family = $key === 'projection' ? CAREER_RECOVERY_PROJECTION_KIND : 'career_release_ledger';
    $filename = $key === 'projection' ? CAREER_RECOVERY_PROJECTION_FILE : CAREER_RECOVERY_LEDGER_FILE;
    $expectedSha = $key === 'projection' ? CAREER_RECOVERY_PROJECTION_SHA256 : CAREER_RECOVERY_LEDGER_SHA256;
    if (! is_string($relative)
        || preg_match('#^'.preg_quote($family, '#').'/[A-Za-z0-9][A-Za-z0-9._-]{0,127}/'.preg_quote($filename, '#').'$#D', $relative) !== 1) {
        throw new CareerRecoveryFailure('POINTER_ARTIFACT_PATH_INVALID');
    }
    $path = $privateRoot.'/'.$relative;
    assertContainedRegularFile($privateRoot, $path);
    $bytes = file_get_contents($path);
    if (! is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > CAREER_RECOVERY_MAX_BYTES
        || ! hash_equals($expectedSha, hash('sha256', $bytes))) {
        throw new CareerRecoveryFailure('FROZEN_ARTIFACT_HASH_INVALID');
    }
    $payload = json_decode($bytes, true);
    if (! is_array($payload)) {
        throw new CareerRecoveryFailure('FROZEN_ARTIFACT_JSON_INVALID');
    }
    $state = $key === 'projection' ? validateProjection($payload) : validateLedger($payload);

    return [
        'path' => $path,
        'path_sha256' => hash('sha256', $relative),
        'sha256' => $expectedSha,
        'bytes' => strlen($bytes),
        'state' => $state,
    ];
}

/** @return array<string, mixed> */
function inspectStaging(string $privateRoot): array
{
    $states = [];
    foreach (['projection', 'ledger'] as $key) {
        $target = stagingTarget($privateRoot, $key);
        if (is_link($target) || is_link(dirname($target))) {
            throw new CareerRecoveryFailure('STAGING_TARGET_PATH_UNSAFE');
        }
        if (! file_exists($target)) {
            $states[$key] = ['state' => 'ABSENT', 'path_sha256' => hash('sha256', stagingRelative($key))];

            continue;
        }
        assertContainedRegularFile($privateRoot, $target);
        $bytes = file_get_contents($target);
        $expectedSha = $key === 'projection' ? CAREER_RECOVERY_PROJECTION_SHA256 : CAREER_RECOVERY_LEDGER_SHA256;
        if (! is_string($bytes) || ! hash_equals($expectedSha, hash('sha256', $bytes))) {
            throw new CareerRecoveryFailure('STAGING_EXISTING_ARTIFACT_CONFLICT');
        }
        $payload = json_decode($bytes, true);
        if (! is_array($payload)) {
            throw new CareerRecoveryFailure('STAGING_EXISTING_ARTIFACT_CONFLICT');
        }
        $contract = $key === 'projection' ? validateProjection($payload) : validateLedger($payload);
        $states[$key] = [
            'state' => 'IDENTICAL',
            'path_sha256' => hash('sha256', stagingRelative($key)),
            'contract' => $contract,
        ];
    }
    if (($states['projection']['state'] ?? null) === 'IDENTICAL'
        && ($states['ledger']['state'] ?? null) === 'IDENTICAL') {
        validateAuthorityPair($states['projection']['contract'], $states['ledger']['contract']);
    }

    return ['artifacts' => $states];
}

/** @param array<string, mixed> $writeState */
function importArtifact(string $privateRoot, string $key, array &$writeState): void
{
    $bytes = stream_get_contents(STDIN, CAREER_RECOVERY_MAX_BYTES + 1);
    $expectedSha = $key === 'projection' ? CAREER_RECOVERY_PROJECTION_SHA256 : CAREER_RECOVERY_LEDGER_SHA256;
    if (! is_string($bytes) || strlen($bytes) < 1 || strlen($bytes) > CAREER_RECOVERY_MAX_BYTES
        || ! hash_equals($expectedSha, hash('sha256', $bytes))) {
        throw new CareerRecoveryFailure('IMPORT_BYTES_HASH_INVALID');
    }
    $payload = json_decode($bytes, true);
    if (! is_array($payload)) {
        throw new CareerRecoveryFailure('IMPORT_BYTES_JSON_INVALID');
    }
    $key === 'projection' ? validateProjection($payload) : validateLedger($payload);

    $familyRoot = $privateRoot.'/'.($key === 'projection' ? CAREER_RECOVERY_PROJECTION_KIND : 'career_release_ledger');
    $generationRoot = $familyRoot.'/'.CAREER_RECOVERY_GENERATION;
    foreach ([$familyRoot, $generationRoot] as $directory) {
        if (is_link($directory)) {
            throw new CareerRecoveryFailure('STAGING_TARGET_PATH_UNSAFE');
        }
        if (! is_dir($directory)) {
            umask(0027);
            if (! @mkdir($directory, 0777, false)) {
                throw new CareerRecoveryFailure('STAGING_DIRECTORY_CREATE_FAILED');
            }
            $writeState['directory_write_count']++;
        }
        assertContainedDirectory($privateRoot, $directory);
    }

    $target = stagingTarget($privateRoot, $key);
    if (file_exists($target) || is_link($target)) {
        if (is_link($target) || ! is_file($target)
            || ! hash_equals($expectedSha, (string) hash_file('sha256', $target))) {
            throw new CareerRecoveryFailure('STAGING_EXISTING_ARTIFACT_CONFLICT');
        }

        return;
    }

    $runId = identityEnv('CAREER_RECOVERY_WORKFLOW_RUN_ID');
    $attempt = identityEnv('CAREER_RECOVERY_WORKFLOW_RUN_ATTEMPT');
    $candidate = $generationRoot.'/.'.basename($target).'.candidate.'.$runId.'.'.$attempt;
    if (file_exists($candidate) || is_link($candidate)) {
        throw new CareerRecoveryFailure('STAGING_CANDIDATE_CONFLICT');
    }
    $handle = @fopen($candidate, 'x+b');
    if (! is_resource($handle)) {
        throw new CareerRecoveryFailure('STAGING_CANDIDATE_CREATE_FAILED');
    }
    $writeState['candidate_file_write_count']++;
    $writeState['write_state'] = 'candidate_only';
    try {
        if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
            throw new CareerRecoveryFailure('STAGING_CANDIDATE_WRITE_FAILED');
        }
        fclose($handle);
        $handle = null;
        if (! hash_equals($expectedSha, (string) hash_file('sha256', $candidate))) {
            throw new CareerRecoveryFailure('STAGING_CANDIDATE_READBACK_FAILED');
        }
        if (! @link($candidate, $target)) {
            if (! is_file($target) || ! hash_equals($expectedSha, (string) hash_file('sha256', $target))) {
                throw new CareerRecoveryFailure('STAGING_NO_CLOBBER_COMMIT_FAILED');
            }
        } else {
            $writeState['artifact_write_count']++;
            $writeState['writes_committed'] = true;
            $writeState['write_state'] = 'artifact_committed';
        }
        if (! hash_equals($expectedSha, (string) hash_file('sha256', $target))) {
            throw new CareerRecoveryFailure('STAGING_ARTIFACT_READBACK_FAILED');
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($candidate) && ! is_link($candidate)) {
            @unlink($candidate);
        }
    }
}

function stagingRelative(string $key): string
{
    $family = $key === 'projection' ? CAREER_RECOVERY_PROJECTION_KIND : 'career_release_ledger';
    $filename = $key === 'projection' ? CAREER_RECOVERY_PROJECTION_FILE : CAREER_RECOVERY_LEDGER_FILE;

    return $family.'/'.CAREER_RECOVERY_GENERATION.'/'.$filename;
}

function stagingTarget(string $privateRoot, string $key): string
{
    return $privateRoot.'/'.stagingRelative($key);
}

/** @param array<string, mixed> $projection @return array<string, mixed> */
function validateProjection(array $projection): array
{
    if (($projection['projection_kind'] ?? null) !== CAREER_RECOVERY_PROJECTION_KIND
        || ($projection['projection_version'] ?? null) !== CAREER_RECOVERY_PROJECTION_VERSION
        || ($projection['source_authority'] ?? null) !== 'CareerFullReleaseLedger'
        || ! is_array($projection['items'] ?? null)) {
        throw new CareerRecoveryFailure('PROJECTION_CONTRACT_INVALID');
    }
    $slugs = [];
    $rows = [];
    $publishedRows = [];
    $publishedLocales = [];
    foreach ($projection['items'] as $item) {
        if (! is_array($item)) {
            throw new CareerRecoveryFailure('PROJECTION_CONTRACT_INVALID');
        }
        $slug = normalizedSlug($item['slug'] ?? null);
        $locale = $item['locale'] ?? null;
        if (! in_array($locale, ['en', 'zh'], true)) {
            throw new CareerRecoveryFailure('PROJECTION_LOCALE_INVALID');
        }
        $row = $slug.'|'.$locale;
        if (isset($rows[$row])) {
            throw new CareerRecoveryFailure('PROJECTION_DUPLICATE_ROW');
        }
        $rows[$row] = true;
        $slugs[$slug] = true;
        if (($item['runtime_publish_state'] ?? null) === 'published') {
            if (($item['public_resolution_type'] ?? null) !== 'public_canonical_job'
                || ($item['release_gate_pass'] ?? false) !== true) {
                throw new CareerRecoveryFailure('PUBLISHED_ROW_AUTHORITY_INVALID');
            }
            $publishedRows[$row] = true;
            $publishedLocales[$slug][$locale] = true;
        }
    }
    $slugList = sortedKeys($slugs);
    $rowList = sortedKeys($rows);
    foreach ($slugList as $slug) {
        if (! isset($rows[$slug.'|en'], $rows[$slug.'|zh'])) {
            throw new CareerRecoveryFailure('PROJECTION_LOCALE_PAIR_INCOMPLETE');
        }
    }
    $publishedSlugs = [];
    foreach ($publishedLocales as $slug => $locales) {
        if (isset($locales['en'], $locales['zh'])) {
            $publishedSlugs[] = $slug;
        }
    }
    if (count($publishedRows) !== count($publishedSlugs) * 2) {
        throw new CareerRecoveryFailure('PUBLISHED_LOCALE_PAIR_INCOMPLETE');
    }

    return [
        'slug_count' => count($slugList),
        'locale_row_count' => count($rowList),
        'published_slug_count' => count($publishedSlugs),
        'published_locale_row_count' => count($publishedRows),
        'slug_set_sha256' => setHash($slugList),
        'locale_row_set_sha256' => setHash($rowList),
    ];
}

/** @param array<string, mixed> $ledger @return array<string, mixed> */
function validateLedger(array $ledger): array
{
    if (($ledger['ledger_kind'] ?? null) !== CAREER_RECOVERY_LEDGER_KIND) {
        throw new CareerRecoveryFailure('LEDGER_CONTRACT_INVALID');
    }
    $rows = valueAt($ledger, ['public_resolution', 'rows']);
    if (! is_array($rows) || $rows === []) {
        $rows = $ledger['members'] ?? null;
    }
    if (! is_array($rows) || $rows === []) {
        throw new CareerRecoveryFailure('LEDGER_ROWS_INVALID');
    }
    $slugs = [];
    foreach ($rows as $row) {
        if (! is_array($row)) {
            throw new CareerRecoveryFailure('LEDGER_ROW_INVALID');
        }
        $slugs[normalizedSlug($row['source_slug'] ?? $row['canonical_slug'] ?? $row['slug'] ?? null)] = true;
    }
    $slugList = sortedKeys($slugs);

    return ['slug_count' => count($slugList), 'slug_set_sha256' => setHash($slugList)];
}

/** @param array<string, mixed> $projection @param array<string, mixed> $ledger */
function validateAuthorityPair(array $projection, array $ledger): void
{
    if ($projection['slug_count'] !== 342 || $projection['locale_row_count'] !== 684
        || $projection['published_slug_count'] !== 30 || $projection['published_locale_row_count'] !== 60
        || $projection['slug_set_sha256'] !== CAREER_RECOVERY_SLUG_SET_SHA256
        || $projection['locale_row_set_sha256'] !== CAREER_RECOVERY_LOCALE_ROW_SET_SHA256
        || $ledger['slug_count'] !== 342 || $ledger['slug_set_sha256'] !== CAREER_RECOVERY_SLUG_SET_SHA256) {
        throw new CareerRecoveryFailure('FROZEN_AUTHORITY_MISMATCH');
    }
}

/** @param array<string, mixed> $state @param array<string, mixed> $writeState @return array<string, mixed> */
function productionReceipt(array $state, array $writeState): array
{
    return baseReceipt('inspect-production', 'PASS_PRODUCTION_FROZEN_AUTHORITY_READ_ONLY', $writeState) + [
        'pointer_sha256' => $state['pointer_sha256'],
        'projection_path_sha256' => $state['projection']['path_sha256'],
        'ledger_path_sha256' => $state['ledger']['path_sha256'],
        'projection_bytes' => $state['projection']['bytes'],
        'ledger_bytes' => $state['ledger']['bytes'],
        'authority' => authorityReceipt(),
    ];
}

/** @param array<string, mixed> $state @param array<string, mixed> $writeState @return array<string, mixed> */
function stagingReceipt(array $state, array $writeState): array
{
    $complete = ($state['artifacts']['projection']['state'] ?? null) === 'IDENTICAL'
        && ($state['artifacts']['ledger']['state'] ?? null) === 'IDENTICAL';

    return baseReceipt('inspect-staging', $complete ? 'PASS_STAGING_FROZEN_AUTHORITY_PRESENT' : 'PASS_STAGING_IMPORT_ELIGIBLE', $writeState) + [
        'projection_state' => $state['artifacts']['projection']['state'],
        'ledger_state' => $state['artifacts']['ledger']['state'],
        'projection_path_sha256' => $state['artifacts']['projection']['path_sha256'],
        'ledger_path_sha256' => $state['artifacts']['ledger']['path_sha256'],
        'authority' => authorityReceipt(),
    ];
}

/** @param array<string, mixed> $writeState @return array<string, mixed> */
function baseReceipt(string $mode, string $status, array $writeState): array
{
    return [
        'contract_version' => CAREER_RECOVERY_CONTRACT,
        'mode' => $mode,
        'status' => $status,
        'failed_stage' => null,
        'generation_id' => CAREER_RECOVERY_GENERATION,
        'candidate_file_write_count' => $writeState['candidate_file_write_count'],
        'artifact_write_count' => $writeState['artifact_write_count'],
        'directory_write_count' => $writeState['directory_write_count'],
        'pointer_write_count' => 0,
        'database_write_count' => 0,
        'cms_write_count' => 0,
        'cache_write_count' => 0,
        'publication_write_count' => 0,
        'discoverability_write_count' => 0,
        'deployment_count' => 0,
        'migration_count' => 0,
        'restart_count' => 0,
        'permission_write_count' => 0,
        'write_state' => $writeState['write_state'],
        'writes_committed' => $writeState['writes_committed'],
        'automatic_retry_allowed' => false,
        'automatic_rollback_allowed' => false,
    ];
}

/** @param array<string, mixed> $writeState @return array<string, mixed> */
function failureReceipt(string $mode, string $safeCode, array $writeState): array
{
    return array_merge(
        baseReceipt($mode, ($writeState['artifact_write_count'] ?? 0) > 0 ? 'FAIL_APPLY_OUTCOME_REQUIRES_REVIEW' : 'FAIL_CLOSED', $writeState),
        ['failed_stage' => $safeCode],
    );
}

/** @return array<string, mixed> */
function authorityReceipt(): array
{
    return [
        'projection_sha256' => CAREER_RECOVERY_PROJECTION_SHA256,
        'ledger_sha256' => CAREER_RECOVERY_LEDGER_SHA256,
        'slug_set_sha256' => CAREER_RECOVERY_SLUG_SET_SHA256,
        'locale_row_set_sha256' => CAREER_RECOVERY_LOCALE_ROW_SET_SHA256,
        'slug_count' => 342,
        'locale_row_count' => 684,
        'published_slug_count' => 30,
        'published_locale_row_count' => 60,
    ];
}

function absoluteDirectoryEnv(string $key): string
{
    $value = rtrim((string) getenv($key), '/');
    if ($value === '' || $value[0] !== '/' || str_contains($value, "\0") || ! is_dir($value) || is_link($value)) {
        throw new CareerRecoveryFailure('PRIVATE_ROOT_INVALID');
    }

    return $value;
}

function identityEnv(string $key): string
{
    $value = (string) getenv($key);
    if (preg_match('/^[1-9][0-9]{0,19}$/D', $value) !== 1) {
        throw new CareerRecoveryFailure('WORKFLOW_IDENTITY_INVALID');
    }

    return $value;
}

function assertContainedDirectory(string $root, string $directory): void
{
    if (is_link($directory) || ! is_dir($directory)) {
        throw new CareerRecoveryFailure('DIRECTORY_PATH_UNSAFE');
    }
    $resolvedRoot = realpath($root);
    $resolved = realpath($directory);
    if (! is_string($resolvedRoot) || ! is_string($resolved)
        || ($resolved !== $resolvedRoot && ! str_starts_with($resolved, $resolvedRoot.'/'))) {
        throw new CareerRecoveryFailure('DIRECTORY_PATH_ESCAPED');
    }
}

function assertContainedRegularFile(string $root, string $path): void
{
    if (is_link($path) || ! is_file($path)) {
        throw new CareerRecoveryFailure('ARTIFACT_PATH_UNSAFE');
    }
    $resolvedRoot = realpath($root);
    $resolved = realpath($path);
    if (! is_string($resolvedRoot) || ! is_string($resolved)
        || ! str_starts_with($resolved, $resolvedRoot.'/')) {
        throw new CareerRecoveryFailure('ARTIFACT_PATH_ESCAPED');
    }
}

/** @return array<string, mixed> */
function readJsonFile(string $root, string $path, string $safeCode): array
{
    assertContainedRegularFile($root, $path);
    $bytes = file_get_contents($path);
    $payload = is_string($bytes) ? json_decode($bytes, true) : null;
    if (! is_array($payload)) {
        throw new CareerRecoveryFailure($safeCode);
    }

    return $payload;
}

function normalizedSlug(mixed $value): string
{
    if (! is_string($value) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) !== 1) {
        throw new CareerRecoveryFailure('SLUG_INVALID');
    }

    return $value;
}

/** @param array<string, bool> $values @return list<string> */
function sortedKeys(array $values): array
{
    $keys = array_keys($values);
    sort($keys, SORT_STRING);

    return $keys;
}

/** @param list<string> $values */
function setHash(array $values): string
{
    $normalized = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): string => strtolower(trim((string) $value)),
        $values,
    ))));
    sort($normalized, SORT_STRING);

    return hash('sha256', implode("\n", $normalized)."\n");
}

function valueAt(array $payload, array $path): mixed
{
    $value = $payload;
    foreach ($path as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }

    return $value;
}

function canonicalJson(array $payload): string
{
    $normalize = static function (mixed $value) use (&$normalize): mixed {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($normalize, $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $normalize($item);
        }

        return $value;
    };

    return json_encode($normalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $receipt */
function emit(array $receipt): void
{
    fwrite(STDOUT, json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
