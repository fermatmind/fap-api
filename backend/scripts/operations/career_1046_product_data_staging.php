<?php

declare(strict_types=1);

const CAREER_STAGING_CONTRACT = 'career.1046.product_data_staging.v2';
const CAREER_STAGING_CANDIDATE_SCHEMA = 'career.1046.immutable_candidate.v2';
const CAREER_STAGING_POINTER_SCHEMA = 'career.generation_pointer.v1';
const CAREER_STAGING_GENERATION_MANIFEST_SCHEMA = 'career.generation_manifest.v1';
const CAREER_STAGING_MANIFEST_SHA256 = 'ef4d43eeaa0300534b36fd77d7806bcbe065de1fb13f158ceda1517f259207c5';
const CAREER_STAGING_BASELINE_SET_SHA256 = '39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060';
const CAREER_STAGING_RECEIPT_SET_SHA256 = '09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f';
const CAREER_STAGING_TARGET_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';
const CAREER_STAGING_TARGET_LOCALE_SET_SHA256 = 'c9878e76c817cc09448c32b1dcba3152b22821af34a31204840eb77a2d65857e';
const CAREER_STAGING_TARGET_COUNT = 1046;
const CAREER_STAGING_TARGET_LOCALE_COUNT = 2092;
const CAREER_STAGING_MAX_BUNDLE_BYTES = 268_435_456;

final class Career1046StagingFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

/** @var array<string, mixed> $writeState */
$writeState = [
    'production_write_execution' => false,
    'candidate_file_write_count' => 0,
    'directory_write_count' => 0,
    'write_state' => 'none',
    'writes_committed' => false,
];
$mode = trim((string) ($argv[1] ?? ''));

if ($mode === 'candidate-receipt-sha256') {
    try {
        $raw = file_get_contents('php://stdin');
        if (! is_string($raw) || $raw === '' || strlen($raw) > CAREER_STAGING_MAX_BUNDLE_BYTES) {
            throw new Career1046StagingFailure('CANDIDATE_BUNDLE_BYTES_INVALID');
        }
        $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $receipt = is_array($bundle) ? ($bundle['candidate_receipt'] ?? null) : null;
        if (! is_array($receipt)) {
            throw new Career1046StagingFailure('CANDIDATE_RECEIPT_INVALID');
        }
        echo stagingCanonicalSha256($receipt).PHP_EOL;
        exit(0);
    } catch (Throwable) {
        fwrite(STDERR, "CANDIDATE_RECEIPT_HASH_FAILURE\n");
        exit(1);
    }
}

try {
    if (! in_array($mode, ['preflight', 'apply'], true)) {
        throw new Career1046StagingFailure('MODE_INVALID');
    }

    $expected = stagingExpectedAuthority();
    $bundle = stagingReadCandidateBundle($expected);
    $current = stagingInspectCurrentGeneration($expected);
    $candidate = stagingValidateCandidate($bundle, $expected);
    stagingAssertDestinationAbsent($current, (string) $expected['generation_id']);

    if ($mode === 'preflight') {
        stagingEmitReceipt(stagingSuccessReceipt($mode, $expected, $current, $candidate, $writeState));
        exit(0);
    }

    stagingAssertApplyAuthorization($expected);
    $result = stagingApplyCandidate($expected, $current, $candidate, $writeState);
    stagingEmitReceipt(stagingSuccessReceipt($mode, $expected, $result, $candidate, $writeState));
    exit(0);
} catch (Career1046StagingFailure $failure) {
    stagingEmitReceipt(stagingFailureReceipt($mode, $failure->safeCode, $writeState));
    exit(1);
} catch (Throwable) {
    if (($writeState['production_write_execution'] ?? false) === true) {
        $writeState['write_state'] = 'indeterminate';
    }
    stagingEmitReceipt(stagingFailureReceipt($mode, 'UNEXPECTED_CONTROL_FAILURE', $writeState));
    exit(1);
}

/** @return array<string, mixed> */
function stagingExpectedAuthority(): array
{
    return [
        'private_root' => stagingAbsoluteDirectoryEnv('CAREER_STAGING_PRIVATE_ROOT'),
        'control_plane_sha' => stagingShaEnv('CAREER_STAGING_CONTROL_PLANE_SHA', 40),
        'release_sha' => stagingShaEnv('CAREER_STAGING_RELEASE_SHA', 40),
        'release_name' => stagingIdentityEnv('CAREER_STAGING_RELEASE_NAME'),
        'workflow_run_id' => stagingPositiveIntEnv('CAREER_STAGING_WORKFLOW_RUN_ID'),
        'workflow_run_attempt' => stagingPositiveIntEnv('CAREER_STAGING_WORKFLOW_RUN_ATTEMPT'),
        'generation_id' => stagingGenerationIdEnv('CAREER_STAGING_GENERATION_ID'),
        'candidate_bundle_sha256' => stagingShaEnv('CAREER_STAGING_CANDIDATE_BUNDLE_SHA256'),
        'candidate_receipt_sha256' => stagingShaEnv('CAREER_STAGING_CANDIDATE_RECEIPT_SHA256'),
        'candidate_artifact_digest' => stagingArtifactDigestEnv('CAREER_STAGING_CANDIDATE_ARTIFACT_DIGEST'),
        'previous_generation_id' => stagingIdentityEnv('CAREER_STAGING_PREVIOUS_GENERATION_ID'),
        'previous_pointer_sha256' => stagingShaEnv('CAREER_STAGING_PREVIOUS_POINTER_SHA256'),
    ];
}

/** @param array<string, mixed> $expected @return array<string, mixed> */
function stagingReadCandidateBundle(array $expected): array
{
    $raw = file_get_contents('php://stdin');
    if (! is_string($raw) || $raw === '' || strlen($raw) > CAREER_STAGING_MAX_BUNDLE_BYTES) {
        throw new Career1046StagingFailure('CANDIDATE_BUNDLE_BYTES_INVALID');
    }
    if (! hash_equals((string) $expected['candidate_bundle_sha256'], hash('sha256', $raw))) {
        throw new Career1046StagingFailure('CANDIDATE_BUNDLE_SHA256_MISMATCH');
    }
    $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($bundle)) {
        throw new Career1046StagingFailure('CANDIDATE_BUNDLE_JSON_INVALID');
    }

    return $bundle;
}

/** @param array<string, mixed> $expected @return array<string, mixed> */
function stagingInspectCurrentGeneration(array $expected): array
{
    $authorityRoot = $expected['private_root'].'/career_generation_authority';
    stagingAssertContainedDirectory((string) $expected['private_root'], $authorityRoot);
    $activePath = $authorityRoot.'/active-generation.json';
    $activeRaw = stagingReadContainedFile($authorityRoot, $activePath, 256_000);
    $activeSha256 = hash('sha256', $activeRaw);
    if (! hash_equals((string) $expected['previous_pointer_sha256'], $activeSha256)) {
        throw new Career1046StagingFailure('PREVIOUS_POINTER_SHA256_MISMATCH');
    }
    $active = json_decode($activeRaw, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($active)
        || ($active['schema_version'] ?? null) !== CAREER_STAGING_POINTER_SCHEMA
        || ! is_array($active['payload'] ?? null)
        || ! is_string($active['payload_sha256'] ?? null)
        || ! hash_equals((string) $active['payload_sha256'], stagingCanonicalSha256($active['payload']))) {
        throw new Career1046StagingFailure('PREVIOUS_POINTER_DOCUMENT_INVALID');
    }
    $payload = $active['payload'];
    $pointerCounts = $payload['counts'] ?? null;
    $pointerDiscoverability = $payload['discoverability'] ?? null;
    if (($payload['generation_id'] ?? null) !== $expected['previous_generation_id']
        || ! is_array($pointerCounts)
        || ($pointerCounts['public_slug_count'] ?? null) !== 30
        || ($pointerCounts['public_locale_row_count'] ?? null) !== 60
        || ! is_array($pointerDiscoverability)
        || ($pointerDiscoverability['sitemap_mutated'] ?? null) !== false
        || ($pointerDiscoverability['llms_mutated'] ?? null) !== false
        || ($pointerDiscoverability['search_mutated'] ?? null) !== false) {
        throw new Career1046StagingFailure('PREVIOUS_GENERATION_BOUNDARY_INVALID');
    }

    $generationsRoot = $authorityRoot.'/generations';
    stagingAssertContainedDirectory($authorityRoot, $generationsRoot);

    return [
        'authority_root' => $authorityRoot,
        'generations_root' => $generationsRoot,
        'active_path' => $activePath,
        'active_sha256' => $activeSha256,
        'previous_generation_id' => $expected['previous_generation_id'],
    ];
}

/** @param array<string, mixed> $bundle @param array<string, mixed> $expected @return array<string, mixed> */
function stagingValidateCandidate(array $bundle, array $expected): array
{
    if (($bundle['schema_version'] ?? null) !== CAREER_STAGING_CANDIDATE_SCHEMA
        || ($bundle['generation_id'] ?? null) !== $expected['generation_id']) {
        throw new Career1046StagingFailure('CANDIDATE_IDENTITY_INVALID');
    }
    $authority = $bundle['authority'] ?? null;
    $counts = $bundle['counts'] ?? null;
    $requiredAuthority = [
        'frozen_manifest_sha256' => CAREER_STAGING_MANIFEST_SHA256,
        'baseline_set_sha256' => CAREER_STAGING_BASELINE_SET_SHA256,
        'receipt_set_sha256' => CAREER_STAGING_RECEIPT_SET_SHA256,
        'target_slug_set_sha256' => CAREER_STAGING_TARGET_SET_SHA256,
        'target_locale_row_set_sha256' => CAREER_STAGING_TARGET_LOCALE_SET_SHA256,
    ];
    if (! is_array($authority) || $authority != $requiredAuthority || ! is_array($counts)
        || $counts != [
            'unique_slugs' => CAREER_STAGING_TARGET_COUNT,
            'locale_rows' => CAREER_STAGING_TARGET_LOCALE_COUNT,
            'published_slugs' => CAREER_STAGING_TARGET_COUNT,
            'published_locale_rows' => CAREER_STAGING_TARGET_LOCALE_COUNT,
            'missing' => 0,
            'duplicate' => 0,
            'outside_target' => 0,
        ]) {
        throw new Career1046StagingFailure('CANDIDATE_AUTHORITY_INVALID');
    }

    $documents = $bundle['documents'] ?? null;
    $expectedFiles = [
        'candidate-receipt.json',
        'career-directory-en.json',
        'career-directory-zh.json',
        'career-full-release-ledger.json',
        'career-job-details-en.json',
        'career-job-details-zh.json',
        'career-runtime-publish-projection.json',
        'generation-manifest.json',
    ];
    if (! is_array($documents)) {
        throw new Career1046StagingFailure('CANDIDATE_DOCUMENTS_INVALID');
    }
    $actualFiles = array_keys($documents);
    sort($actualFiles, SORT_STRING);
    if ($actualFiles !== $expectedFiles) {
        throw new Career1046StagingFailure('CANDIDATE_DOCUMENT_SET_INVALID');
    }
    foreach ($documents as $filename => $document) {
        if (! is_array($document) || ! stagingSafeFilename((string) $filename)) {
            throw new Career1046StagingFailure('CANDIDATE_DOCUMENT_INVALID');
        }
    }

    $receipt = $documents['candidate-receipt.json'];
    if (($bundle['candidate_receipt'] ?? null) !== $receipt
        || ! hash_equals((string) $expected['candidate_receipt_sha256'], stagingCanonicalSha256($receipt))
        || ($receipt['schema_version'] ?? null) !== CAREER_STAGING_CANDIDATE_SCHEMA
        || ($receipt['generation_id'] ?? null) !== $expected['generation_id']
        || ($receipt['authority'] ?? null) != $requiredAuthority
        || ($receipt['counts'] ?? null) !== $counts
        || ($receipt['immutable_candidate_only'] ?? null) !== true
        || ($receipt['active_pointer_written'] ?? null) !== false
        || ($receipt['published'] ?? null) !== false
        || ($receipt['warmed'] ?? null) !== false
        || ($receipt['production_workflow_triggered'] ?? null) !== false) {
        throw new Career1046StagingFailure('CANDIDATE_RECEIPT_INVALID');
    }

    $manifest = $documents['generation-manifest.json'];
    $manifestDiscoverability = $manifest['discoverability'] ?? null;
    if (($manifest['schema_version'] ?? null) !== CAREER_STAGING_GENERATION_MANIFEST_SCHEMA
        || ($manifest['generation_id'] ?? null) !== $expected['generation_id']
        || ($manifest['authority'] ?? null) != $requiredAuthority
        || ($manifest['counts'] ?? null) !== $counts
        || ! is_array($manifestDiscoverability)
        || ($manifestDiscoverability['sitemap_released'] ?? null) !== false
        || ($manifestDiscoverability['llms_released'] ?? null) !== false
        || ($manifestDiscoverability['search_submission_enabled'] ?? null) !== false
        || ! hash_equals((string) ($receipt['generation_manifest_sha256'] ?? ''), stagingCanonicalSha256($manifest))) {
        throw new Career1046StagingFailure('GENERATION_MANIFEST_INVALID');
    }

    $descriptors = $manifest['artifacts'] ?? null;
    $primaryFiles = array_values(array_diff($expectedFiles, ['candidate-receipt.json', 'generation-manifest.json']));
    sort($primaryFiles, SORT_STRING);
    $descriptorFiles = is_array($descriptors) ? array_keys($descriptors) : [];
    sort($descriptorFiles, SORT_STRING);
    if ($descriptorFiles !== $primaryFiles) {
        throw new Career1046StagingFailure('GENERATION_ARTIFACT_SET_INVALID');
    }

    $documentSha256 = [];
    $documentBytes = [];
    foreach ($documents as $filename => $document) {
        $bytes = stagingCanonicalJson($document)."\n";
        $documentSha256[$filename] = hash('sha256', $bytes);
        $documentBytes[$filename] = strlen($bytes);
        if (isset($descriptors[$filename])
            && (($descriptors[$filename]['sha256'] ?? null) !== $documentSha256[$filename]
                || ($descriptors[$filename]['bytes'] ?? null) !== $documentBytes[$filename])) {
            throw new Career1046StagingFailure('GENERATION_ARTIFACT_DESCRIPTOR_INVALID');
        }
    }

    foreach (['career-runtime-publish-projection.json', 'career-full-release-ledger.json'] as $filename) {
        $document = $documents[$filename];
        if (($document['generation_id'] ?? null) !== $expected['generation_id']
            || ! is_array($document['generation_authority'] ?? null)
            || ($document['generation_authority']['frozen_manifest_sha256'] ?? null) !== CAREER_STAGING_MANIFEST_SHA256
            || ($document['generation_authority']['target_slug_set_sha256'] ?? null) !== CAREER_STAGING_TARGET_SET_SHA256
            || ($document['generation_authority']['target_locale_row_set_sha256'] ?? null) !== CAREER_STAGING_TARGET_LOCALE_SET_SHA256
            || ($document['generation_authority']['receipt_set_sha256'] ?? null) !== CAREER_STAGING_RECEIPT_SET_SHA256) {
            throw new Career1046StagingFailure('GENERATION_NATIVE_ARTIFACT_INVALID');
        }
    }
    foreach (['career-directory-en.json', 'career-directory-zh.json', 'career-job-details-en.json', 'career-job-details-zh.json'] as $filename) {
        $document = $documents[$filename];
        if (($document['generation_id'] ?? null) !== $expected['generation_id']
            || (($document['public_count'] ?? $document['count'] ?? null) !== CAREER_STAGING_TARGET_COUNT)) {
            throw new Career1046StagingFailure('GENERATION_PRODUCT_ARTIFACT_INVALID');
        }
    }

    return [
        'documents' => $documents,
        'document_sha256' => $documentSha256,
        'document_bytes' => $documentBytes,
        'generation_manifest_sha256' => stagingCanonicalSha256($manifest),
        'candidate_receipt_sha256' => stagingCanonicalSha256($receipt),
    ];
}

/** @param array<string, mixed> $current */
function stagingAssertDestinationAbsent(array $current, string $generationId): void
{
    $destination = $current['generations_root'].'/'.$generationId;
    if (file_exists($destination) || is_link($destination)) {
        throw new Career1046StagingFailure('GENERATION_DESTINATION_CONFLICT');
    }
    foreach (scandir((string) $current['generations_root']) ?: [] as $entry) {
        if (str_starts_with($entry, '.'.$generationId.'.staging.')) {
            throw new Career1046StagingFailure('GENERATION_STAGING_RESIDUE_CONFLICT');
        }
    }
}

/** @param array<string, mixed> $expected */
function stagingAssertApplyAuthorization(array $expected): void
{
    if (getenv('CAREER_STAGING_APPLY_AUTHORIZED') !== '1') {
        throw new Career1046StagingFailure('APPLY_NOT_AUTHORIZED');
    }
    stagingShaEnv('CAREER_STAGING_PREFLIGHT_RECEIPT_SHA256');
}

/**
 * @param  array<string, mixed>  $expected
 * @param  array<string, mixed>  $current
 * @param  array<string, mixed>  $candidate
 * @param  array<string, mixed>  $writeState
 * @return array<string, mixed>
 */
function stagingApplyCandidate(array $expected, array $current, array $candidate, array &$writeState): array
{
    $generationId = (string) $expected['generation_id'];
    $temporary = $current['generations_root'].'/.'.$generationId.'.staging.'.$expected['workflow_run_id'].'.'.$expected['workflow_run_attempt'];
    $destination = $current['generations_root'].'/'.$generationId;
    stagingAssertDestinationAbsent($current, $generationId);

    $writeState['production_write_execution'] = true;
    $writeState['write_state'] = 'temporary_directory_started';
    if (! mkdir($temporary, 0750)) {
        throw new Career1046StagingFailure('STAGING_DIRECTORY_CREATE_FAILED');
    }
    $writeState['directory_write_count'] = 1;

    foreach ($candidate['documents'] as $filename => $document) {
        $path = $temporary.'/'.$filename;
        $bytes = stagingCanonicalJson($document)."\n";
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new Career1046StagingFailure('CANDIDATE_FILE_CREATE_FAILED');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new Career1046StagingFailure('CANDIDATE_FILE_WRITE_FAILED');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new Career1046StagingFailure('CANDIDATE_FILE_SYNC_FAILED');
            }
        } finally {
            fclose($handle);
        }
        if (! chmod($path, 0640)) {
            throw new Career1046StagingFailure('CANDIDATE_FILE_MODE_FAILED');
        }
        $actual = stagingReadContainedFile($temporary, $path, CAREER_STAGING_MAX_BUNDLE_BYTES);
        if (! hash_equals($candidate['document_sha256'][$filename], hash('sha256', $actual))) {
            throw new Career1046StagingFailure('CANDIDATE_FILE_READBACK_FAILED');
        }
        $writeState['candidate_file_write_count']++;
    }

    if (! rename($temporary, $destination)) {
        throw new Career1046StagingFailure('GENERATION_FINALIZE_FAILED');
    }
    $writeState['write_state'] = 'generation_directory_committed';
    $writeState['writes_committed'] = true;

    $readback = [];
    foreach ($candidate['document_sha256'] as $filename => $expectedSha256) {
        $raw = stagingReadContainedFile($destination, $destination.'/'.$filename, CAREER_STAGING_MAX_BUNDLE_BYTES);
        $actualSha256 = hash('sha256', $raw);
        if (! hash_equals($expectedSha256, $actualSha256)) {
            throw new Career1046StagingFailure('COMMITTED_GENERATION_READBACK_FAILED');
        }
        $readback[$filename] = $actualSha256;
    }
    $activeAfter = hash('sha256', stagingReadContainedFile(
        (string) $current['authority_root'],
        (string) $current['active_path'],
        256_000,
    ));
    if (! hash_equals((string) $current['active_sha256'], $activeAfter)) {
        throw new Career1046StagingFailure('ACTIVE_POINTER_CHANGED_DURING_STAGING');
    }

    return [
        ...$current,
        'staged_document_sha256' => $readback,
    ];
}

/** @return array<string, mixed> */
function stagingSuccessReceipt(string $mode, array $expected, array $current, array $candidate, array $writeState): array
{
    return [
        'contract_version' => CAREER_STAGING_CONTRACT,
        'mode' => $mode,
        'status' => $mode === 'preflight' ? 'PASS_PREFLIGHT_STAGE_ELIGIBLE' : 'PASS_APPLY_PRODUCT_DATA_STAGED',
        'failed_stage' => null,
        'control_plane_sha' => $expected['control_plane_sha'],
        'release_sha' => $expected['release_sha'],
        'release_name_sha256' => hash('sha256', (string) $expected['release_name']),
        'workflow_run_id' => $expected['workflow_run_id'],
        'workflow_run_attempt' => $expected['workflow_run_attempt'],
        'generation_id' => $expected['generation_id'],
        'previous_generation_id' => $expected['previous_generation_id'],
        'previous_pointer_sha256' => $expected['previous_pointer_sha256'],
        'active_pointer_sha256_before' => $current['active_sha256'],
        'active_pointer_sha256_after' => $current['active_sha256'],
        'candidate_bundle_sha256' => $expected['candidate_bundle_sha256'],
        'candidate_receipt_sha256' => $candidate['candidate_receipt_sha256'],
        'candidate_artifact_digest' => $expected['candidate_artifact_digest'],
        'generation_manifest_sha256' => $candidate['generation_manifest_sha256'],
        'document_sha256' => $candidate['document_sha256'],
        'document_count' => count($candidate['document_sha256']),
        'counts' => [
            'unique_slugs' => CAREER_STAGING_TARGET_COUNT,
            'locale_rows' => CAREER_STAGING_TARGET_LOCALE_COUNT,
            'published_slugs' => CAREER_STAGING_TARGET_COUNT,
            'published_locale_rows' => CAREER_STAGING_TARGET_LOCALE_COUNT,
            'missing' => 0,
            'duplicate' => 0,
            'outside_target' => 0,
        ],
        ...stagingWriteGuarantees($writeState),
        'zero_write_guarantee' => $mode === 'preflight',
    ];
}

/** @return array<string, mixed> */
function stagingFailureReceipt(string $mode, string $safeCode, array $writeState): array
{
    return [
        'contract_version' => CAREER_STAGING_CONTRACT,
        'mode' => in_array($mode, ['preflight', 'apply'], true) ? $mode : 'invalid',
        'status' => 'FAIL_'.$safeCode,
        'failed_stage' => $safeCode,
        'control_plane_sha' => stagingSafeEnv('CAREER_STAGING_CONTROL_PLANE_SHA', 40),
        'release_sha' => stagingSafeEnv('CAREER_STAGING_RELEASE_SHA', 40),
        'workflow_run_id' => stagingSafeIntEnv('CAREER_STAGING_WORKFLOW_RUN_ID'),
        'workflow_run_attempt' => stagingSafeIntEnv('CAREER_STAGING_WORKFLOW_RUN_ATTEMPT'),
        'generation_id' => stagingSafeEnv('CAREER_STAGING_GENERATION_ID', 64),
        ...stagingWriteGuarantees($writeState),
        'zero_write_guarantee' => ($writeState['production_write_execution'] ?? false) === false,
    ];
}

/** @return array<string, mixed> */
function stagingWriteGuarantees(array $writeState): array
{
    return [
        ...$writeState,
        'pointer_write_count' => 0,
        'database_write_count' => 0,
        'cms_write_count' => 0,
        'cache_write_count' => 0,
        'deployment_count' => 0,
        'migration_count' => 0,
        'publication_write_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_submission_count' => 0,
        'automatic_retry_allowed' => false,
        'automatic_cleanup_allowed' => false,
        'automatic_rollback_allowed' => false,
    ];
}

function stagingEmitReceipt(array $receipt): void
{
    echo stagingCanonicalJson($receipt).PHP_EOL;
}

function stagingCanonicalJson(mixed $value): string
{
    return json_encode(
        stagingSortRecursively($value),
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

function stagingCanonicalSha256(mixed $value): string
{
    return hash('sha256', stagingCanonicalJson($value));
}

function stagingSortRecursively(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }
    if (! array_is_list($value)) {
        ksort($value, SORT_STRING);
    }
    foreach ($value as $key => $child) {
        $value[$key] = stagingSortRecursively($child);
    }

    return $value;
}

function stagingReadContainedFile(string $root, string $path, int $maxBytes): string
{
    $rootReal = realpath($root);
    if (! is_string($rootReal) || is_link($root) || is_link($path)) {
        throw new Career1046StagingFailure('FILE_BOUNDARY_INVALID');
    }
    $real = realpath($path);
    $size = is_string($real) ? filesize($real) : false;
    if (! is_string($real) || ! str_starts_with($real, $rootReal.'/') || ! is_int($size) || $size < 1 || $size > $maxBytes) {
        throw new Career1046StagingFailure('FILE_BOUNDARY_INVALID');
    }
    $raw = file_get_contents($real);
    if (! is_string($raw)) {
        throw new Career1046StagingFailure('FILE_READ_FAILED');
    }

    return $raw;
}

function stagingAssertContainedDirectory(string $root, string $path): void
{
    $rootReal = realpath($root);
    $real = realpath($path);
    if (! is_string($rootReal) || ! is_string($real) || is_link($root) || is_link($path)
        || ! is_dir($real) || ! str_starts_with($real, $rootReal.'/')) {
        throw new Career1046StagingFailure('DIRECTORY_BOUNDARY_INVALID');
    }
}

function stagingSafeFilename(string $value): bool
{
    return preg_match('/^[a-z0-9][a-z0-9.-]{0,127}\.json$/D', $value) === 1;
}

function stagingAbsoluteDirectoryEnv(string $name): string
{
    $value = trim((string) getenv($name));
    if ($value === '' || ! str_starts_with($value, '/') || str_contains($value, '..') || is_link($value) || ! is_dir($value)) {
        throw new Career1046StagingFailure($name.'_INVALID');
    }

    return rtrim($value, '/');
}

function stagingIdentityEnv(string $name): string
{
    $value = trim((string) getenv($name));
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,127}$/D', $value) !== 1) {
        throw new Career1046StagingFailure($name.'_INVALID');
    }

    return $value;
}

function stagingGenerationIdEnv(string $name): string
{
    $value = trim((string) getenv($name));
    if (preg_match('/^career-1046-[0-9a-f]{32}$/D', $value) !== 1) {
        throw new Career1046StagingFailure($name.'_INVALID');
    }

    return $value;
}

function stagingShaEnv(string $name, int $length = 64): string
{
    $value = trim((string) getenv($name));
    if (preg_match('/^[0-9a-f]{'.$length.'}$/D', $value) !== 1) {
        throw new Career1046StagingFailure($name.'_INVALID');
    }

    return $value;
}

function stagingArtifactDigestEnv(string $name): string
{
    $value = trim((string) getenv($name));
    if (preg_match('/^sha256:[0-9a-f]{64}$/D', $value) !== 1) {
        throw new Career1046StagingFailure($name.'_INVALID');
    }

    return $value;
}

function stagingPositiveIntEnv(string $name): int
{
    $value = trim((string) getenv($name));
    if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
        throw new Career1046StagingFailure($name.'_INVALID');
    }

    return (int) $value;
}

function stagingSafeEnv(string $name, int $maxLength): ?string
{
    $value = trim((string) getenv($name));

    return $value !== '' && strlen($value) <= $maxLength && preg_match('/^[A-Za-z0-9._:@-]+$/D', $value) === 1 ? $value : null;
}

function stagingSafeIntEnv(string $name): ?int
{
    $value = trim((string) getenv($name));

    return preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : null;
}
