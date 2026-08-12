<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\CareerGenerationAuthorityLoader;
use App\Domain\Career\Publish\CareerVerifiedRolloutBatchSlugAuthority;
use App\Models\IndexState;
use App\Models\Occupation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class Career1046RootActivationFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class Career1046RootGenerationActivation
{
    public const CONTRACT_VERSION = 'career.1046.root_generation_activation.v1';

    public const POINTER_SCHEMA = 'career.generation_pointer.v1';

    public const MANIFEST_SHA256 = 'b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e';

    public const BASELINE_SET_SHA256 = '39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060';

    public const RECEIPT_SET_SHA256 = '09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f';

    public const TARGET_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';

    public const TARGET_LOCALE_SET_SHA256 = 'c9878e76c817cc09448c32b1dcba3152b22821af34a31204840eb77a2d65857e';

    public const TARGET_COUNT = 1046;

    public const TARGET_LOCALE_COUNT = 2092;

    private const REQUIRED_DOCUMENTS = [
        'candidate-receipt.json',
        'career-directory-en.json',
        'career-directory-zh.json',
        'career-full-release-ledger.json',
        'career-job-details-en.json',
        'career-job-details-zh.json',
        'career-runtime-publish-projection.json',
        'generation-manifest.json',
    ];

    /** @var array<string, mixed> */
    private static array $writeState = [
        'production_write_execution' => false,
        'candidate_file_write_count' => 0,
        'pointer_write_count' => 0,
        'root_pointer_switch_count' => 0,
        'database_exclusion_lock_acquired' => false,
        'database_exclusion_lock_released' => false,
        'write_state' => 'none',
        'writes_committed' => false,
    ];

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $mode = trim((string) ($argv[1] ?? ''));
        self::$writeState = [
            'production_write_execution' => false,
            'candidate_file_write_count' => 0,
            'pointer_write_count' => 0,
            'root_pointer_switch_count' => 0,
            'database_exclusion_lock_acquired' => false,
            'database_exclusion_lock_released' => false,
            'write_state' => 'none',
            'writes_committed' => false,
        ];

        try {
            if (! in_array($mode, ['preflight', 'apply'], true)) {
                throw new Career1046RootActivationFailure('MODE_INVALID');
            }
            $expected = self::expectedAuthority($mode);
            self::assertActiveRelease($expected);
            $app = self::bootstrapApplication($expected);
            $current = self::inspectCurrentAndRollback($expected);
            $generation = self::inspectStagedGeneration($expected);
            $database = self::inspectDatabaseAuthority($expected, $app);
            self::assertNoPointerCandidateResidue($current, $expected);

            if ($mode === 'preflight') {
                self::emit(self::successReceipt($mode, $expected, $current, $generation, $database));

                return 0;
            }

            $preflightSha = self::assertApplyAuthorization($expected, $database);
            self::assertActiveRelease($expected);
            $result = self::activate(
                $expected,
                $current,
                $generation,
                $database,
                $preflightSha,
                static fn (): array => self::inspectDatabaseAuthority($expected, $app),
                static fn () => self::acquireDatabaseAuthorityExclusionLock(),
                static fn () => self::releaseDatabaseAuthorityExclusionLock(),
            );
            self::emit(self::successReceipt($mode, $expected, $result, $generation, $database));

            return 0;
        } catch (Career1046RootActivationFailure $failure) {
            self::emit(self::failureReceipt($mode, $failure->safeCode));

            return 1;
        } catch (Throwable) {
            if ((self::$writeState['production_write_execution'] ?? false) === true) {
                self::$writeState['write_state'] = 'indeterminate';
            }
            self::emit(self::failureReceipt($mode, 'UNEXPECTED_ACTIVATION_FAILURE'));

            return 1;
        }
    }

    /** @return array<string, mixed> */
    private static function expectedAuthority(string $mode): array
    {
        $documentHashesJson = base64_decode(self::requiredEnv('CAREER_ACTIVATION_DOCUMENT_HASHES_B64'), true);
        $documentHashes = is_string($documentHashesJson) ? json_decode($documentHashesJson, true) : null;
        if (! is_array($documentHashes) || array_keys($documentHashes) !== self::REQUIRED_DOCUMENTS) {
            throw new Career1046RootActivationFailure('DOCUMENT_HASH_SET_INVALID');
        }
        foreach ($documentHashes as $filename => $sha256) {
            if (! self::safeFilename((string) $filename) || ! self::isSha($sha256)) {
                throw new Career1046RootActivationFailure('DOCUMENT_HASH_SET_INVALID');
            }
        }

        return [
            'mode' => $mode,
            'backend_root' => self::absoluteDirectoryEnv('CAREER_ACTIVATION_BACKEND_ROOT'),
            'private_root' => self::absoluteDirectoryEnv('CAREER_ACTIVATION_PRIVATE_ROOT'),
            'active_release_link' => self::absolutePathEnv('CAREER_ACTIVATION_ACTIVE_RELEASE_LINK'),
            'control_plane_sha' => self::shaEnv('CAREER_ACTIVATION_CONTROL_PLANE_SHA', 40),
            'release_sha' => self::shaEnv('CAREER_ACTIVATION_RELEASE_SHA', 40),
            'release_name' => self::identityEnv('CAREER_ACTIVATION_RELEASE_NAME'),
            'workflow_run_id' => self::positiveIntEnv('CAREER_ACTIVATION_WORKFLOW_RUN_ID'),
            'workflow_run_attempt' => self::positiveIntEnv('CAREER_ACTIVATION_WORKFLOW_RUN_ATTEMPT'),
            'activation_timestamp' => self::timestampEnv('CAREER_ACTIVATION_TIMESTAMP'),
            'generation_id' => self::generationIdEnv('CAREER_ACTIVATION_GENERATION_ID'),
            'previous_generation_id' => self::identityEnv('CAREER_ACTIVATION_PREVIOUS_GENERATION_ID'),
            'previous_pointer_sha256' => self::shaEnv('CAREER_ACTIVATION_PREVIOUS_POINTER_SHA256'),
            'staging_receipt_sha256' => self::shaEnv('CAREER_ACTIVATION_STAGING_RECEIPT_SHA256'),
            'staging_artifact_digest' => self::digestEnv('CAREER_ACTIVATION_STAGING_ARTIFACT_DIGEST'),
            'candidate_receipt_sha256' => self::shaEnv('CAREER_ACTIVATION_CANDIDATE_RECEIPT_SHA256'),
            'generation_manifest_sha256' => self::shaEnv('CAREER_ACTIVATION_GENERATION_MANIFEST_SHA256'),
            'document_hashes' => $documentHashes,
        ];
    }

    /** @param array<string, mixed> $expected */
    private static function assertActiveRelease(array $expected): void
    {
        $activeLink = (string) $expected['active_release_link'];
        $activeRelease = realpath($activeLink);
        $expectedRelease = realpath(dirname((string) $expected['backend_root']));
        if (! is_link($activeLink) || ! is_string($activeRelease) || ! is_string($expectedRelease)
            || ! hash_equals($expectedRelease, $activeRelease)) {
            throw new Career1046RootActivationFailure('ACTIVE_RELEASE_DRIFT');
        }
        $revision = self::readContainedFile($expectedRelease, $expectedRelease.'/REVISION', 128);
        if (! hash_equals((string) $expected['release_sha'], trim($revision))) {
            throw new Career1046RootActivationFailure('ACTIVE_RELEASE_REVISION_DRIFT');
        }
    }

    /** @param array<string, mixed> $expected */
    private static function assertNoConflictingOperation(array $expected): void
    {
        $deployRoot = dirname((string) $expected['active_release_link']);
        $lockPath = $deployRoot.'/.dep/deploy.lock';
        if (is_link($lockPath) || file_exists($lockPath)) {
            throw new Career1046RootActivationFailure('DEPLOY_LOCK_PRESENT');
        }

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $cmdlinePath) {
            $pid = (int) basename(dirname($cmdlinePath));
            if ($pid <= 0 || $pid === getmypid()) {
                continue;
            }
            $raw = @file_get_contents($cmdlinePath);
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $parts = array_values(array_filter(explode("\0", $raw), static fn (string $part): bool => $part !== ''));
            if (self::processCommandIsConflicting($parts)) {
                throw new Career1046RootActivationFailure('CONFLICTING_AUTHORITY_PROCESS_PRESENT');
            }
        }
    }

    /** @param list<string> $parts */
    public static function processCommandIsConflicting(array $parts): bool
    {
        $command = basename((string) ($parts[0] ?? ''));
        $arguments = implode(' ', $parts);
        $phpCommand = preg_match('/^php(?:[0-9]+(?:\.[0-9]+)*)?$/', $command) === 1;
        $phpConflict = $phpCommand && (
            preg_match('/dep(?:\.phar)? .* production/', $arguments) === 1
            || preg_match('/\bartisan\s+migrate(?:\s|$)/', $arguments) === 1
            || preg_match('/\bartisan\s+career:[a-z0-9:_-]+(?:\s|$)/i', $arguments) === 1
            || preg_match('/queue:(?:restart|reload-workers)(?:\s|$)/', $arguments) === 1
            || preg_match('/career_.*(?:apply|activation|staging)/', $arguments) === 1
        );
        $composerConflict = $command === 'composer'
            && preg_match('/(?:^|\s)install(?:\s|$)/', $arguments) === 1;

        return $phpConflict || $composerConflict;
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    public static function inspectCurrentAndRollback(array $expected): array
    {
        $authorityRoot = $expected['private_root'].'/career_generation_authority';
        self::assertContainedDirectory((string) $expected['private_root'], $authorityRoot);
        $generationsRoot = $authorityRoot.'/generations';
        self::assertContainedDirectory($authorityRoot, $generationsRoot);
        $activePath = $authorityRoot.'/active-generation.json';
        $activeRaw = self::readContainedFile($authorityRoot, $activePath, 256_000);
        $activeSha256 = hash('sha256', $activeRaw);
        if (! hash_equals((string) $expected['previous_pointer_sha256'], $activeSha256)) {
            throw new Career1046RootActivationFailure('PREVIOUS_POINTER_SHA256_MISMATCH');
        }
        $active = self::decodePointer($activeRaw, 'ACTIVE_POINTER_INVALID');
        $activeCanonicalSha256 = self::canonicalSha($active);
        $payload = $active['payload'];
        $counts = $payload['counts'] ?? null;
        $discoverability = $payload['discoverability'] ?? null;
        if (($payload['generation_id'] ?? null) !== $expected['previous_generation_id']
            || ! is_array($counts)
            || ($counts['public_slug_count'] ?? null) !== 30
            || ($counts['public_locale_row_count'] ?? null) !== 60
            || ! self::discoverabilityClosed($discoverability)) {
            throw new Career1046RootActivationFailure('PREVIOUS_GENERATION_BOUNDARY_INVALID');
        }

        $rollbackPath = $generationsRoot.'/'.$expected['previous_generation_id'].'/generation-pointer.json';
        $rollbackRaw = self::readContainedFile($generationsRoot, $rollbackPath, 256_000);
        if (! hash_equals($activeSha256, hash('sha256', $rollbackRaw)) || ! hash_equals($activeRaw, $rollbackRaw)) {
            throw new Career1046RootActivationFailure('PREVIOUS_ROLLBACK_POINTER_INCOMPLETE');
        }
        self::decodePointer($rollbackRaw, 'PREVIOUS_ROLLBACK_POINTER_INVALID');
        $runtimePrivateRoot = realpath(storage_path('app/private'));
        $expectedPrivateRoot = realpath((string) $expected['private_root']);
        if (! is_string($runtimePrivateRoot) || ! is_string($expectedPrivateRoot)
            || ! hash_equals($expectedPrivateRoot, $runtimePrivateRoot)) {
            throw new Career1046RootActivationFailure('ROLLBACK_LOADER_ROOT_MISMATCH');
        }
        try {
            $rollbackAuthority = (new CareerGenerationAuthorityLoader)->loadStrict();
        } catch (Throwable) {
            throw new Career1046RootActivationFailure('ROLLBACK_AUTHORITY_UNREADABLE');
        }
        if (($rollbackAuthority['pointer']['generation_id'] ?? null) !== $expected['previous_generation_id']) {
            throw new Career1046RootActivationFailure('ROLLBACK_AUTHORITY_GENERATION_MISMATCH');
        }

        return [
            'authority_root' => $authorityRoot,
            'generations_root' => $generationsRoot,
            'active_path' => $activePath,
            'active_sha256_before' => $activeSha256,
            'active_sha256_after' => $activeSha256,
            'previous_pointer_canonical_sha256' => $activeCanonicalSha256,
            'rollback_pointer_sha256' => hash('sha256', $rollbackRaw),
            'rollback_authority_validated' => true,
            'previous_generation_id' => $expected['previous_generation_id'],
        ];
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    public static function inspectStagedGeneration(array $expected): array
    {
        $authorityRoot = $expected['private_root'].'/career_generation_authority';
        $generationsRoot = $authorityRoot.'/generations';
        $generationRoot = $generationsRoot.'/'.$expected['generation_id'];
        self::assertContainedDirectory($generationsRoot, $generationRoot);
        $entries = array_values(array_diff(scandir($generationRoot) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);
        if ($entries !== self::REQUIRED_DOCUMENTS) {
            throw new Career1046RootActivationFailure('STAGED_DOCUMENT_SET_INVALID');
        }

        $documents = [];
        $readback = [];
        foreach ($expected['document_hashes'] as $filename => $sha256) {
            $raw = self::readContainedFile($generationRoot, $generationRoot.'/'.$filename, 268_435_456);
            if (! hash_equals((string) $sha256, hash('sha256', $raw))) {
                throw new Career1046RootActivationFailure('STAGED_DOCUMENT_READBACK_MISMATCH');
            }
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($document)) {
                throw new Career1046RootActivationFailure('STAGED_DOCUMENT_JSON_INVALID');
            }
            $documents[$filename] = $document;
            $readback[$filename] = hash('sha256', $raw);
        }

        $manifest = $documents['generation-manifest.json'];
        $receipt = $documents['candidate-receipt.json'];
        if (($manifest['schema_version'] ?? null) !== 'career.generation_manifest.v1'
            || ($manifest['generation_id'] ?? null) !== $expected['generation_id']
            || ($manifest['counts'] ?? null) != self::targetCounts()
            || ! self::candidateAuthorityMatches($manifest['authority'] ?? null)
            || ! self::manifestDiscoverabilityClosed($manifest['discoverability'] ?? null)
            || ! hash_equals((string) $expected['generation_manifest_sha256'], self::canonicalSha($manifest))
            || ($receipt['schema_version'] ?? null) !== 'career.1046.immutable_candidate.v1'
            || ($receipt['generation_id'] ?? null) !== $expected['generation_id']
            || ($receipt['counts'] ?? null) != self::targetCounts()
            || ! self::candidateAuthorityMatches($receipt['authority'] ?? null)
            || ! hash_equals((string) $expected['candidate_receipt_sha256'], self::canonicalSha($receipt))
            || ($receipt['generation_manifest_sha256'] ?? null) !== $expected['generation_manifest_sha256']
            || ($receipt['active_pointer_written'] ?? null) !== false
            || ($receipt['published'] ?? null) !== false
            || ($receipt['warmed'] ?? null) !== false) {
            throw new Career1046RootActivationFailure('STAGED_GENERATION_CONTRACT_INVALID');
        }

        foreach (['career-directory-en.json', 'career-directory-zh.json'] as $filename) {
            if (($documents[$filename]['generation_id'] ?? null) !== $expected['generation_id']
                || ($documents[$filename]['public_count'] ?? null) !== self::TARGET_COUNT) {
                throw new Career1046RootActivationFailure('STAGED_DIRECTORY_CONTRACT_INVALID');
            }
        }
        foreach (['career-job-details-en.json', 'career-job-details-zh.json'] as $filename) {
            if (($documents[$filename]['generation_id'] ?? null) !== $expected['generation_id']
                || ($documents[$filename]['count'] ?? null) !== self::TARGET_COUNT) {
                throw new Career1046RootActivationFailure('STAGED_DETAIL_CONTRACT_INVALID');
            }
        }
        foreach (['career-runtime-publish-projection.json', 'career-full-release-ledger.json'] as $filename) {
            if (($documents[$filename]['generation_id'] ?? null) !== $expected['generation_id']) {
                throw new Career1046RootActivationFailure('STAGED_AUTHORITY_CONTRACT_INVALID');
            }
        }

        return [
            'generation_root' => $generationRoot,
            'document_sha256' => $readback,
            'generation_manifest_sha256' => self::canonicalSha($manifest),
            'candidate_receipt_sha256' => self::canonicalSha($receipt),
        ];
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    private static function bootstrapApplication(array $expected): Application
    {
        $backendRoot = (string) $expected['backend_root'];
        require_once $backendRoot.'/vendor/autoload.php';
        $app = require $backendRoot.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /** @param array<string, mixed> $expected @return array<string, mixed> */
    private static function inspectDatabaseAuthority(array $expected, Application $app): array
    {
        $backendRoot = (string) $expected['backend_root'];
        require_once $backendRoot.'/scripts/operations/career_publication_index_reconciliation_preflight.php';

        $verbs = [];
        DB::listen(static function (QueryExecuted $query) use (&$verbs): void {
            $verbs[] = strtolower((string) strtok(ltrim($query->sql), " \t\r\n"));
        });
        $manifestPath = $backendRoot.'/docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json';
        $manifestRaw = file_get_contents($manifestPath);
        $manifest = is_string($manifestRaw) ? json_decode($manifestRaw, true) : null;
        if (! is_string($manifestRaw) || ! is_array($manifest)
            || ! hash_equals(self::MANIFEST_SHA256, hash('sha256', $manifestRaw))) {
            throw new Career1046RootActivationFailure('DATABASE_MANIFEST_INVALID');
        }
        $receiptSlugs = $app->make(CareerVerifiedRolloutBatchSlugAuthority::class)->slugs();
        $deltaSlugs = $manifest['delta_slugs'] ?? null;
        if (! is_array($deltaSlugs)) {
            throw new Career1046RootActivationFailure('DATABASE_DELTA_SET_INVALID');
        }
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $deltaSlugs)
            ->get(['id', 'canonical_slug'])
            ->map(static fn (Occupation $occupation): array => [
                'id' => (string) $occupation->id,
                'canonical_slug' => (string) $occupation->canonical_slug,
            ])->all();
        $indexStates = IndexState::query()
            ->whereIn('occupation_id', array_column($occupations, 'id'))
            ->get(['id', 'occupation_id', 'index_state', 'index_eligible', 'canonical_path', 'canonical_target', 'reason_codes', 'changed_at', 'created_at'])
            ->map(static fn (IndexState $state): array => [
                'id' => (string) $state->id,
                'occupation_id' => (string) $state->occupation_id,
                'index_state' => (string) $state->index_state,
                'index_eligible' => (bool) $state->index_eligible,
                'canonical_path' => (string) $state->canonical_path,
                'canonical_target' => $state->canonical_target === null ? '' : (string) $state->canonical_target,
                'reason_codes' => is_array($state->reason_codes) ? $state->reason_codes : [],
                'changed_at' => self::stringValue($state->changed_at),
                'created_at' => self::stringValue($state->created_at),
            ])->all();
        if ($verbs === [] || array_values(array_unique($verbs)) !== ['select']) {
            throw new Career1046RootActivationFailure('DATABASE_NOT_SELECT_ONLY');
        }
        $analysis = CareerPublicationIndexReconciliationPreflight::analyze(
            $manifest,
            $receiptSlugs,
            $occupations,
            $indexStates,
        );
        $receipt = $analysis['receipt_authority'];
        $database = $analysis['database_latest_index_state'];
        if (($receipt['exact_delta_receipt_authority'] ?? null) !== true
            || ($receipt['authentic_receipt_count'] ?? null) !== 1016
            || ($receipt['outside_target_count'] ?? null) !== 0
            || ($database['receipt_covered_count'] ?? null) !== 1016
            || ($database['matching_count'] ?? null) !== 1016
            || ($database['missing_or_mismatching_count'] ?? null) !== 0
            || ($database['occupation_missing_count'] ?? null) !== 0
            || ($database['latest_state_missing_count'] ?? null) !== 0
            || ($database['latest_state_tie_count'] ?? null) !== 0
            || ($database['full_delta_match'] ?? null) !== true) {
            throw new Career1046RootActivationFailure('DATABASE_AUTHORITY_NOT_EXACT');
        }

        return [
            'receipt_covered_count' => 1016,
            'matching_count' => 1016,
            'missing_or_mismatching_count' => 0,
            'outside_target_count' => 0,
            'current_state_sha256' => $database['current_state_sha256'],
            'query_count' => count($verbs),
            'query_verb_set_sha256' => CareerPublicationIndexReconciliationPreflight::setHash($verbs),
        ];
    }

    private static function acquireDatabaseAuthorityExclusionLock(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new Career1046RootActivationFailure('DATABASE_EXCLUSION_LOCK_UNSUPPORTED');
        }

        try {
            $pdo = $connection->getPdo();
            if ($pdo->exec('SET SESSION lock_wait_timeout = 10') === false
                || $pdo->exec('LOCK TABLES `occupations` READ, `index_states` READ') === false) {
                throw new Career1046RootActivationFailure('DATABASE_EXCLUSION_LOCK_FAILED');
            }
        } catch (Career1046RootActivationFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new Career1046RootActivationFailure('DATABASE_EXCLUSION_LOCK_FAILED');
        }
    }

    private static function releaseDatabaseAuthorityExclusionLock(): void
    {
        try {
            if (DB::connection()->getPdo()->exec('UNLOCK TABLES') === false) {
                throw new Career1046RootActivationFailure('DATABASE_EXCLUSION_UNLOCK_FAILED');
            }
        } catch (Career1046RootActivationFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new Career1046RootActivationFailure('DATABASE_EXCLUSION_UNLOCK_FAILED');
        }
    }

    /** @param array<string, mixed> $current @param array<string, mixed> $expected */
    private static function assertNoPointerCandidateResidue(array $current, array $expected): void
    {
        foreach (scandir((string) $current['authority_root']) ?: [] as $entry) {
            if (str_starts_with($entry, '.active-generation.json.candidate.')) {
                throw new Career1046RootActivationFailure('ACTIVE_POINTER_CANDIDATE_RESIDUE');
            }
        }
        if ($expected['generation_id'] === $expected['previous_generation_id']) {
            throw new Career1046RootActivationFailure('GENERATION_ADVANCE_REQUIRED');
        }
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $database */
    private static function assertApplyAuthorization(array $expected, array $database): string
    {
        if (getenv('CAREER_ACTIVATION_APPLY_AUTHORIZED') !== '1') {
            throw new Career1046RootActivationFailure('APPLY_NOT_AUTHORIZED');
        }
        $preflightSha = self::shaEnv('CAREER_ACTIVATION_PREFLIGHT_RECEIPT_SHA256');
        $expectedDatabaseSha = self::shaEnv('CAREER_ACTIVATION_EXPECTED_DATABASE_STATE_SHA256');
        if (! hash_equals($expectedDatabaseSha, (string) $database['current_state_sha256'])) {
            throw new Career1046RootActivationFailure('DATABASE_AUTHORITY_DRIFT');
        }

        return $preflightSha;
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $generation
     * @param  array<string, mixed>  $database
     * @param  callable(): array<string, mixed>  $databaseRevalidator
     * @param  callable(): void  $databaseLockAcquirer
     * @param  callable(): void  $databaseLockReleaser
     * @return array<string, mixed>
     */
    public static function activate(
        array $expected,
        array $current,
        array $generation,
        array $database,
        string $preflightSha,
        callable $databaseRevalidator,
        callable $databaseLockAcquirer,
        callable $databaseLockReleaser,
    ): array {
        $pointer = self::pointerDocument($expected, $current, $generation, $database, $preflightSha);
        $bytes = self::canonicalJson($pointer)."\n";
        $pointerSha = hash('sha256', $bytes);
        $suffix = $expected['workflow_run_id'].'.'.$expected['workflow_run_attempt'];
        $immutablePath = $generation['generation_root'].'/generation-pointer.json';
        $immutableCandidate = $generation['generation_root'].'/.generation-pointer.json.candidate.'.$suffix;
        $activeCandidate = $current['authority_root'].'/.active-generation.json.candidate.'
            .$expected['workflow_run_id'].'.'.$expected['workflow_run_attempt'];

        self::assertNoConflictingOperation($expected);
        if (is_link($immutablePath) || file_exists($immutablePath)) {
            throw new Career1046RootActivationFailure('IMMUTABLE_POINTER_ALREADY_EXISTS');
        }
        self::assertActiveRelease($expected);
        self::assertGenerationDocumentReadback($expected, $generation);
        self::assertNoConflictingOperation($expected);
        $databaseLockAcquirer();
        self::$writeState['database_exclusion_lock_acquired'] = true;
        try {
            $databaseNow = $databaseRevalidator();
            if (($databaseNow['receipt_covered_count'] ?? null) !== 1016
                || ($databaseNow['matching_count'] ?? null) !== 1016
                || ($databaseNow['missing_or_mismatching_count'] ?? null) !== 0
                || ($databaseNow['outside_target_count'] ?? null) !== 0
                || ! hash_equals(
                    (string) ($database['current_state_sha256'] ?? ''),
                    (string) ($databaseNow['current_state_sha256'] ?? ''),
                )) {
                throw new Career1046RootActivationFailure('DATABASE_AUTHORITY_CHANGED_BEFORE_SWITCH');
            }

            self::assertNoConflictingOperation($expected);
            self::writePointerCandidate(
                $generation['generation_root'],
                $immutableCandidate,
                $bytes,
                $pointerSha,
                'immutable_pointer_candidate',
            );
            $activeNow = self::readContainedFile((string) $current['authority_root'], (string) $current['active_path'], 256_000);
            if (! hash_equals((string) $current['active_sha256_before'], hash('sha256', $activeNow))) {
                throw new Career1046RootActivationFailure('ACTIVE_POINTER_CHANGED_BEFORE_IMMUTABLE_POINTER');
            }
            if (is_link($immutablePath) || file_exists($immutablePath) || ! rename($immutableCandidate, $immutablePath)) {
                throw new Career1046RootActivationFailure('IMMUTABLE_POINTER_COMMIT_FAILED');
            }
            self::$writeState['pointer_write_count'] = 1;
            self::$writeState['write_state'] = 'immutable_pointer_committed';
            self::$writeState['writes_committed'] = true;
            if (! hash_equals($pointerSha, hash('sha256', self::readContainedFile(
                (string) $generation['generation_root'],
                $immutablePath,
                256_000,
            )))) {
                throw new Career1046RootActivationFailure('IMMUTABLE_POINTER_READBACK_FAILED');
            }

            self::writePointerCandidate(
                (string) $current['authority_root'],
                $activeCandidate,
                $bytes,
                $pointerSha,
                'root_pointer_candidate',
            );
            self::assertActiveRelease($expected);
            self::assertGenerationDocumentReadback($expected, $generation);
            self::assertNoConflictingOperation($expected);
            $activeNow = self::readContainedFile((string) $current['authority_root'], (string) $current['active_path'], 256_000);
            if (! hash_equals((string) $current['active_sha256_before'], hash('sha256', $activeNow))) {
                throw new Career1046RootActivationFailure('ACTIVE_POINTER_CHANGED_BEFORE_SWITCH');
            }
            self::$writeState['write_state'] = 'root_pointer_switch_started';
            if (! rename($activeCandidate, (string) $current['active_path'])) {
                throw new Career1046RootActivationFailure('ROOT_POINTER_SWITCH_FAILED');
            }
            self::$writeState['pointer_write_count'] = 2;
            self::$writeState['root_pointer_switch_count'] = 1;
            self::$writeState['write_state'] = 'root_pointer_switched';
            $activeAfter = self::readContainedFile((string) $current['authority_root'], (string) $current['active_path'], 256_000);
            $immutableAfter = self::readContainedFile(
                (string) $generation['generation_root'],
                $immutablePath,
                256_000,
            );
            if (! hash_equals($pointerSha, hash('sha256', $activeAfter))
                || ! hash_equals($bytes, $activeAfter)
                || ! hash_equals($activeAfter, $immutableAfter)) {
                throw new Career1046RootActivationFailure('ACTIVATED_POINTER_READBACK_FAILED');
            }
        } finally {
            $databaseLockReleaser();
            self::$writeState['database_exclusion_lock_released'] = true;
        }

        return [
            ...$current,
            'active_sha256_after' => $pointerSha,
            'activated_pointer_sha256' => $pointerSha,
            'immutable_pointer_sha256' => $pointerSha,
        ];
    }

    private static function writePointerCandidate(
        string $root,
        string $candidate,
        string $bytes,
        string $pointerSha,
        string $writeState,
    ): void {
        self::$writeState['production_write_execution'] = true;
        self::$writeState['write_state'] = $writeState;
        $handle = fopen($candidate, 'x');
        if ($handle === false) {
            throw new Career1046RootActivationFailure('POINTER_CANDIDATE_CREATE_FAILED');
        }
        self::$writeState['candidate_file_write_count']++;
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new Career1046RootActivationFailure('POINTER_CANDIDATE_WRITE_FAILED');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new Career1046RootActivationFailure('POINTER_CANDIDATE_SYNC_FAILED');
            }
        } finally {
            fclose($handle);
        }
        if (! chmod($candidate, 0640)
            || ! hash_equals($pointerSha, hash('sha256', self::readContainedFile($root, $candidate, 256_000)))) {
            throw new Career1046RootActivationFailure('POINTER_CANDIDATE_READBACK_FAILED');
        }
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $generation */
    private static function assertGenerationDocumentReadback(array $expected, array $generation): void
    {
        foreach ($expected['document_hashes'] as $filename => $sha256) {
            $raw = self::readContainedFile(
                (string) $generation['generation_root'],
                $generation['generation_root'].'/'.$filename,
                268_435_456,
            );
            if (! hash_equals((string) $sha256, hash('sha256', $raw))) {
                throw new Career1046RootActivationFailure('STAGED_DOCUMENT_DRIFT_BEFORE_SWITCH');
            }
        }
    }

    /** @return array<string, mixed> */
    public static function pointerDocument(
        array $expected,
        array $current,
        array $generation,
        array $database,
        string $preflightSha,
    ): array {
        $generationId = (string) $expected['generation_id'];
        $artifactDefinitions = [
            'candidate_receipt' => ['candidate-receipt.json', 'career-1046-candidate-receipt@'.$generationId],
            'directory_en' => ['career-directory-en.json', 'career-directory-en@'.$generationId],
            'directory_zh' => ['career-directory-zh.json', 'career-directory-zh@'.$generationId],
            'ledger' => ['career-full-release-ledger.json', 'career-full-release-ledger@'.$generationId],
            'detail_en' => ['career-job-details-en.json', 'career-job-details-en@'.$generationId],
            'detail_zh' => ['career-job-details-zh.json', 'career-job-details-zh@'.$generationId],
            'projection' => ['career-runtime-publish-projection.json', 'career-runtime-publish-projection@'.$generationId],
            'generation_manifest' => ['generation-manifest.json', 'career-generation-manifest@'.$generationId],
        ];
        $artifacts = [];
        foreach ($artifactDefinitions as $key => [$filename, $identity]) {
            $artifacts[$key] = [
                'identity' => $identity,
                'path' => 'generations/'.$expected['generation_id'].'/'.$filename,
                'sha256' => $generation['document_sha256'][$filename],
            ];
        }
        $payload = [
            'generation_id' => $expected['generation_id'],
            'artifact_format' => 'generation_native_v1',
            'artifacts' => $artifacts,
            'authority' => [
                'frozen_manifest_sha256' => self::MANIFEST_SHA256,
                'baseline_set_sha256' => self::BASELINE_SET_SHA256,
                'receipt_set_sha256' => self::RECEIPT_SET_SHA256,
                'target_slug_set_sha256' => self::TARGET_SET_SHA256,
                'target_locale_row_set_sha256' => self::TARGET_LOCALE_SET_SHA256,
            ],
            'counts' => [
                'public_slug_count' => self::TARGET_COUNT,
                'public_locale_row_count' => self::TARGET_LOCALE_COUNT,
            ],
            'lineage' => [
                'previous_generation_id' => $expected['previous_generation_id'],
                'previous_pointer_sha256' => $current['previous_pointer_canonical_sha256'],
            ],
            'timestamps' => [
                'created_at' => $expected['activation_timestamp'],
                'activated_at' => $expected['activation_timestamp'],
            ],
            'activation_receipt' => [
                'identity' => 'activation:'.$expected['generation_id'],
                'sha256' => $preflightSha,
            ],
            'staging_receipt' => [
                'sha256' => $expected['staging_receipt_sha256'],
                'artifact_digest' => $expected['staging_artifact_digest'],
            ],
            'database_authority' => [
                'receipt_covered_count' => 1016,
                'matching_count' => 1016,
                'missing_or_mismatching_count' => 0,
                'outside_target_count' => 0,
                'current_state_sha256' => $database['current_state_sha256'],
            ],
            'rollback' => [
                'eligible' => true,
                'previous_generation_id' => $expected['previous_generation_id'],
                'previous_pointer_sha256' => $current['previous_pointer_canonical_sha256'],
                'pointer_path' => 'generations/'.$expected['previous_generation_id'].'/generation-pointer.json',
            ],
            'discoverability' => [
                'sitemap_mutated' => false,
                'llms_mutated' => false,
                'search_mutated' => false,
            ],
            'revocation_receipt' => null,
        ];

        return [
            'schema_version' => self::POINTER_SCHEMA,
            'payload_sha256' => self::canonicalSha($payload),
            'payload' => $payload,
        ];
    }

    /** @return array<string, mixed> */
    private static function successReceipt(string $mode, array $expected, array $current, array $generation, array $database): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => $mode,
            'status' => $mode === 'preflight' ? 'PASS_PREFLIGHT_ACTIVATION_ELIGIBLE' : 'PASS_APPLY_ROOT_GENERATION_ACTIVATED',
            'failed_stage' => null,
            'control_plane_sha' => $expected['control_plane_sha'],
            'release_sha' => $expected['release_sha'],
            'release_name_sha256' => hash('sha256', (string) $expected['release_name']),
            'workflow_run_id' => $expected['workflow_run_id'],
            'workflow_run_attempt' => $expected['workflow_run_attempt'],
            'activation_timestamp' => $expected['activation_timestamp'],
            'generation_id' => $expected['generation_id'],
            'previous_generation_id' => $expected['previous_generation_id'],
            'previous_pointer_sha256' => $expected['previous_pointer_sha256'],
            'active_pointer_sha256_before' => $current['active_sha256_before'],
            'active_pointer_sha256_after' => $current['active_sha256_after'],
            'rollback_pointer_sha256' => $current['rollback_pointer_sha256'],
            'rollback_authority_validated' => $current['rollback_authority_validated'],
            'staging_receipt_sha256' => $expected['staging_receipt_sha256'],
            'staging_artifact_digest' => $expected['staging_artifact_digest'],
            'candidate_receipt_sha256' => $generation['candidate_receipt_sha256'],
            'generation_manifest_sha256' => $generation['generation_manifest_sha256'],
            'document_sha256' => $generation['document_sha256'],
            'document_count' => count($generation['document_sha256']),
            'counts' => self::targetCounts(),
            'database_authority' => $database,
            ...self::writeGuarantees(),
            'zero_write_guarantee' => $mode === 'preflight',
        ];
    }

    /** @return array<string, mixed> */
    private static function failureReceipt(string $mode, string $safeCode): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => in_array($mode, ['preflight', 'apply'], true) ? $mode : 'invalid',
            'status' => 'FAIL_'.$safeCode,
            'failed_stage' => $safeCode,
            'control_plane_sha' => self::safeEnv('CAREER_ACTIVATION_CONTROL_PLANE_SHA', 40),
            'release_sha' => self::safeEnv('CAREER_ACTIVATION_RELEASE_SHA', 40),
            'workflow_run_id' => self::safeIntEnv('CAREER_ACTIVATION_WORKFLOW_RUN_ID'),
            'workflow_run_attempt' => self::safeIntEnv('CAREER_ACTIVATION_WORKFLOW_RUN_ATTEMPT'),
            'generation_id' => self::safeEnv('CAREER_ACTIVATION_GENERATION_ID', 64),
            ...self::writeGuarantees(),
            'zero_write_guarantee' => (self::$writeState['production_write_execution'] ?? false) === false,
        ];
    }

    /** @return array<string, mixed> */
    private static function writeGuarantees(): array
    {
        return [
            ...self::$writeState,
            'database_select_only' => true,
            'database_write_count' => 0,
            'cms_write_count' => 0,
            'cache_write_count' => 0,
            'generation_document_write_count' => 0,
            'deployment_count' => 0,
            'migration_count' => 0,
            'restart_count' => 0,
            'publication_write_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
            'automatic_retry_allowed' => false,
            'automatic_cleanup_allowed' => false,
            'automatic_rollback_allowed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private static function decodePointer(string $raw, string $safeCode): array
    {
        $pointer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($pointer)
            || ($pointer['schema_version'] ?? null) !== self::POINTER_SCHEMA
            || ! is_array($pointer['payload'] ?? null)
            || ! is_string($pointer['payload_sha256'] ?? null)
            || ! hash_equals((string) $pointer['payload_sha256'], self::canonicalSha($pointer['payload']))) {
            throw new Career1046RootActivationFailure($safeCode);
        }

        return $pointer;
    }

    private static function candidateAuthorityMatches(mixed $authority): bool
    {
        return is_array($authority) && $authority == [
            'frozen_manifest_sha256' => self::MANIFEST_SHA256,
            'baseline_set_sha256' => self::BASELINE_SET_SHA256,
            'receipt_set_sha256' => self::RECEIPT_SET_SHA256,
            'target_slug_set_sha256' => self::TARGET_SET_SHA256,
            'target_locale_row_set_sha256' => self::TARGET_LOCALE_SET_SHA256,
        ];
    }

    private static function discoverabilityClosed(mixed $discoverability): bool
    {
        return is_array($discoverability)
            && ($discoverability['sitemap_mutated'] ?? null) === false
            && ($discoverability['llms_mutated'] ?? null) === false
            && ($discoverability['search_mutated'] ?? null) === false;
    }

    private static function manifestDiscoverabilityClosed(mixed $discoverability): bool
    {
        return is_array($discoverability)
            && ($discoverability['sitemap_released'] ?? null) === false
            && ($discoverability['llms_released'] ?? null) === false
            && ($discoverability['search_submission_enabled'] ?? null) === false;
    }

    /** @return array<string, int> */
    private static function targetCounts(): array
    {
        return [
            'unique_slugs' => 1046,
            'locale_rows' => 2092,
            'published_slugs' => 1046,
            'published_locale_rows' => 2092,
            'missing' => 0,
            'duplicate' => 0,
            'outside_target' => 0,
        ];
    }

    private static function readContainedFile(string $root, string $path, int $maxBytes): string
    {
        $rootReal = realpath($root);
        $real = realpath($path);
        $size = is_string($real) ? filesize($real) : false;
        if (! is_string($rootReal) || ! is_string($real) || is_link($root) || is_link($path)
            || ! str_starts_with($real, $rootReal.'/') || ! is_int($size) || $size < 1 || $size > $maxBytes) {
            throw new Career1046RootActivationFailure('FILE_BOUNDARY_INVALID');
        }
        $raw = file_get_contents($real);
        if (! is_string($raw)) {
            throw new Career1046RootActivationFailure('FILE_READ_FAILED');
        }

        return $raw;
    }

    private static function assertContainedDirectory(string $root, string $path): void
    {
        $rootReal = realpath($root);
        $real = realpath($path);
        if (! is_string($rootReal) || ! is_string($real) || is_link($root) || is_link($path)
            || ! is_dir($real) || ! str_starts_with($real, $rootReal.'/')) {
            throw new Career1046RootActivationFailure('DIRECTORY_BOUNDARY_INVALID');
        }
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::sortRecursively($value),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private static function canonicalSha(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::sortRecursively($child);
        }

        return $value;
    }

    private static function stringValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s.uP');
        }

        return trim((string) ($value ?? ''));
    }

    private static function emit(array $receipt): void
    {
        echo self::canonicalJson($receipt).PHP_EOL;
    }

    private static function safeFilename(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.-]{0,127}\.json$/D', $value) === 1;
    }

    private static function isSha(mixed $value, int $length = 64): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{'.$length.'}$/D', $value) === 1;
    }

    private static function requiredEnv(string $name): string
    {
        $value = trim((string) getenv($name));
        if ($value === '') {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return $value;
    }

    private static function absoluteDirectoryEnv(string $name): string
    {
        $value = self::requiredEnv($name);
        if (! str_starts_with($value, '/') || str_contains($value, '..') || is_link($value) || ! is_dir($value)) {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return rtrim($value, '/');
    }

    private static function absolutePathEnv(string $name): string
    {
        $value = self::requiredEnv($name);
        if (! str_starts_with($value, '/') || str_contains($value, '..') || strlen($value) > 512) {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return rtrim($value, '/');
    }

    private static function identityEnv(string $name): string
    {
        $value = self::requiredEnv($name);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@-]{0,127}$/D', $value) !== 1) {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return $value;
    }

    private static function generationIdEnv(string $name): string
    {
        $value = self::requiredEnv($name);
        if (preg_match('/^career-1046-[0-9a-f]{32}$/D', $value) !== 1) {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return $value;
    }

    private static function shaEnv(string $name, int $length = 64): string
    {
        $value = self::requiredEnv($name);
        if (! self::isSha($value, $length)) {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return $value;
    }

    private static function digestEnv(string $name): string
    {
        $value = self::requiredEnv($name);
        if (preg_match('/^sha256:[0-9a-f]{64}$/D', $value) !== 1) {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return $value;
    }

    private static function positiveIntEnv(string $name): int
    {
        $value = self::requiredEnv($name);
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return (int) $value;
    }

    private static function timestampEnv(string $name): string
    {
        $value = self::requiredEnv($name);
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) !== 1) {
            throw new Career1046RootActivationFailure($name.'_INVALID');
        }

        return $value;
    }

    private static function safeEnv(string $name, int $maxLength): ?string
    {
        $value = trim((string) getenv($name));

        return $value !== '' && strlen($value) <= $maxLength && preg_match('/^[A-Za-z0-9._:@-]+$/D', $value) === 1 ? $value : null;
    }

    private static function safeIntEnv(string $name): ?int
    {
        $value = trim((string) getenv($name));

        return preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : null;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(Career1046RootGenerationActivation::main($argv));
}
