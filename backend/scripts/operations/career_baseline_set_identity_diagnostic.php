<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\CareerGenerationAuthorityLoader;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Domain\Career\Publish\CareerVerifiedRolloutBatchSlugAuthority;
use App\Models\IndexState;
use App\Models\Occupation;
use DateTimeInterface;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

final class CareerBaselineSetIdentityDiagnosticFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerBaselineSetIdentityDiagnostic
{
    public const CONTRACT_VERSION = 'career.baseline_set_identity_diagnostic.v1';

    public const MANIFEST_SHA256 = 'b570ec0cdda65278aa543431886b3529d072de8d67a8e79f1cafbb1c4c8dfc0e';

    public const GENERATION_ID = 'career-current-342-30-bootstrap-v1';

    public const POINTER_SHA256 = '1ebfd2826be9d3b63d810d33050034e3d424c95b3db81fa49b0822c5e6b2ec08';

    public const PROJECTION_SHA256 = '397f2a4ec284e9c0a6cd610447541ad4773fa7a7f3045008fab5efb334ec85c6';

    public const LEDGER_SHA256 = '975b311bb346a090f1add678d5a6d9f1be230f87b223e2c3c829f4c7fd7aac6e';

    public const TARGET_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';

    public const TARGET_LOCALE_ROW_SET_SHA256 = 'c9878e76c817cc09448c32b1dcba3152b22821af34a31204840eb77a2d65857e';

    public const V1_BASELINE_LOCALE_ROW_SET_SHA256 = 'a42b2c69562ee7ea463d8190572f3b9f8244a633e1616d73b2122c3119ecfbee';

    public const EMPTY_SET_SHA256 = '01ba4719c80b6fe911b091a7c05124b64eeece964e09c058ef8f9805daca546b';

    private const MANIFEST_PATH = 'docs/seo/generated/detail-ready-1046-rollout-manifest.v1.json';

    private const PROMOTION_REASON = 'canonical_rollout_batch_promotion';

    /** @var list<string> */
    private const FORBIDDEN_SLUGS = [
        'database-administrators-and-architects',
        'software-developers',
    ];

    /** @param list<string> $values */
    public static function setHash(array $values): string
    {
        return hash('sha256', implode("\n", self::identityList($values))."\n");
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $rawProjection
     * @param  array<string,mixed>  $loaderProjection
     * @param  array<string,mixed>  $ledgerProjection
     * @param  list<string>  $receiptSlugs
     * @return array<string,mixed>
     */
    public static function analyzeSetRelations(
        array $manifest,
        array $rawProjection,
        array $loaderProjection,
        array $ledgerProjection,
        array $receiptSlugs,
    ): array {
        $baseline = self::normalizedList($manifest['baseline_slugs'] ?? null);
        $delta = self::normalizedList($manifest['delta_slugs'] ?? null);
        $target = self::union($baseline, $delta);
        if (count($baseline) !== 30 || count($delta) !== 1016 || count($target) !== 1046
            || ! hash_equals(self::TARGET_SET_SHA256, self::setHash($target))) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('FROZEN_MANIFEST_INVALID');
        }

        $raw = self::projectionSnapshot($rawProjection);
        $loader = self::projectionSnapshot($loaderProjection);
        $ledger = self::projectionSnapshot($ledgerProjection);
        $actual = $raw['published_slugs'];
        $actualRows = $raw['published_locale_rows'];
        $actualDelta = self::difference($target, $actual);
        $receipts = self::normalizedList($receiptSlugs);

        $legacyLocaleHash = self::legacyLowercaseSetHash($actualRows);
        $legacyLocaleHashError = self::sameSet($actual, $baseline)
            && hash_equals(self::V1_BASELINE_LOCALE_ROW_SET_SHA256, self::setHash($actualRows))
            && ! hash_equals(self::V1_BASELINE_LOCALE_ROW_SET_SHA256, $legacyLocaleHash);
        $rawLoaderMismatch = ! self::sameSet($raw['slugs'], $loader['slugs'])
            || ! self::sameSet($raw['locale_rows'], $loader['locale_rows'])
            || ! self::sameSet($actual, $loader['published_slugs'])
            || ! self::sameSet($actualRows, $loader['published_locale_rows']);
        $controlError = $legacyLocaleHashError || $rawLoaderMismatch;
        $projectionInvalid = ! $raw['contract_valid']
            || ! $raw['locale_pairs_complete']
            || ! $raw['published_locale_pairs_complete']
            || count($raw['slugs']) !== 342
            || count($raw['locale_rows']) !== 684
            || count($actual) !== 30
            || count($actualRows) !== 60
            || ! self::sameSet($raw['slugs'], $ledger['slugs'])
            || ! self::sameSet($raw['locale_rows'], $ledger['locale_rows'])
            || ! self::sameSet($actual, $ledger['published_slugs'])
            || ! self::sameSet($actualRows, $ledger['published_locale_rows'])
            || self::difference($actual, $target) !== []
            || self::intersection($actual, self::FORBIDDEN_SLUGS) !== [];
        $manifestStale = ! $projectionInvalid && ! self::sameSet($actual, $baseline);
        $receiptMismatch = ! self::sameSet($receipts, $actualDelta);

        $primary = match (true) {
            $controlError => 'CONTROL_CALCULATION_ERROR',
            $projectionInvalid => 'PROJECTION_PUBLISHED_SET_INVALID',
            $manifestStale => 'MANIFEST_BASELINE_PARTITION_STALE',
            $receiptMismatch => 'RECEIPT_DELTA_PARTITION_MISMATCH',
            default => 'IDENTITIES_EXACT',
        };

        return [
            'primary_classification' => $primary,
            'findings' => [
                'control_calculation_error' => $controlError,
                'raw_loader_set_mismatch' => $rawLoaderMismatch,
                'legacy_locale_hash_normalization_error' => $legacyLocaleHashError,
                'projection_published_set_invalid' => $projectionInvalid,
                'manifest_baseline_partition_stale' => $manifestStale,
                'receipt_delta_partition_mismatch' => $receiptMismatch,
            ],
            'raw_projection' => self::sanitizeProjection($raw),
            'strict_loader_projection' => self::sanitizeProjection($loader),
            'ledger_derived_projection' => self::sanitizeProjection($ledger),
            'actual_published' => self::setEvidence($actual),
            'actual_published_locale_rows' => self::setEvidence($actualRows),
            'legacy_v1_locale_hash_control' => [
                'expected_set_sha256' => self::V1_BASELINE_LOCALE_ROW_SET_SHA256,
                'observed_canonical_set_sha256' => self::setHash($actualRows),
                'observed_legacy_lowercase_set_sha256' => $legacyLocaleHash,
                'normalization_error' => $legacyLocaleHashError,
            ],
            'manifest_baseline' => self::setEvidence($baseline),
            'actual_only' => self::setEvidence(self::difference($actual, $baseline)),
            'manifest_only' => self::setEvidence(self::difference($baseline, $actual)),
            'target' => self::setEvidence($target),
            'target_locale_rows' => self::setEvidence(self::productLocaleRows($target)),
            'actual_to_target' => [
                'contained' => self::difference($actual, $target) === [],
                'missing' => self::setEvidence(self::difference($target, $actual)),
                'outside' => self::setEvidence(self::difference($actual, $target)),
                'forbidden' => self::setEvidence(self::intersection($actual, self::FORBIDDEN_SLUGS)),
            ],
            'actual_delta' => self::setEvidence($actualDelta),
            'signed_receipt_authority' => self::setEvidence($receipts),
            'receipt_to_actual_delta' => [
                'covered' => self::setEvidence(self::intersection($receipts, $actualDelta)),
                'missing' => self::setEvidence(self::difference($actualDelta, $receipts)),
                'outside' => self::setEvidence(self::difference($receipts, $actualDelta)),
                'baseline_overlap' => self::setEvidence(self::intersection($receipts, $actual)),
            ],
            '_internal' => [
                'actual_baseline_slugs' => $actual,
                'actual_delta_slugs' => $actualDelta,
                'target_slugs' => $target,
            ],
        ];
    }

    /**
     * @param  list<string>  $scopeSlugs
     * @param  list<array<string,mixed>>  $occupations
     * @param  list<array<string,mixed>>  $indexStates
     * @return array<string,mixed>
     */
    public static function analyzeDatabase(array $scopeSlugs, array $occupations, array $indexStates): array
    {
        $scope = self::normalizedList($scopeSlugs);
        $occupationBySlug = [];
        $slugByOccupation = [];
        $duplicates = [];
        foreach ($occupations as $occupation) {
            $slug = self::normalizedSlug($occupation['canonical_slug'] ?? null);
            $id = self::stringValue($occupation['id'] ?? null);
            if ($slug === null || $id === '') {
                throw new CareerBaselineSetIdentityDiagnosticFailure('OCCUPATION_IDENTITY_INVALID');
            }
            if (! in_array($slug, $scope, true)) {
                continue;
            }
            if (isset($occupationBySlug[$slug]) || isset($slugByOccupation[$id])) {
                $duplicates[] = $slug;

                continue;
            }
            $occupationBySlug[$slug] = $id;
            $slugByOccupation[$id] = $slug;
        }

        $statesByOccupation = [];
        foreach ($indexStates as $state) {
            $occupationId = self::stringValue($state['occupation_id'] ?? null);
            if ($occupationId === '') {
                throw new CareerBaselineSetIdentityDiagnosticFailure('INDEX_STATE_IDENTITY_INVALID');
            }
            if (! isset($slugByOccupation[$occupationId])) {
                continue;
            }
            $statesByOccupation[$occupationId][] = $state;
        }

        $matching = [];
        $missing = [];
        $mismatching = [];
        $ties = [];
        $stateMismatch = [];
        $eligibilityMismatch = [];
        $pathMismatch = [];
        $targetMismatch = [];
        $reasonMismatch = [];
        $snapshotRows = [];
        foreach ($scope as $slug) {
            $occupationId = $occupationBySlug[$slug] ?? null;
            if ($occupationId === null) {
                $missing[] = $slug;
                $snapshotRows[] = 'slug='.$slug.'|occupation=missing';

                continue;
            }
            $states = $statesByOccupation[$occupationId] ?? [];
            usort($states, self::compareStates(...));
            if (isset($states[1])
                && self::stringValue($states[0]['changed_at'] ?? null) === self::stringValue($states[1]['changed_at'] ?? null)
                && self::stringValue($states[0]['created_at'] ?? null) === self::stringValue($states[1]['created_at'] ?? null)) {
                $ties[] = $slug;
            }
            $latest = $states[0] ?? null;
            if ($latest === null) {
                $missing[] = $slug;
                $snapshotRows[] = 'slug='.$slug.'|occupation_id='.$occupationId.'|latest=missing';

                continue;
            }
            $reasonCodes = self::normalizedList($latest['reason_codes'] ?? []);
            $indexState = strtolower(self::stringValue($latest['index_state'] ?? null));
            $eligible = filter_var($latest['index_eligible'] ?? false, FILTER_VALIDATE_BOOL);
            $path = self::stringValue($latest['canonical_path'] ?? null);
            $target = self::stringValue($latest['canonical_target'] ?? null);
            $snapshotRows[] = implode('|', [
                'slug='.$slug,
                'occupation_id='.$occupationId,
                'index_state_id='.self::stringValue($latest['id'] ?? null),
                'index_state='.$indexState,
                'index_eligible='.($eligible ? '1' : '0'),
                'canonical_path='.$path,
                'canonical_target='.$target,
                'reason_codes='.implode(',', $reasonCodes),
                'changed_at='.self::stringValue($latest['changed_at'] ?? null),
                'created_at='.self::stringValue($latest['created_at'] ?? null),
            ]);
            $matches = $indexState === 'indexed' && $eligible && $path === '/career/jobs/'.$slug
                && $target === '' && in_array(self::PROMOTION_REASON, $reasonCodes, true);
            if ($matches) {
                $matching[] = $slug;

                continue;
            }
            $mismatching[] = $slug;
            if ($indexState !== 'indexed') {
                $stateMismatch[] = $slug;
            }
            if (! $eligible) {
                $eligibilityMismatch[] = $slug;
            }
            if ($path !== '/career/jobs/'.$slug) {
                $pathMismatch[] = $slug;
            }
            if ($target !== '') {
                $targetMismatch[] = $slug;
            }
            if (! in_array(self::PROMOTION_REASON, $reasonCodes, true)) {
                $reasonMismatch[] = $slug;
            }
        }

        return [
            'scope' => self::setEvidence($scope),
            'occupation_missing' => self::setEvidence(self::difference($scope, array_keys($occupationBySlug))),
            'occupation_duplicate' => self::setEvidence($duplicates),
            'matching' => self::setEvidence($matching),
            'missing' => self::setEvidence($missing),
            'mismatching' => self::setEvidence($mismatching),
            'latest_state_ties' => self::setEvidence($ties),
            'state_mismatch' => self::setEvidence($stateMismatch),
            'eligibility_mismatch' => self::setEvidence($eligibilityMismatch),
            'canonical_path_mismatch' => self::setEvidence($pathMismatch),
            'canonical_target_mismatch' => self::setEvidence($targetMismatch),
            'promotion_reason_mismatch' => self::setEvidence($reasonMismatch),
            'current_state_row_count' => count($snapshotRows),
            'current_state_sha256' => self::setHash($snapshotRows),
        ];
    }

    /** @return array<string,mixed> */
    public static function failureReceipt(string $safeCode): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'HOLD_POINTER_BOUND_SET_IDENTITY_DIAGNOSTIC',
            'safe_code' => $safeCode,
            ...self::zeroWriteGuarantees(),
        ];
    }

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        try {
            if (($argv[1] ?? '') !== 'inspect') {
                throw new CareerBaselineSetIdentityDiagnosticFailure('MODE_INVALID');
            }
            $expected = self::expectedEnvironment();
            $backendRoot = self::backendRoot();
            require $backendRoot.'/vendor/autoload.php';
            $app = require $backendRoot.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();
            self::assertNoPendingMigrations($backendRoot);

            $manifest = self::manifest($backendRoot);
            $bound = self::pointerBoundArtifacts($backendRoot);
            $strict = $app->make(CareerGenerationAuthorityLoader::class)->loadStrict();
            $ledgerProjection = $app->make(CareerRuntimePublishProjectionService::class)
                ->buildFromLedgerArray($bound['ledger']);
            $receiptSlugs = $app->make(CareerVerifiedRolloutBatchSlugAuthority::class)->slugs();
            $relations = self::analyzeSetRelations(
                $manifest,
                $bound['projection'],
                $strict['projection'],
                $ledgerProjection,
                $receiptSlugs,
            );

            $internal = $relations['_internal'];
            unset($relations['_internal']);
            $observed = [];
            DB::listen(static function (QueryExecuted $query) use (&$observed): void {
                $observed[] = strtolower((string) strtok(ltrim($query->sql), " \t\r\n"));
            });
            $snapshot = self::databaseSnapshot($internal['target_slugs']);
            $baselineDb = self::analyzeDatabase(
                $internal['actual_baseline_slugs'],
                $snapshot['occupations'],
                $snapshot['index_states'],
            );
            $deltaDb = self::analyzeDatabase(
                $internal['actual_delta_slugs'],
                $snapshot['occupations'],
                $snapshot['index_states'],
            );
            if ($observed === [] || array_values(array_unique($observed)) !== ['select']) {
                throw new CareerBaselineSetIdentityDiagnosticFailure('DATABASE_NOT_SELECT_ONLY');
            }

            self::emit([
                'contract_version' => self::CONTRACT_VERSION,
                'status' => 'PASS_POINTER_BOUND_SET_IDENTITY_DIAGNOSTIC',
                'safe_code' => null,
                'control_plane_sha' => $expected['control_plane_sha'],
                'active_revision' => $expected['active_revision'],
                'active_release_name_sha256' => hash('sha256', $expected['release_name']),
                'workflow_run_id' => $expected['run_id'],
                'workflow_run_attempt' => $expected['run_attempt'],
                'historical_lineage' => [
                    'pointer_apply_run_id' => 31593321673,
                    'failed_baseline_preflight_run_id' => 31658052357,
                ],
                'authority' => [
                    'generation_id' => self::GENERATION_ID,
                    'pointer_document_sha256' => self::POINTER_SHA256,
                    'projection_sha256' => self::PROJECTION_SHA256,
                    'ledger_sha256' => self::LEDGER_SHA256,
                ],
                ...$relations,
                'database_latest_index_state' => [
                    'actual_baseline' => $baselineDb,
                    'actual_delta' => $deltaDb,
                ],
                'observed_database_query_count' => count($observed),
                'database_select_only' => true,
                'deploy_lock_absent' => true,
                'deploy_process_absent' => true,
                'migration_file_delta_count' => 0,
                ...self::zeroWriteGuarantees(),
            ]);

            return 0;
        } catch (CareerBaselineSetIdentityDiagnosticFailure $failure) {
            self::emit(self::failureReceipt($failure->safeCode));

            return 1;
        } catch (Throwable) {
            self::emit(self::failureReceipt('UNEXPECTED_DIAGNOSTIC_FAILURE'));

            return 1;
        }
    }

    /** @return array{projection:array<string,mixed>,ledger:array<string,mixed>} */
    private static function pointerBoundArtifacts(string $backendRoot): array
    {
        $privateRoot = $backendRoot.'/storage/app/private';
        $authorityRoot = $privateRoot.'/career_generation_authority';
        $active = self::readExactJson(
            $authorityRoot,
            $authorityRoot.'/active-generation.json',
            self::POINTER_SHA256,
            'ACTIVE_POINTER',
        );
        $immutable = self::readExactJson(
            $authorityRoot,
            $authorityRoot.'/generations/'.self::GENERATION_ID.'/generation-pointer.json',
            self::POINTER_SHA256,
            'IMMUTABLE_POINTER',
        );
        if ($active !== $immutable
            || ($active['schema_version'] ?? null) !== 'career.generation_pointer.v1'
            || ($active['payload']['generation_id'] ?? null) !== self::GENERATION_ID
            || ($active['payload']['artifact_format'] ?? null) !== 'legacy_exact_bytes_v1') {
            throw new CareerBaselineSetIdentityDiagnosticFailure('POINTER_CONTRACT_INVALID');
        }

        $result = [];
        foreach ([
            'projection' => ['career_runtime_publish_projection', 'career-runtime-publish-projection.json', self::PROJECTION_SHA256],
            'ledger' => ['career_release_ledger', 'career-full-release-ledger.json', self::LEDGER_SHA256],
        ] as $key => [$family, $filename, $sha]) {
            $descriptor = $active['payload']['artifacts'][$key] ?? null;
            $relative = is_array($descriptor) ? ($descriptor['path'] ?? null) : null;
            if (! is_string($relative)
                || ($descriptor['sha256'] ?? null) !== $sha
                || preg_match('#^'.preg_quote($family, '#').'/[A-Za-z0-9][A-Za-z0-9._-]{0,127}/'.preg_quote($filename, '#').'$#D', $relative) !== 1) {
                throw new CareerBaselineSetIdentityDiagnosticFailure(strtoupper($key).'_DESCRIPTOR_INVALID');
            }
            $result[$key] = self::readExactJson($privateRoot, $privateRoot.'/'.$relative, $sha, strtoupper($key));
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private static function projectionSnapshot(array $projection): array
    {
        $items = $projection['items'] ?? null;
        if (($projection['projection_kind'] ?? null) !== 'career_runtime_publish_projection'
            || ($projection['projection_version'] ?? null) !== 'career.runtime_publish_projection.v1'
            || ($projection['source_authority'] ?? null) !== 'CareerFullReleaseLedger'
            || ! is_array($items) || ! array_is_list($items)) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('PROJECTION_CONTRACT_INVALID');
        }
        $slugs = [];
        $rows = [];
        $published = [];
        $publishedRows = [];
        $contractValid = true;
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new CareerBaselineSetIdentityDiagnosticFailure('PROJECTION_ROW_INVALID');
            }
            $slug = self::normalizedSlug($item['slug'] ?? null);
            $locale = match ($item['locale'] ?? null) {
                'en' => 'en',
                'zh', 'zh-CN' => 'zh-CN',
                default => null,
            };
            if ($slug === null || $locale === null || isset($rows[$slug.'|'.$locale])) {
                throw new CareerBaselineSetIdentityDiagnosticFailure('PROJECTION_ROW_IDENTITY_INVALID');
            }
            $slugs[$slug] = true;
            $rows[$slug.'|'.$locale] = true;
            if (($item['runtime_publish_state'] ?? null) === 'published') {
                if (($item['public_resolution_type'] ?? null) !== 'public_canonical_job'
                    || ($item['release_gate_pass'] ?? null) !== true) {
                    $contractValid = false;
                }
                $published[$slug] = true;
                $publishedRows[$slug.'|'.$locale] = true;
            }
        }
        $slugList = self::normalizedList(array_keys($slugs));
        $publishedList = self::normalizedList(array_keys($published));
        $rowList = self::identityList(array_keys($rows));
        $publishedRowList = self::identityList(array_keys($publishedRows));

        return [
            'slugs' => $slugList,
            'locale_rows' => $rowList,
            'published_slugs' => $publishedList,
            'published_locale_rows' => $publishedRowList,
            'contract_valid' => $contractValid,
            'locale_pairs_complete' => self::localeRows($slugList) === $rowList,
            'published_locale_pairs_complete' => self::localeRows($publishedList) === $publishedRowList,
        ];
    }

    /** @return array<string,mixed> */
    private static function sanitizeProjection(array $snapshot): array
    {
        return [
            'slug' => self::setEvidence($snapshot['slugs']),
            'locale_row' => self::setEvidence($snapshot['locale_rows']),
            'published_slug' => self::setEvidence($snapshot['published_slugs']),
            'published_locale_row' => self::setEvidence($snapshot['published_locale_rows']),
            'contract_valid' => $snapshot['contract_valid'],
            'locale_pairs_complete' => $snapshot['locale_pairs_complete'],
            'published_locale_pairs_complete' => $snapshot['published_locale_pairs_complete'],
        ];
    }

    /** @return array{count:int,set_sha256:string} */
    private static function setEvidence(array $values): array
    {
        $normalized = self::identityList($values);

        return ['count' => count($normalized), 'set_sha256' => self::setHash($normalized)];
    }

    /** @return list<string> */
    private static function localeRows(array $slugs): array
    {
        $rows = [];
        foreach (self::normalizedList($slugs) as $slug) {
            $rows[] = $slug.'|en';
            $rows[] = $slug.'|zh-CN';
        }

        return self::identityList($rows);
    }

    /** @return list<string> */
    private static function productLocaleRows(array $slugs): array
    {
        $rows = [];
        foreach (self::normalizedList($slugs) as $slug) {
            $rows[] = $slug.'|en';
            $rows[] = $slug.'|zh';
        }

        return self::identityList($rows);
    }

    /** @return array{occupations:list<array<string,mixed>>,index_states:list<array<string,mixed>>} */
    private static function databaseSnapshot(array $target): array
    {
        $occupations = Occupation::query()->whereIn('canonical_slug', $target)->orderBy('canonical_slug')
            ->get(['id', 'canonical_slug'])->map(static fn (Occupation $row): array => [
                'id' => (string) $row->id,
                'canonical_slug' => strtolower(trim((string) $row->canonical_slug)),
            ])->all();
        $states = IndexState::query()->whereIn('occupation_id', array_column($occupations, 'id'))
            ->orderBy('occupation_id')->orderBy('changed_at')->orderBy('created_at')->orderBy('id')
            ->get([
                'id', 'occupation_id', 'index_state', 'index_eligible', 'canonical_path', 'canonical_target',
                'reason_codes', 'changed_at', 'created_at',
            ])->map(static fn (IndexState $row): array => [
                'id' => (string) $row->id,
                'occupation_id' => (string) $row->occupation_id,
                'index_state' => (string) $row->index_state,
                'index_eligible' => (bool) $row->index_eligible,
                'canonical_path' => (string) $row->canonical_path,
                'canonical_target' => $row->canonical_target === null ? '' : (string) $row->canonical_target,
                'reason_codes' => is_array($row->reason_codes) ? $row->reason_codes : [],
                'changed_at' => self::stringValue($row->changed_at),
                'created_at' => self::stringValue($row->created_at),
            ])->all();

        return ['occupations' => $occupations, 'index_states' => $states];
    }

    private static function assertNoPendingMigrations(string $backendRoot): void
    {
        $files = glob($backendRoot.'/database/migrations/*.php');
        if (! is_array($files) || $files === []) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('MIGRATION_FILES_INVALID');
        }
        $expected = array_map(static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME), $files);
        $applied = DB::table('migrations')->pluck('migration')->map(
            static fn (mixed $value): string => trim((string) $value),
        )->all();
        if (array_values(array_diff($expected, $applied)) !== []) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('PENDING_MIGRATION_PRESENT');
        }
    }

    /** @return array<string,mixed> */
    private static function manifest(string $backendRoot): array
    {
        $bytes = file_get_contents($backendRoot.'/'.self::MANIFEST_PATH);
        $decoded = is_string($bytes) ? json_decode($bytes, true) : null;
        if (! is_string($bytes) || ! is_array($decoded)
            || ! hash_equals(self::MANIFEST_SHA256, hash('sha256', $bytes))) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('MANIFEST_IDENTITY_INVALID');
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private static function readExactJson(string $root, string $path, string $sha, string $stage): array
    {
        self::assertNoSymlinkPath($root, $path, $stage);
        $rootReal = realpath($root);
        $pathReal = realpath($path);
        $stat = $pathReal !== false ? @lstat($pathReal) : false;
        if (! is_string($rootReal) || ! is_string($pathReal) || ! is_array($stat)
            || is_link($path) || ! str_starts_with($pathReal, $rootReal.'/')
            || ! is_file($pathReal) || (int) ($stat['nlink'] ?? 0) !== 1) {
            throw new CareerBaselineSetIdentityDiagnosticFailure($stage.'_PATH_INVALID');
        }
        $bytes = file_get_contents($pathReal);
        if (! is_string($bytes) || ! hash_equals($sha, hash('sha256', $bytes))) {
            throw new CareerBaselineSetIdentityDiagnosticFailure($stage.'_HASH_INVALID');
        }
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerBaselineSetIdentityDiagnosticFailure($stage.'_JSON_INVALID');
        }
        if (! is_array($decoded)) {
            throw new CareerBaselineSetIdentityDiagnosticFailure($stage.'_JSON_INVALID');
        }

        return $decoded;
    }

    private static function assertNoSymlinkPath(string $root, string $path, string $stage): void
    {
        $prefix = rtrim($root, '/').'/';
        if (! str_starts_with($path, $prefix)) {
            throw new CareerBaselineSetIdentityDiagnosticFailure($stage.'_PATH_INVALID');
        }
        $current = rtrim($root, '/');
        foreach (explode('/', substr($path, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new CareerBaselineSetIdentityDiagnosticFailure($stage.'_PATH_INVALID');
            }
            $current .= '/'.$segment;
            if (is_link($current)) {
                throw new CareerBaselineSetIdentityDiagnosticFailure($stage.'_PATH_INVALID');
            }
        }
    }

    /** @return array<string,mixed> */
    private static function expectedEnvironment(): array
    {
        $release = trim((string) getenv('CAREER_SET_DIAGNOSTIC_RELEASE_NAME'));
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $release)) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('RELEASE_NAME_INVALID');
        }

        return [
            'control_plane_sha' => self::shaEnv('CAREER_SET_DIAGNOSTIC_CONTROL_PLANE_SHA', 40),
            'active_revision' => self::shaEnv('CAREER_SET_DIAGNOSTIC_ACTIVE_REVISION', 40),
            'release_name' => $release,
            'run_id' => self::positiveIntEnv('CAREER_SET_DIAGNOSTIC_RUN_ID'),
            'run_attempt' => self::positiveIntEnv('CAREER_SET_DIAGNOSTIC_RUN_ATTEMPT'),
        ];
    }

    private static function backendRoot(): string
    {
        $value = trim((string) getenv('CAREER_SET_DIAGNOSTIC_BACKEND_ROOT'));
        $real = $value !== '' ? realpath($value) : false;
        if (! is_string($real) || ! is_dir($real) || ! str_ends_with($real, '/backend')) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('BACKEND_ROOT_INVALID');
        }

        return $real;
    }

    private static function shaEnv(string $name, int $length = 64): string
    {
        $value = strtolower(trim((string) getenv($name)));
        if (! preg_match('/^[0-9a-f]{'.$length.'}$/D', $value)) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('IDENTITY_ENV_INVALID');
        }

        return $value;
    }

    private static function positiveIntEnv(string $name): int
    {
        $value = trim((string) getenv($name));
        if (! preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('INTEGER_ENV_INVALID');
        }

        return (int) $value;
    }

    /** @return array<string,int|bool> */
    private static function zeroWriteGuarantees(): array
    {
        return [
            'database_insert_count' => 0,
            'database_update_count' => 0,
            'database_delete_count' => 0,
            'database_transaction_committed' => false,
            'cms_write_count' => 0,
            'cache_write_count' => 0,
            'pointer_write_count' => 0,
            'artifact_write_count' => 0,
            'publication_write_count' => 0,
            'discoverability_write_count' => 0,
            'migration_count' => 0,
            'deployment_count' => 0,
            'restart_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
            'writes_committed' => false,
            'automatic_retry_allowed' => false,
            'automatic_rollback_allowed' => false,
        ];
    }

    /** @return list<string> */
    private static function normalizedList(mixed $values): array
    {
        if (! is_array($values)) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('SET_INPUT_INVALID');
        }
        $result = [];
        foreach ($values as $value) {
            $item = is_string($value) ? strtolower(trim($value)) : '';
            if ($item === '') {
                throw new CareerBaselineSetIdentityDiagnosticFailure('SET_ITEM_INVALID');
            }
            $result[$item] = true;
        }
        $list = array_keys($result);
        sort($list, SORT_STRING);

        return $list;
    }

    /** @return list<string> */
    private static function identityList(mixed $values): array
    {
        if (! is_array($values)) {
            throw new CareerBaselineSetIdentityDiagnosticFailure('SET_INPUT_INVALID');
        }
        $result = [];
        foreach ($values as $value) {
            $item = is_string($value) ? trim($value) : '';
            if ($item === '') {
                throw new CareerBaselineSetIdentityDiagnosticFailure('SET_ITEM_INVALID');
            }
            $result[$item] = true;
        }
        $list = array_keys($result);
        sort($list, SORT_STRING);

        return $list;
    }

    /** @param list<string> $values */
    private static function legacyLowercaseSetHash(array $values): string
    {
        return hash('sha256', implode("\n", self::normalizedList($values))."\n");
    }

    private static function normalizedSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $slug = strtolower(trim($value));

        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1 ? $slug : null;
    }

    /** @return list<string> */
    private static function difference(array $left, array $right): array
    {
        return self::normalizedList(array_values(array_diff($left, $right)));
    }

    /** @return list<string> */
    private static function intersection(array $left, array $right): array
    {
        return self::normalizedList(array_values(array_intersect($left, $right)));
    }

    /** @return list<string> */
    private static function union(array $left, array $right): array
    {
        return self::normalizedList([...$left, ...$right]);
    }

    private static function sameSet(array $left, array $right): bool
    {
        return self::identityList($left) === self::identityList($right);
    }

    private static function compareStates(array $left, array $right): int
    {
        foreach (['changed_at', 'created_at', 'id'] as $field) {
            $comparison = strcmp(self::stringValue($right[$field] ?? null), self::stringValue($left[$field] ?? null));
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private static function stringValue(mixed $value): string
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d\TH:i:s.uP')
            : trim((string) ($value ?? ''));
    }

    private static function emit(array $receipt): void
    {
        echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__ || __FILE__ === '/dev/stdin') {
    exit(CareerBaselineSetIdentityDiagnostic::main($argv));
}
