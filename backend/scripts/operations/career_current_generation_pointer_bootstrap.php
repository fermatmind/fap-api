<?php

declare(strict_types=1);

const CAREER_POINTER_CONTRACT = 'career.current_generation_pointer_bootstrap.v1';
const CAREER_POINTER_SCHEMA = 'career.generation_pointer.v1';
const CAREER_POINTER_ARTIFACT_FORMAT = 'legacy_exact_bytes_v1';
const CAREER_POINTER_PROJECTION_KIND = 'career_runtime_publish_projection';
const CAREER_POINTER_PROJECTION_VERSION = 'career.runtime_publish_projection.v1';
const CAREER_POINTER_LEDGER_KIND = 'career_full_release_ledger';
const CAREER_POINTER_PROJECTION_FILE = 'career-runtime-publish-projection.json';
const CAREER_POINTER_LEDGER_FILE = 'career-full-release-ledger.json';
const CAREER_POINTER_MAX_ARTIFACT_BYTES = 64_000_000;

final class CareerPointerBootstrapFailure extends RuntimeException
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
    'pointer_write_count' => 0,
    'directory_write_count' => 0,
    'write_state' => 'none',
    'writes_committed' => false,
];
$mode = trim((string) ($argv[1] ?? ''));

try {
    if (! in_array($mode, ['preflight', 'apply'], true)) {
        throw new CareerPointerBootstrapFailure('MODE_INVALID');
    }

    $expected = expectedAuthority();
    validateRuntimeBoundary($expected);
    $selected = inspectCurrentAuthority($expected);

    if ($mode === 'preflight') {
        emitReceipt(successReceipt($mode, $expected, $selected, $writeState));

        exit(0);
    }

    assertApplyAuthorization($expected, $selected);
    $result = applyPointer($expected, $selected, $writeState);
    emitReceipt(successReceipt($mode, $expected, $result, $writeState));

    exit(0);
} catch (CareerPointerBootstrapFailure $failure) {
    emitReceipt(failureReceipt($mode, $failure->safeCode, $writeState));
    exit(1);
} catch (Throwable) {
    if (($writeState['production_write_execution'] ?? false) === true) {
        $writeState['write_state'] = 'indeterminate';
    }
    emitReceipt(failureReceipt($mode, 'UNEXPECTED_CONTROL_FAILURE', $writeState));
    exit(1);
}

/** @return array<string, mixed> */
function expectedAuthority(): array
{
    return [
        'backend_root' => absoluteDirectoryEnv('CAREER_POINTER_BACKEND_ROOT'),
        'deploy_path' => absoluteDirectoryEnv('CAREER_POINTER_DEPLOY_PATH'),
        'control_plane_sha' => shaEnv('CAREER_POINTER_CONTROL_PLANE_SHA', 40),
        'release_sha' => shaEnv('CAREER_POINTER_EXPECTED_RELEASE_SHA', 40),
        'release_name' => identityEnv('CAREER_POINTER_EXPECTED_RELEASE_NAME'),
        'workflow_run_id' => positiveIntEnv('CAREER_POINTER_WORKFLOW_RUN_ID'),
        'workflow_run_attempt' => positiveIntEnv('CAREER_POINTER_WORKFLOW_RUN_ATTEMPT'),
        'generation_id' => identityEnv('CAREER_POINTER_GENERATION_ID'),
        'pointer_timestamp' => timestampEnv('CAREER_POINTER_TIMESTAMP'),
        'frozen_manifest_sha256' => shaEnv('CAREER_POINTER_FROZEN_MANIFEST_SHA256'),
        'freeze_contract_payload_sha256' => shaEnv('CAREER_POINTER_FREEZE_CONTRACT_PAYLOAD_SHA256'),
        'receipt_set_sha256' => shaEnv('CAREER_POINTER_RECEIPT_SET_SHA256'),
        'projection_sha256' => shaEnv('CAREER_POINTER_PROJECTION_SHA256'),
        'ledger_sha256' => shaEnv('CAREER_POINTER_LEDGER_SHA256'),
        'slug_set_sha256' => shaEnv('CAREER_POINTER_SLUG_SET_SHA256'),
        'locale_row_set_sha256' => shaEnv('CAREER_POINTER_LOCALE_ROW_SET_SHA256'),
        'slug_count' => positiveIntEnv('CAREER_POINTER_SLUG_COUNT'),
        'locale_row_count' => positiveIntEnv('CAREER_POINTER_LOCALE_ROW_COUNT'),
        'published_slug_count' => positiveIntEnv('CAREER_POINTER_PUBLISHED_SLUG_COUNT'),
        'published_locale_row_count' => positiveIntEnv('CAREER_POINTER_PUBLISHED_LOCALE_ROW_COUNT'),
    ];
}

/** @param array<string, mixed> $expected @return array<string, mixed> */
function inspectCurrentAuthority(array $expected): array
{
    $privateRoot = $expected['backend_root'].'/storage/app/private';
    assertResolvedDirectory($privateRoot);

    $projection = selectExactArtifact(
        privateRoot: $privateRoot,
        family: 'career_runtime_publish_projection',
        filename: CAREER_POINTER_PROJECTION_FILE,
        expectedSha256: (string) $expected['projection_sha256'],
        validator: validateProjection(...),
    );
    $ledger = selectExactArtifact(
        privateRoot: $privateRoot,
        family: 'career_release_ledger',
        filename: CAREER_POINTER_LEDGER_FILE,
        expectedSha256: (string) $expected['ledger_sha256'],
        validator: validateLedger(...),
    );

    $projectionState = $projection['contract_state'];
    $ledgerState = $ledger['contract_state'];
    foreach ([
        'slug_count' => $projectionState['slug_count'],
        'locale_row_count' => $projectionState['locale_row_count'],
        'published_slug_count' => $projectionState['published_slug_count'],
        'published_locale_row_count' => $projectionState['published_locale_row_count'],
        'slug_set_sha256' => $projectionState['slug_set_sha256'],
        'locale_row_set_sha256' => $projectionState['locale_row_set_sha256'],
    ] as $key => $actual) {
        if ($actual !== $expected[$key]) {
            throw new CareerPointerBootstrapFailure('PROJECTION_AUTHORITY_MISMATCH');
        }
    }
    if ($ledgerState['slug_count'] !== $expected['slug_count']
        || $ledgerState['slug_set_sha256'] !== $expected['slug_set_sha256']
        || $ledgerState['slug_count'] !== $projectionState['slug_count']
        || $ledgerState['slug_set_sha256'] !== $projectionState['slug_set_sha256']) {
        throw new CareerPointerBootstrapFailure('LEDGER_AUTHORITY_MISMATCH');
    }

    $pointerState = inspectPointerState($privateRoot, (string) $expected['generation_id']);
    if ($pointerState['active_pointer_state'] !== 'ABSENT'
        || $pointerState['generation_pointer_state'] !== 'ABSENT'
        || $pointerState['existing_generation_pointer_count'] !== 0
        || $pointerState['candidate_file_count'] !== 0) {
        throw new CareerPointerBootstrapFailure('EXISTING_POINTER_CONFLICT');
    }

    return [
        'private_root' => $privateRoot,
        'projection' => $projection,
        'ledger' => $ledger,
        'projection_state' => $projectionState,
        'ledger_state' => $ledgerState,
        'pointer_state' => $pointerState,
    ];
}

/**
 * @param  callable(array<string, mixed>): array<string, mixed>  $validator
 * @return array{path:string,relative_path:string,path_sha256:string,sha256:string,bytes:int,payload:array<string,mixed>,contract_state:array<string,mixed>,candidate_count:int,selection_rule:string}
 */
function selectExactArtifact(
    string $privateRoot,
    string $family,
    string $filename,
    string $expectedSha256,
    callable $validator,
): array {
    $root = $privateRoot.'/'.$family;
    assertContainedDirectory($privateRoot, $root);
    $entries = scandir($root);
    if (! is_array($entries)) {
        throw new CareerPointerBootstrapFailure('ARTIFACT_ROOT_UNREADABLE');
    }

    $matches = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $entry) !== 1) {
            continue;
        }
        $directory = $root.'/'.$entry;
        $path = $directory.'/'.$filename;
        if (is_link($directory) || is_link($path)) {
            throw new CareerPointerBootstrapFailure('ARTIFACT_PATH_SAFETY_INVALID');
        }
        if (! is_dir($directory) || ! is_file($path)) {
            continue;
        }
        assertContainedRegularFile($privateRoot, $path);
        $bytes = filesize($path);
        if (! is_int($bytes) || $bytes < 1 || $bytes > CAREER_POINTER_MAX_ARTIFACT_BYTES) {
            continue;
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || ! hash_equals($expectedSha256, hash('sha256', $raw))) {
            continue;
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            throw new CareerPointerBootstrapFailure('ARTIFACT_READBACK_INVALID');
        }
        $relativePath = $family.'/'.$entry.'/'.$filename;
        $matches[] = [
            'path' => $path,
            'relative_path' => $relativePath,
            'path_sha256' => hash('sha256', $relativePath),
            'sha256' => $expectedSha256,
            'bytes' => $bytes,
            'payload' => $payload,
            'contract_state' => $validator($payload),
        ];
    }

    if ($matches === []) {
        throw new CareerPointerBootstrapFailure('EXACT_ARTIFACT_NOT_FOUND');
    }
    usort($matches, static fn (array $left, array $right): int => strcmp(
        (string) $left['relative_path'],
        (string) $right['relative_path'],
    ));
    $selected = $matches[0];
    foreach ($matches as $match) {
        if (! hash_equals((string) $selected['sha256'], (string) $match['sha256'])
            || (int) $selected['bytes'] !== (int) $match['bytes']
            || ! hash_equals(canonicalJsonSha256($selected['payload']), canonicalJsonSha256($match['payload']))
            || ! hash_equals(canonicalJsonSha256($selected['contract_state']), canonicalJsonSha256($match['contract_state']))) {
            throw new CareerPointerBootstrapFailure('ARTIFACT_CANDIDATE_CONTRACT_MISMATCH');
        }
    }
    $selected['candidate_count'] = count($matches);
    $selected['selection_rule'] = 'relative_path_bytewise_ascending_first_v1';

    return $selected;
}

/** @param array<string, mixed> $projection @return array<string, mixed> */
function validateProjection(array $projection): array
{
    if (($projection['projection_kind'] ?? null) !== CAREER_POINTER_PROJECTION_KIND
        || ($projection['projection_version'] ?? null) !== CAREER_POINTER_PROJECTION_VERSION
        || ($projection['source_authority'] ?? null) !== 'CareerFullReleaseLedger') {
        throw new CareerPointerBootstrapFailure('PROJECTION_CONTRACT_INVALID');
    }
    $items = $projection['items'] ?? null;
    if (! is_array($items) || $items === []) {
        throw new CareerPointerBootstrapFailure('PROJECTION_ITEMS_INVALID');
    }

    $slugs = [];
    $rows = [];
    $publishedRows = [];
    $publishedLocales = [];
    foreach ($items as $item) {
        if (! is_array($item)) {
            throw new CareerPointerBootstrapFailure('PROJECTION_ITEM_INVALID');
        }
        $slug = normalizedSlug($item['slug'] ?? null);
        $locale = $item['locale'] ?? null;
        if (! in_array($locale, ['en', 'zh'], true)) {
            throw new CareerPointerBootstrapFailure('PROJECTION_LOCALE_INVALID');
        }
        $row = $slug.'|'.$locale;
        if (isset($rows[$row])) {
            throw new CareerPointerBootstrapFailure('PROJECTION_DUPLICATE_ROW');
        }
        $rows[$row] = true;
        $slugs[$slug] = true;
        if (($item['runtime_publish_state'] ?? null) === 'published') {
            if (($item['public_resolution_type'] ?? null) !== 'public_canonical_job'
                || ($item['release_gate_pass'] ?? false) !== true) {
                throw new CareerPointerBootstrapFailure('PUBLISHED_ROW_AUTHORITY_INVALID');
            }
            $publishedRows[$row] = true;
            $publishedLocales[$slug][$locale] = true;
        }
    }

    $slugList = sortedKeys($slugs);
    $rowList = sortedKeys($rows);
    foreach ($slugList as $slug) {
        if (! isset($rows[$slug.'|en'], $rows[$slug.'|zh'])) {
            throw new CareerPointerBootstrapFailure('PROJECTION_LOCALE_PAIR_INCOMPLETE');
        }
    }
    $publishedSlugs = [];
    foreach ($publishedLocales as $slug => $locales) {
        if (isset($locales['en'], $locales['zh'])) {
            $publishedSlugs[] = $slug;
        }
    }
    sort($publishedSlugs, SORT_STRING);
    if (count($publishedRows) !== count($publishedSlugs) * 2) {
        throw new CareerPointerBootstrapFailure('PUBLISHED_LOCALE_PAIR_INCOMPLETE');
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
    if (($ledger['ledger_kind'] ?? null) !== CAREER_POINTER_LEDGER_KIND) {
        throw new CareerPointerBootstrapFailure('LEDGER_CONTRACT_INVALID');
    }
    $rows = valueAt($ledger, ['public_resolution', 'rows']);
    if (! is_array($rows) || $rows === []) {
        $rows = $ledger['members'] ?? null;
    }
    if (! is_array($rows) || $rows === []) {
        throw new CareerPointerBootstrapFailure('LEDGER_ROWS_INVALID');
    }
    $slugs = [];
    foreach ($rows as $row) {
        if (! is_array($row)) {
            throw new CareerPointerBootstrapFailure('LEDGER_ROW_INVALID');
        }
        $slug = normalizedSlug($row['source_slug'] ?? $row['canonical_slug'] ?? $row['slug'] ?? null);
        $slugs[$slug] = true;
    }
    $slugList = sortedKeys($slugs);

    return ['slug_count' => count($slugList), 'slug_set_sha256' => setHash($slugList)];
}

/** @return array{active_pointer_state:string,generation_pointer_state:string,existing_generation_pointer_count:int,candidate_file_count:int} */
function inspectPointerState(string $privateRoot, string $generationId): array
{
    $root = $privateRoot.'/career_generation_authority';
    if (is_link($root)) {
        throw new CareerPointerBootstrapFailure('POINTER_ROOT_SYMLINK_FORBIDDEN');
    }
    if (file_exists($root) && ! is_dir($root)) {
        throw new CareerPointerBootstrapFailure('POINTER_ROOT_INVALID');
    }
    $activePath = $root.'/active-generation.json';
    $generationPath = $root.'/generations/'.$generationId.'/generation-pointer.json';
    $count = 0;
    $candidateCount = 0;
    foreach (glob($root.'/*.candidate.*') ?: [] as $candidate) {
        if (is_link($candidate) || file_exists($candidate)) {
            $candidateCount++;
        }
    }
    $generationsRoot = $root.'/generations';
    if (is_link($generationsRoot)) {
        throw new CareerPointerBootstrapFailure('GENERATIONS_ROOT_SYMLINK_FORBIDDEN');
    }
    if (is_dir($generationsRoot)) {
        $directories = scandir($generationsRoot);
        foreach (is_array($directories) ? $directories : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $generationDirectory = $generationsRoot.'/'.$entry;
            if (is_link($generationDirectory)) {
                $count++;

                continue;
            }
            $candidate = $generationDirectory.'/generation-pointer.json';
            if (is_link($candidate) || file_exists($candidate)) {
                $count++;
            }
            foreach (glob($generationDirectory.'/*.candidate.*') ?: [] as $candidateFile) {
                if (is_link($candidateFile) || file_exists($candidateFile)) {
                    $candidateCount++;
                }
            }
        }
    }

    return [
        'active_pointer_state' => (is_link($activePath) || file_exists($activePath)) ? 'PRESENT' : 'ABSENT',
        'generation_pointer_state' => (is_link($generationPath) || file_exists($generationPath)) ? 'PRESENT' : 'ABSENT',
        'existing_generation_pointer_count' => $count,
        'candidate_file_count' => $candidateCount,
    ];
}

/** @param array<string, mixed> $expected @param array<string, mixed> $selected */
function assertApplyAuthorization(array $expected, array $selected): void
{
    if (getenv('CAREER_POINTER_APPLY_AUTHORIZED') !== '1') {
        throw new CareerPointerBootstrapFailure('APPLY_AUTHORIZATION_MISSING');
    }
    $receiptSha = shaEnv('CAREER_POINTER_PREFLIGHT_RECEIPT_SHA256');
    $projectionPathSha = shaEnv('CAREER_POINTER_EXPECTED_PROJECTION_PATH_SHA256');
    $ledgerPathSha = shaEnv('CAREER_POINTER_EXPECTED_LEDGER_PATH_SHA256');
    if (! hash_equals($projectionPathSha, (string) $selected['projection']['path_sha256'])
        || ! hash_equals($ledgerPathSha, (string) $selected['ledger']['path_sha256'])) {
        throw new CareerPointerBootstrapFailure('PREFLIGHT_ARTIFACT_PATH_DRIFT');
    }
    if ($receiptSha === $expected['receipt_set_sha256']) {
        throw new CareerPointerBootstrapFailure('PREFLIGHT_RECEIPT_IDENTITY_INVALID');
    }
}

/**
 * @param  array<string, mixed>  $expected
 * @param  array<string, mixed>  $selected
 * @param  array<string, mixed>  $writeState
 * @return array<string, mixed>
 */
function applyPointer(array $expected, array $selected, array &$writeState): array
{
    $receiptSha = shaEnv('CAREER_POINTER_PREFLIGHT_RECEIPT_SHA256');
    $privateRoot = (string) $selected['private_root'];
    $authorityRoot = $privateRoot.'/career_generation_authority';
    $generationsRoot = $authorityRoot.'/generations';
    $generationRoot = $generationsRoot.'/'.$expected['generation_id'];
    foreach ([$authorityRoot, $generationsRoot, $generationRoot] as $directory) {
        if (is_link($directory)) {
            throw new CareerPointerBootstrapFailure('POINTER_DIRECTORY_SYMLINK_FORBIDDEN');
        }
        if (! is_dir($directory)) {
            $writeState['production_write_execution'] = true;
            if (! mkdir($directory, 0750) && ! is_dir($directory)) {
                throw new CareerPointerBootstrapFailure('POINTER_DIRECTORY_CREATE_FAILED');
            }
            $writeState['directory_write_count']++;
            $writeState['write_state'] = 'candidate_only';
        }
    }

    $document = pointerDocument($expected, $selected, $receiptSha);
    $bytes = json_encode(
        $document,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    )."\n";
    $documentSha = hash('sha256', $bytes);
    $candidateSuffix = '.candidate.'.$expected['workflow_run_id'].'.'.$expected['workflow_run_attempt'];
    $immutablePath = $generationRoot.'/generation-pointer.json';
    $immutableCandidate = $immutablePath.$candidateSuffix;
    $activePath = $authorityRoot.'/active-generation.json';
    $activeCandidate = $activePath.$candidateSuffix;

    $writeState['production_write_execution'] = true;
    writeNoClobberCandidate($immutableCandidate, $bytes, $writeState);
    revalidateBeforeRename($expected, $selected);
    atomicRenameNoClobber($immutableCandidate, $immutablePath, $documentSha);
    $writeState['pointer_write_count'] = 1;
    $writeState['write_state'] = 'immutable_pointer_committed';

    writeNoClobberCandidate($activeCandidate, $bytes, $writeState);
    revalidateBeforeRename($expected, $selected);
    atomicRenameNoClobber($activeCandidate, $activePath, $documentSha);
    $writeState['pointer_write_count'] = 2;
    $writeState['write_state'] = 'active_pointer_committed';

    foreach ([$immutablePath, $activePath] as $path) {
        if (is_link($path) || ! is_file($path)
            || ! hash_equals($documentSha, (string) hash_file('sha256', $path))) {
            throw new CareerPointerBootstrapFailure('POINTER_FULL_HASH_READBACK_FAILED');
        }
    }
    if (! hash_equals((string) hash_file('sha256', $immutablePath), (string) hash_file('sha256', $activePath))) {
        throw new CareerPointerBootstrapFailure('ROOT_POINTER_IDENTITY_MISMATCH');
    }
    $writeState['writes_committed'] = true;

    $selected['pointer_document_sha256'] = $documentSha;

    return $selected;
}

/** @param array<string, mixed> $expected @param array<string, mixed> $selected */
function pointerDocument(array $expected, array $selected, string $receiptSha): array
{
    $generationId = (string) $expected['generation_id'];
    $payload = [
        'generation_id' => $generationId,
        'artifact_format' => CAREER_POINTER_ARTIFACT_FORMAT,
        'artifacts' => [
            'projection' => [
                'identity' => 'career-runtime-publish-projection@'.$generationId,
                'path' => $selected['projection']['relative_path'],
                'sha256' => $expected['projection_sha256'],
            ],
            'ledger' => [
                'identity' => 'career-full-release-ledger@'.$generationId,
                'path' => $selected['ledger']['relative_path'],
                'sha256' => $expected['ledger_sha256'],
            ],
        ],
        'authority' => [
            'frozen_manifest_sha256' => $expected['frozen_manifest_sha256'],
            'target_slug_set_sha256' => $expected['slug_set_sha256'],
            'target_locale_row_set_sha256' => $expected['locale_row_set_sha256'],
            'receipt_set_sha256' => $expected['receipt_set_sha256'],
        ],
        'counts' => [
            'public_slug_count' => $expected['published_slug_count'],
            'public_locale_row_count' => $expected['published_locale_row_count'],
        ],
        'lineage' => ['previous_generation_id' => null, 'previous_pointer_sha256' => null],
        'timestamps' => [
            'created_at' => $expected['pointer_timestamp'],
            'activated_at' => $expected['pointer_timestamp'],
        ],
        'activation_receipt' => [
            'identity' => 'activation:'.$generationId,
            'sha256' => $receiptSha,
        ],
        'rollback' => ['eligible' => false, 'previous_generation_id' => null],
        'discoverability' => [
            'sitemap_mutated' => false,
            'llms_mutated' => false,
            'search_mutated' => false,
        ],
        'revocation_receipt' => null,
    ];

    return [
        'schema_version' => CAREER_POINTER_SCHEMA,
        'payload_sha256' => hash('sha256', canonicalJson($payload)),
        'payload' => $payload,
    ];
}

/** @param array<string, mixed> $writeState */
function writeNoClobberCandidate(string $path, string $bytes, array &$writeState): void
{
    $handle = @fopen($path, 'x');
    if (! is_resource($handle)) {
        throw new CareerPointerBootstrapFailure('NO_CLOBBER_CANDIDATE_CREATE_FAILED');
    }
    $writeState['candidate_file_write_count']++;
    $writeState['write_state'] = 'candidate_only';
    $written = fwrite($handle, $bytes);
    if ($written !== strlen($bytes) || ! fflush($handle)) {
        fclose($handle);
        throw new CareerPointerBootstrapFailure('CANDIDATE_WRITE_FAILED');
    }
    if (function_exists('fsync') && ! fsync($handle)) {
        fclose($handle);
        throw new CareerPointerBootstrapFailure('CANDIDATE_FSYNC_FAILED');
    }
    fclose($handle);
    if (! hash_equals(hash('sha256', $bytes), (string) hash_file('sha256', $path))) {
        throw new CareerPointerBootstrapFailure('CANDIDATE_HASH_READBACK_FAILED');
    }
}

function atomicRenameNoClobber(string $candidate, string $target, string $expectedSha256): void
{
    if (is_link($target) || file_exists($target)) {
        throw new CareerPointerBootstrapFailure('POINTER_TARGET_ALREADY_EXISTS');
    }
    if (! hash_equals($expectedSha256, (string) hash_file('sha256', $candidate))) {
        throw new CareerPointerBootstrapFailure('CANDIDATE_HASH_DRIFT');
    }
    if (! rename($candidate, $target)) {
        throw new CareerPointerBootstrapFailure('ATOMIC_POINTER_RENAME_FAILED');
    }
}

/** @param array<string, mixed> $expected @param array<string, mixed> $selected */
function revalidateBeforeRename(array $expected, array $selected): void
{
    validateRuntimeBoundary($expected);
    $fresh = [
        'projection' => selectExactArtifact(
            privateRoot: (string) $selected['private_root'],
            family: 'career_runtime_publish_projection',
            filename: CAREER_POINTER_PROJECTION_FILE,
            expectedSha256: (string) $expected['projection_sha256'],
            validator: validateProjection(...),
        ),
        'ledger' => selectExactArtifact(
            privateRoot: (string) $selected['private_root'],
            family: 'career_release_ledger',
            filename: CAREER_POINTER_LEDGER_FILE,
            expectedSha256: (string) $expected['ledger_sha256'],
            validator: validateLedger(...),
        ),
    ];
    foreach (['projection', 'ledger'] as $key) {
        if (! hash_equals((string) $selected[$key]['path_sha256'], (string) $fresh[$key]['path_sha256'])
            || (int) $selected[$key]['candidate_count'] !== (int) $fresh[$key]['candidate_count']
            || (string) $selected[$key]['selection_rule'] !== (string) $fresh[$key]['selection_rule']) {
            throw new CareerPointerBootstrapFailure('SOURCE_ARTIFACT_SELECTION_DRIFT_BEFORE_RENAME');
        }
    }
    $activePath = $selected['private_root'].'/career_generation_authority/active-generation.json';
    if (is_link($activePath) || file_exists($activePath)) {
        throw new CareerPointerBootstrapFailure('ACTIVE_POINTER_DRIFT_BEFORE_RENAME');
    }
}

/** @param array<string, mixed> $expected */
function validateRuntimeBoundary(array $expected): void
{
    $deployPath = (string) $expected['deploy_path'];
    $current = realpath($deployPath.'/current');
    $release = realpath($deployPath.'/releases/'.$expected['release_name']);
    $backend = realpath((string) $expected['backend_root']);
    if (! is_string($current) || ! is_string($release) || $current !== $release
        || ! is_string($backend) || $backend !== $current.'/backend'
        || basename($current) !== $expected['release_name']) {
        throw new CareerPointerBootstrapFailure('ACTIVE_RELEASE_IDENTITY_MISMATCH');
    }
    $revisionPath = $current.'/REVISION';
    $revision = is_file($revisionPath) && ! is_link($revisionPath)
        ? trim((string) file_get_contents($revisionPath))
        : '';
    if (! hash_equals((string) $expected['release_sha'], $revision)) {
        throw new CareerPointerBootstrapFailure('ACTIVE_RELEASE_REVISION_MISMATCH');
    }
    if (file_exists($deployPath.'/.dep/deploy.lock') || is_link($deployPath.'/.dep/deploy.lock')) {
        throw new CareerPointerBootstrapFailure('DEPLOY_LOCK_PRESENT');
    }

    $processes = [];
    $status = 0;
    exec('/bin/ps -eo comm=,args=', $processes, $status);
    if ($status !== 0) {
        throw new CareerPointerBootstrapFailure('DEPLOY_PROCESS_INSPECTION_FAILED');
    }
    foreach ($processes as $process) {
        if (preg_match('/^php\s+.*(?:dep(?:\.phar)?\s+.*production|artisan\s+migrate|queue:reload-workers)/', $process) === 1
            || preg_match('/^composer\s+.*install/', $process) === 1) {
            throw new CareerPointerBootstrapFailure('DEPLOY_PROCESS_PRESENT');
        }
    }
}

/** @param array<string, mixed> $expected @param array<string, mixed> $selected @param array<string, mixed> $writeState @return array<string, mixed> */
function successReceipt(string $mode, array $expected, array $selected, array $writeState): array
{
    $apply = $mode === 'apply';

    return [
        'contract_version' => CAREER_POINTER_CONTRACT,
        'mode' => $mode,
        'status' => $apply ? 'PASS_APPLY_POINTER_BOOTSTRAPPED' : 'PASS_PREFLIGHT_APPLY_ELIGIBLE',
        'failed_stage' => null,
        'control_plane_sha' => $expected['control_plane_sha'],
        'release_sha' => $expected['release_sha'],
        'release_name_sha256' => hash('sha256', (string) $expected['release_name']),
        'workflow_run_id' => $expected['workflow_run_id'],
        'workflow_run_attempt' => $expected['workflow_run_attempt'],
        'generation_id' => $expected['generation_id'],
        'pointer_timestamp' => $expected['pointer_timestamp'],
        'artifact_format' => CAREER_POINTER_ARTIFACT_FORMAT,
        'freeze_contract_payload_sha256' => $expected['freeze_contract_payload_sha256'],
        'authority' => [
            'frozen_manifest_sha256' => $expected['frozen_manifest_sha256'],
            'receipt_set_sha256' => $expected['receipt_set_sha256'],
            'projection_sha256' => $expected['projection_sha256'],
            'ledger_sha256' => $expected['ledger_sha256'],
            'slug_set_sha256' => $expected['slug_set_sha256'],
            'locale_row_set_sha256' => $expected['locale_row_set_sha256'],
            'slug_count' => $expected['slug_count'],
            'locale_row_count' => $expected['locale_row_count'],
            'published_slug_count' => $expected['published_slug_count'],
            'published_locale_row_count' => $expected['published_locale_row_count'],
        ],
        'source_artifacts' => [
            'projection_path_sha256' => $selected['projection']['path_sha256'],
            'ledger_path_sha256' => $selected['ledger']['path_sha256'],
            'projection_candidate_count' => $selected['projection']['candidate_count'],
            'ledger_candidate_count' => $selected['ledger']['candidate_count'],
            'selection_rule' => $selected['projection']['selection_rule'],
        ],
        'pointer_state_before' => $selected['pointer_state'],
        'pointer_document_sha256' => $selected['pointer_document_sha256'] ?? str_repeat('0', 64),
        'lineage_previous_generation_id' => null,
        'rollback_eligible' => false,
        'deploy_lock_absent' => true,
        'deploy_process_absent' => true,
        'apply_eligible' => ! $apply,
        'production_write_execution' => $writeState['production_write_execution'],
        'candidate_file_write_count' => $writeState['candidate_file_write_count'],
        'pointer_write_count' => $writeState['pointer_write_count'],
        'directory_write_count' => $writeState['directory_write_count'],
        'database_write_count' => 0,
        'cms_write_count' => 0,
        'cache_write_count' => 0,
        'source_artifact_write_count' => 0,
        'deployment_count' => 0,
        'migration_count' => 0,
        'publication_write_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_submission_count' => 0,
        'automatic_retry_allowed' => false,
        'automatic_cleanup_allowed' => false,
        'automatic_rollback_allowed' => false,
        'write_state' => $writeState['write_state'],
        'writes_committed' => $writeState['writes_committed'],
        'zero_write_guarantee' => ! $apply,
    ];
}

/** @param array<string, mixed> $writeState @return array<string, mixed> */
function failureReceipt(string $mode, string $safeCode, array $writeState): array
{
    $apply = $mode === 'apply';

    return [
        'contract_version' => CAREER_POINTER_CONTRACT,
        'mode' => in_array($mode, ['preflight', 'apply'], true) ? $mode : 'invalid',
        'status' => $apply && ($writeState['production_write_execution'] ?? false)
            ? 'FAIL_APPLY_OUTCOME_REQUIRES_REVIEW'
            : 'FAIL_CLOSED',
        'failed_stage' => $safeCode,
        'control_plane_sha' => safeEnv('CAREER_POINTER_CONTROL_PLANE_SHA', 40),
        'release_sha' => safeEnv('CAREER_POINTER_EXPECTED_RELEASE_SHA', 40),
        'release_name_sha256' => hash('sha256', (string) getenv('CAREER_POINTER_EXPECTED_RELEASE_NAME')),
        'workflow_run_id' => safePositiveIntEnv('CAREER_POINTER_WORKFLOW_RUN_ID'),
        'workflow_run_attempt' => safePositiveIntEnv('CAREER_POINTER_WORKFLOW_RUN_ATTEMPT'),
        'generation_id' => safeIdentityEnv('CAREER_POINTER_GENERATION_ID'),
        'production_write_execution' => (bool) ($writeState['production_write_execution'] ?? false),
        'candidate_file_write_count' => (int) ($writeState['candidate_file_write_count'] ?? 0),
        'pointer_write_count' => (int) ($writeState['pointer_write_count'] ?? 0),
        'directory_write_count' => (int) ($writeState['directory_write_count'] ?? 0),
        'database_write_count' => 0,
        'cms_write_count' => 0,
        'cache_write_count' => 0,
        'source_artifact_write_count' => 0,
        'deployment_count' => 0,
        'migration_count' => 0,
        'publication_write_count' => 0,
        'sitemap_write_count' => 0,
        'llms_write_count' => 0,
        'search_submission_count' => 0,
        'automatic_retry_allowed' => false,
        'automatic_cleanup_allowed' => false,
        'automatic_rollback_allowed' => false,
        'write_state' => (string) ($writeState['write_state'] ?? 'indeterminate'),
        'writes_committed' => (bool) ($writeState['writes_committed'] ?? false),
        'zero_write_guarantee' => ! $apply && ! ($writeState['production_write_execution'] ?? false),
    ];
}

/** @param array<string, mixed> $receipt */
function emitReceipt(array $receipt): void
{
    fwrite(STDOUT, json_encode(
        $receipt,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ).PHP_EOL);
}

function absoluteDirectoryEnv(string $name): string
{
    $value = rtrim(trim((string) getenv($name)), '/');
    if ($value === '' || $value[0] !== '/' || str_contains($value, '..') || is_link($value) || ! is_dir($value)) {
        throw new CareerPointerBootstrapFailure($name.'_INVALID');
    }

    return $value;
}

function identityEnv(string $name): string
{
    $value = trim((string) getenv($name));
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,127}$/D', $value) !== 1) {
        throw new CareerPointerBootstrapFailure($name.'_INVALID');
    }

    return $value;
}

function shaEnv(string $name, int $length = 64): string
{
    $value = strtolower(trim((string) getenv($name)));
    if (preg_match('/^[0-9a-f]{'.$length.'}$/D', $value) !== 1) {
        throw new CareerPointerBootstrapFailure($name.'_INVALID');
    }

    return $value;
}

function positiveIntEnv(string $name): int
{
    $value = trim((string) getenv($name));
    if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
        throw new CareerPointerBootstrapFailure($name.'_INVALID');
    }

    return (int) $value;
}

function timestampEnv(string $name): string
{
    $value = trim((string) getenv($name));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);
    if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $value) {
        throw new CareerPointerBootstrapFailure($name.'_INVALID');
    }

    return $value;
}

function normalizedSlug(mixed $value): string
{
    if (! is_string($value) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) !== 1) {
        throw new CareerPointerBootstrapFailure('SLUG_INVALID');
    }

    return $value;
}

function assertContainedDirectory(string $root, string $path): void
{
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    if (! is_string($rootReal) || ! is_string($pathReal) || is_link($path)
        || ! str_starts_with($pathReal, $rootReal.'/') || ! is_dir($pathReal)) {
        throw new CareerPointerBootstrapFailure('DIRECTORY_BOUNDARY_INVALID');
    }
}

function assertResolvedDirectory(string $path): void
{
    if (is_link($path) || ! is_dir($path) || ! is_string(realpath($path))) {
        throw new CareerPointerBootstrapFailure('DIRECTORY_BOUNDARY_INVALID');
    }
}

function assertContainedRegularFile(string $root, string $path): void
{
    $rootReal = realpath($root);
    $pathReal = realpath($path);
    if (! is_string($rootReal) || ! is_string($pathReal) || is_link($path)
        || ! str_starts_with($pathReal, $rootReal.'/') || ! is_file($pathReal)) {
        throw new CareerPointerBootstrapFailure('ARTIFACT_PATH_SAFETY_INVALID');
    }
}

/** @param array<string, mixed> $value */
function canonicalJsonSha256(array $value): string
{
    return hash('sha256', canonicalJson($value));
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

function canonicalJson(mixed $value): string
{
    $sort = function (mixed $item) use (&$sort): mixed {
        if (! is_array($item)) {
            return $item;
        }
        if (! array_is_list($item)) {
            ksort($item, SORT_STRING);
        }
        foreach ($item as $key => $child) {
            $item[$key] = $sort($child);
        }

        return $item;
    };

    return json_encode(
        $sort($value),
        JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
}

/** @param array<string, mixed> $root @param list<string> $segments */
function valueAt(array $root, array $segments): mixed
{
    $value = $root;
    foreach ($segments as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

function safeEnv(string $name, int $length): string
{
    $value = strtolower(trim((string) getenv($name)));

    return preg_match('/^[0-9a-f]{'.$length.'}$/D', $value) === 1 ? $value : str_repeat('0', $length);
}

function safePositiveIntEnv(string $name): int
{
    $value = trim((string) getenv($name));

    return preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : 0;
}

function safeIdentityEnv(string $name): string
{
    $value = trim((string) getenv($name));

    return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,127}$/D', $value) === 1 ? $value : 'UNKNOWN';
}
