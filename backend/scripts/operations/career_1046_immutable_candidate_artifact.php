<?php

declare(strict_types=1);

namespace FermatMind\Operations;

use App\Domain\Career\Publish\Career1046ImmutableCandidateGenerator;
use App\Domain\Career\Publish\CareerFullReleaseLedgerProjectionService;
use App\Domain\Career\Publish\CareerGenerationCanonicalJson;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionService;
use App\Http\Resources\Career\CareerJobDetailResource;
use App\Models\IndexState;
use App\Models\Occupation;
use App\Services\Career\Bundles\CareerJobDetailBundleBuilder;
use Illuminate\Cache\Events\CacheFlushing;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class Career1046ImmutableCandidateArtifactFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

/**
 * SELECT-only producer for the Task 5 candidate-artifact contract.
 * Its stdout is intentionally the one candidate JSON document consumed by GitHub.
 */
final class Career1046ImmutableCandidateArtifactProducer
{
    public const CONTRACT_VERSION = 'career.1046.immutable_candidate_artifact_producer.v2';

    /** @var array<string, string> */
    private const STAGE_FAILURE_CODES = [
        'initialization' => 'CANDIDATE_INITIALIZATION_STAGE_FAILURE',
        'read_only_transaction' => 'CANDIDATE_READ_ONLY_TRANSACTION_STAGE_FAILURE',
        'audit_initialization' => 'CANDIDATE_AUDIT_INITIALIZATION_STAGE_FAILURE',
        'ledger' => 'CANDIDATE_LEDGER_STAGE_FAILURE',
        'projection' => 'CANDIDATE_PROJECTION_STAGE_FAILURE',
        'detail_bundle' => 'CANDIDATE_DETAIL_BUNDLE_STAGE_FAILURE',
        'resource_transform' => 'CANDIDATE_RESOURCE_TRANSFORM_STAGE_FAILURE',
        'generator' => 'CANDIDATE_GENERATOR_STAGE_FAILURE',
        'serialization' => 'CANDIDATE_SERIALIZATION_STAGE_FAILURE',
        'audit_finalization' => 'CANDIDATE_AUDIT_FINALIZATION_STAGE_FAILURE',
    ];

    public static function emitStreamedRunner(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = [
            $root.'/app/Domain/Career/Publish/CareerGenerationCanonicalJson.php',
            $root.'/app/Domain/Career/Publish/Career1046ImmutableCandidateGenerator.php',
            $root.'/scripts/operations/career_publication_index_reconciliation_apply.php',
            __FILE__,
        ];
        $bundle = "<?php\ndeclare(strict_types=1);\n";
        foreach ($sources as $sourcePath) {
            $source = file_get_contents($sourcePath);
            if (! is_string($source)) {
                throw new Career1046ImmutableCandidateArtifactFailure('STREAMED_RUNNER_SOURCE_UNREADABLE');
            }
            $source = preg_replace('/\\A<\\?php\\s+declare\\(strict_types=1\\);\\s*/', "\n", $source, 1, $openingReplacements);
            if (! is_string($source) || $openingReplacements !== 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('STREAMED_RUNNER_SOURCE_INVALID');
            }
            if ($sourcePath === __FILE__) {
                $source = preg_replace('/\\nif \\(realpath\\(\\(string\\) \\(\\$_SERVER\\[\'SCRIPT_FILENAME\'\\] \\?\\? \'\'\\)\\) === __FILE__ \\|\\| getenv\\(\'CAREER_1046_STREAMED_EXECUTION\'\\) === \'1\'\\) \\{[\\s\\S]*\\z/', "\n", $source, 1, $entrypointReplacements);
            } elseif (str_ends_with($sourcePath, 'career_publication_index_reconciliation_apply.php')) {
                $source = preg_replace('/\\nif \\(realpath\\(\\(string\\) \\(\\$_SERVER\\[\'SCRIPT_FILENAME\'\\] \\?\\? \'\'\\)\\) === __FILE__ \\|\\| __FILE__ === \'\\/dev\\/stdin\'\\) \\{\\n    exit\\(CareerPublicationIndexReconciliationApply::main\\(\\$argv\\)\\);\\n\\}\\s*\\z/', "\n", $source, 1, $entrypointReplacements);
            } else {
                $entrypointReplacements = 1;
            }
            if (! is_string($source) || $entrypointReplacements !== 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('STREAMED_RUNNER_SOURCE_INVALID');
            }
            $bundle .= "\n".$source;
        }

        echo $bundle."\nexit(\\FermatMind\\Operations\\Career1046ImmutableCandidateArtifactProducer::main());\n";
    }

    /** @return array<string, mixed> */
    public static function produceFromSource(array $source, array $task3b): array
    {
        self::assertTask3bAuthority($task3b);
        foreach (['manifest_path', 'baseline_authority_slugs', 'database_matching_receipt_slugs', 'ledger', 'projection', 'detail_rows'] as $field) {
            if (! array_key_exists($field, $source)) {
                throw new Career1046ImmutableCandidateArtifactFailure('SOURCE_'.$field.'_MISSING');
            }
        }

        $candidate = self::executeStage('generator', static function () use ($source): array {
            try {
                return (new Career1046ImmutableCandidateGenerator)->generate(
                    (string) $source['manifest_path'],
                    is_array($source['baseline_authority_slugs']) ? $source['baseline_authority_slugs'] : [],
                    is_array($source['database_matching_receipt_slugs']) ? $source['database_matching_receipt_slugs'] : [],
                    is_array($source['ledger']) ? $source['ledger'] : [],
                    is_array($source['projection']) ? $source['projection'] : [],
                    is_array($source['detail_rows']) ? $source['detail_rows'] : [],
                );
            } catch (RuntimeException) {
                throw new Career1046ImmutableCandidateArtifactFailure('CANDIDATE_AUTHORITY_CONTRACT_FAILURE');
            }
        });

        $binding = [
            'contract_version' => self::CONTRACT_VERSION,
            'task_3b_apply_run_id' => $task3b['run_id'],
            'task_3b_apply_run_attempt' => $task3b['run_attempt'],
            'task_3b_artifact_digest' => $task3b['artifact_digest'],
            'task_3b_receipt_sha256' => $task3b['receipt_sha256'],
            'control_plane_sha' => $task3b['control_plane_sha'],
            'active_release_sha' => $task3b['release_sha'],
            'active_release_name_sha256' => $task3b['release_name_sha256'],
            'database_state_sha256' => $task3b['database_state_sha256'],
            'receipt_covered_publication_index_authority' => true,
            'receipt_covered_slug_count' => Career1046ImmutableCandidateGenerator::RECEIPT_COUNT,
            'baseline_slug_count' => Career1046ImmutableCandidateGenerator::BASELINE_COUNT,
            'target_slug_count' => Career1046ImmutableCandidateGenerator::TARGET_COUNT,
            'target_locale_row_count' => Career1046ImmutableCandidateGenerator::TARGET_LOCALE_ROW_COUNT,
            'forbidden_slug_count' => count(Career1046ImmutableCandidateGenerator::FORBIDDEN_SLUGS),
            'production_read_only' => true,
            'database_write_count' => 0,
            'cms_write_count' => 0,
            'cache_write_count' => 0,
            'artifact_tree_write_count' => 0,
            'pointer_write_count' => 0,
            'sitemap_write_count' => 0,
            'llms_write_count' => 0,
            'search_submission_count' => 0,
        ];
        $candidate['candidate_receipt']['producer_authority'] = $binding;
        $candidate['documents']['candidate-receipt.json'] = $candidate['candidate_receipt'];

        return $candidate;
    }

    /** @return array<string, mixed> */
    /**
     * Execute the same audited transaction used by the streamed production
     * entrypoint. The optional producer exists only so focused acceptance tests
     * can exercise the real DB/cache guards without recreating 1046 detail
     * documents in a second fixture authority.
     *
     * @param  null|callable(string, array<string, mixed>): array<string, mixed>  $producer
     * @return array<string, mixed>
     */
    public static function produceFromDatabase(?callable $producer = null): array
    {
        [$applicationRoot, $app, $task3b, $connection, $cache] = self::executeStage(
            'initialization',
            static function (): array {
                $applicationRoot = self::applicationRoot();
                $app = require $applicationRoot.'/bootstrap/app.php';
                $app->make(Kernel::class)->bootstrap();
                $task3b = self::task3bFromEnvironment();
                $connection = DB::connection();
                $cache = app('cache.store');
                if (! $cache instanceof CacheRepository) {
                    throw new Career1046ImmutableCandidateArtifactFailure('CACHE_AUDIT_UNAVAILABLE');
                }

                return [$applicationRoot, $app, $task3b, $connection, $cache];
            },
        );

        $queryVerbs = [];
        $cacheWriteAttempts = 0;
        [$originalDatabaseDispatcher, $originalCacheDispatcher] = self::executeStage(
            'audit_initialization',
            static function () use ($app, $cache, $connection, &$cacheWriteAttempts, &$queryVerbs): array {
                $databaseDispatcher = new Dispatcher($app);
                $databaseDispatcher->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queryVerbs): void {
                    $verb = strtolower((string) strtok(ltrim($query->sql), " \t\r\n"));
                    $queryVerbs[] = $verb;
                    if ($verb !== 'select') {
                        throw new Career1046ImmutableCandidateArtifactFailure('DATABASE_WRITE_ATTEMPT');
                    }
                });
                $originalDatabaseDispatcher = $connection->getEventDispatcher();
                $connection->setEventDispatcher($databaseDispatcher);

                $cacheDispatcher = new Dispatcher($app);
                foreach ([WritingKey::class, ForgettingKey::class, CacheFlushing::class] as $event) {
                    $cacheDispatcher->listen($event, static function () use (&$cacheWriteAttempts): void {
                        $cacheWriteAttempts++;
                        throw new Career1046ImmutableCandidateArtifactFailure('CACHE_WRITE_ATTEMPT');
                    });
                }
                $originalCacheDispatcher = $cache->getEventDispatcher();
                $cache->setEventDispatcher($cacheDispatcher);

                return [$originalDatabaseDispatcher, $originalCacheDispatcher];
            },
        );

        try {
            $candidate = self::executeStage(
                'read_only_transaction',
                static fn (): array => self::runReadOnlyTransaction($app, $connection, static function () use ($applicationRoot, $task3b, $producer): array {
                    if ($producer !== null) {
                        return $producer($applicationRoot, $task3b);
                    }

                    return self::produceCandidateInsideTransaction($applicationRoot, $task3b);
                }),
            );
            if ($queryVerbs === [] || array_values(array_unique($queryVerbs)) !== ['select']) {
                throw new Career1046ImmutableCandidateArtifactFailure('DATABASE_SELECT_ONLY_NOT_PROVEN');
            }
            if ($cacheWriteAttempts !== 0) {
                throw new Career1046ImmutableCandidateArtifactFailure('CACHE_ZERO_WRITE_NOT_PROVEN');
            }
            $authority = &$candidate['candidate_receipt']['producer_authority'];
            if (! is_array($authority)) {
                throw new Career1046ImmutableCandidateArtifactFailure('CANDIDATE_RECEIPT_INVALID');
            }
            $authority['database_query_count'] = count($queryVerbs);
            $authority['database_query_verbs'] = ['select'];
            $authority['cache_write_count'] = $cacheWriteAttempts;
            $candidate['documents']['candidate-receipt.json'] = $candidate['candidate_receipt'];

            return $candidate;
        } finally {
            self::executeStage('audit_finalization', static function () use ($cache, $connection, $originalCacheDispatcher, $originalDatabaseDispatcher): void {
                if ($originalDatabaseDispatcher !== null) {
                    $connection->setEventDispatcher($originalDatabaseDispatcher);
                } else {
                    $connection->unsetEventDispatcher();
                }
                if ($originalCacheDispatcher !== null) {
                    $cache->setEventDispatcher($originalCacheDispatcher);
                }
            });
        }
    }

    /**
     * Start the MySQL transaction in read-only mode atomically. The previous
     * `SET TRANSACTION READ ONLY` followed by Laravel's separate transaction
     * open left an unclassified driver boundary before the audit lifecycle.
     */
    private static function runReadOnlyTransaction(mixed $app, mixed $connection, callable $operation): array
    {
        if ($connection->getDriverName() === 'sqlite' && $app->environment('testing')) {
            return $connection->transaction($operation, 1);
        }
        if ($connection->getDriverName() !== 'mysql') {
            throw new Career1046ImmutableCandidateArtifactFailure('READ_ONLY_TRANSACTION_UNSUPPORTED');
        }

        $pdo = $connection->getPdo();
        if ($pdo->inTransaction()) {
            throw new Career1046ImmutableCandidateArtifactFailure('READ_ONLY_TRANSACTION_ALREADY_ACTIVE');
        }
        $originalReadPdo = $connection->getRawReadPdo();
        $connection->setReadPdo($pdo);
        try {
            $pdo->exec('START TRANSACTION READ ONLY');
            try {
                $candidate = $operation();
                if (! $pdo->commit()) {
                    throw new Career1046ImmutableCandidateArtifactFailure('READ_ONLY_TRANSACTION_COMMIT_FAILED');
                }

                return $candidate;
            } catch (Throwable $failure) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $failure;
            }
        } finally {
            $connection->setReadPdo($originalReadPdo);
        }
    }

    /** @param array<string, mixed> $task3b @return array<string, mixed> */
    private static function produceCandidateInsideTransaction(string $applicationRoot, array $task3b): array
    {
        $manifestPath = $applicationRoot.'/docs/seo/generated/detail-ready-1046-rollout-manifest.v2.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! is_array($manifest['baseline_slugs'] ?? null) || ! is_array($manifest['delta_slugs'] ?? null)) {
            throw new Career1046ImmutableCandidateArtifactFailure('FROZEN_MANIFEST_INVALID');
        }
        $ledger = self::executeStage('ledger', static function () use ($applicationRoot, $manifest, $task3b): array {
            self::assertTask3bDatabaseState($applicationRoot, $manifest, $task3b);
            $ledgerEnvelope = app(CareerFullReleaseLedgerProjectionService::class)->build(allowCacheWrites: false);
            $fullLedger = $ledgerEnvelope[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? null;
            if (! is_array($fullLedger)) {
                throw new Career1046ImmutableCandidateArtifactFailure('LEDGER_UNAVAILABLE');
            }

            return self::targetBoundedLedger($fullLedger, $manifest);
        });
        $projection = self::executeStage(
            'projection',
            static fn (): array => app(CareerRuntimePublishProjectionService::class)->buildFromLedgerArray($ledger),
        );
        $details = [];
        $items = is_array($projection['items'] ?? null) ? $projection['items'] : [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['slug'] ?? null) || ! is_string($item['locale'] ?? null)) {
                continue;
            }
            $locale = $item['locale'] === 'zh' ? 'zh-CN' : 'en';
            $bundle = self::executeStage(
                'detail_bundle',
                static fn () => app(CareerJobDetailBundleBuilder::class)->buildBySlug($item['slug'], $locale, $item),
            );
            if ($bundle === null) {
                throw new Career1046ImmutableCandidateArtifactFailure('DETAIL_SOURCE_UNAVAILABLE');
            }
            $details[] = [
                'slug' => $item['slug'],
                'locale' => $item['locale'],
                'payload' => self::executeStage(
                    'resource_transform',
                    static fn (): array => (new CareerJobDetailResource($bundle))->toArray(
                        Request::create('/api/v0.5/career/jobs/'.$item['slug'], 'GET', ['locale' => $locale]),
                    ),
                ),
            ];
        }
        $rows = data_get($ledger, 'public_resolution.rows');
        if (! is_array($rows)) {
            $rows = is_array($ledger['members'] ?? null) ? $ledger['members'] : [];
        }
        $bySlug = [];
        foreach ($rows as $row) {
            $slug = is_array($row) ? ($row['source_slug'] ?? $row['canonical_slug'] ?? null) : null;
            if (is_string($slug)) {
                $bySlug[strtolower($slug)] = true;
            }
        }
        $baseline = array_values(array_filter($manifest['baseline_slugs'], static fn (mixed $slug): bool => is_string($slug) && isset($bySlug[strtolower($slug)])));
        $delta = array_values(array_filter($manifest['delta_slugs'], static fn (mixed $slug): bool => is_string($slug) && isset($bySlug[strtolower($slug)])));

        return self::produceFromSource([
            'manifest_path' => $manifestPath,
            'baseline_authority_slugs' => $baseline,
            'database_matching_receipt_slugs' => $delta,
            'ledger' => $ledger,
            'projection' => $projection,
            'detail_rows' => $details,
        ], $task3b);
    }

    /**
     * Preserve the complete SELECT-only ledger as the upstream calculation, then
     * derive the one immutable candidate authority accepted by Tasks 5-7B.
     *
     * @param  array<string, mixed>  $fullLedger
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public static function targetBoundedLedger(array $fullLedger, array $manifest): array
    {
        $baseline = self::frozenSlugSet($manifest['baseline_slugs'] ?? null, 'BASELINE');
        $delta = self::frozenSlugSet($manifest['delta_slugs'] ?? null, 'DELTA');
        $target = array_values(array_unique([...$baseline, ...$delta]));
        sort($target, SORT_STRING);
        $localeRows = [];
        foreach ($target as $slug) {
            $localeRows[] = $slug.'|en';
            $localeRows[] = $slug.'|zh';
        }
        sort($localeRows, SORT_STRING);
        if (count($baseline) !== Career1046ImmutableCandidateGenerator::BASELINE_COUNT
            || count($delta) !== Career1046ImmutableCandidateGenerator::RECEIPT_COUNT
            || count($target) !== Career1046ImmutableCandidateGenerator::TARGET_COUNT
            || count($localeRows) !== Career1046ImmutableCandidateGenerator::TARGET_LOCALE_ROW_COUNT
            || ! hash_equals(Career1046ImmutableCandidateGenerator::BASELINE_SET_SHA256, CareerGenerationCanonicalJson::setSha256($baseline))
            || ! hash_equals(Career1046ImmutableCandidateGenerator::RECEIPT_SET_SHA256, CareerGenerationCanonicalJson::setSha256($delta))
            || ! hash_equals(Career1046ImmutableCandidateGenerator::TARGET_SET_SHA256, CareerGenerationCanonicalJson::setSha256($target))
            || ! hash_equals(Career1046ImmutableCandidateGenerator::TARGET_LOCALE_ROW_SET_SHA256, CareerGenerationCanonicalJson::setSha256($localeRows))) {
            throw new Career1046ImmutableCandidateArtifactFailure('FROZEN_TARGET_AUTHORITY_INVALID');
        }
        if (array_intersect(Career1046ImmutableCandidateGenerator::FORBIDDEN_SLUGS, $target) !== []) {
            throw new Career1046ImmutableCandidateArtifactFailure('FROZEN_TARGET_CONTAINS_FORBIDDEN');
        }
        if (($fullLedger['ledger_kind'] ?? null) !== \App\Domain\Career\Publish\CareerFullReleaseLedgerService::LEDGER_KIND
            || ($fullLedger['ledger_version'] ?? null) !== \App\Domain\Career\Publish\CareerFullReleaseLedgerService::LEDGER_VERSION
            || ($fullLedger['scope'] ?? null) !== \App\Domain\Career\Publish\CareerFullReleaseLedgerService::SCOPE) {
            throw new Career1046ImmutableCandidateArtifactFailure('FULL_LEDGER_IDENTITY_INVALID');
        }

        $rows = data_get($fullLedger, 'public_resolution.rows');
        if (! is_array($rows) || $rows === []) {
            $rows = $fullLedger['members'] ?? null;
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new Career1046ImmutableCandidateArtifactFailure('FULL_LEDGER_ROWS_INVALID');
        }

        $targetLookup = array_fill_keys($target, true);
        $boundedBySlug = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new Career1046ImmutableCandidateArtifactFailure('FULL_LEDGER_ROW_INVALID');
            }
            $rawSlug = $row['source_slug'] ?? $row['canonical_slug'] ?? $row['slug'] ?? null;
            if (! is_string($rawSlug)) {
                throw new Career1046ImmutableCandidateArtifactFailure('FULL_LEDGER_ROW_INVALID');
            }
            $slug = strtolower(trim($rawSlug));
            if ($slug === '' || $slug !== $rawSlug || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('FULL_LEDGER_ROW_INVALID');
            }
            if (! isset($targetLookup[$slug])) {
                continue;
            }
            if (isset($boundedBySlug[$slug])) {
                throw new Career1046ImmutableCandidateArtifactFailure('TARGET_BOUNDED_LEDGER_DUPLICATE');
            }
            $boundedBySlug[$slug] = $row;
        }

        $boundedSlugs = array_keys($boundedBySlug);
        sort($boundedSlugs, SORT_STRING);
        if ($boundedSlugs !== $target) {
            throw new Career1046ImmutableCandidateArtifactFailure('TARGET_BOUNDED_LEDGER_MISSING');
        }
        if (array_intersect(Career1046ImmutableCandidateGenerator::FORBIDDEN_SLUGS, $boundedSlugs) !== []) {
            throw new Career1046ImmutableCandidateArtifactFailure('TARGET_BOUNDED_LEDGER_FORBIDDEN');
        }

        $boundedRows = array_map(static fn (string $slug): array => $boundedBySlug[$slug], $boundedSlugs);
        $bounded = $fullLedger;
        unset($bounded['public_resolution']);
        $bounded['scope'] = 'career_exact_1046';
        $bounded['members'] = $boundedRows;
        $bounded['counts'] = self::targetBoundedLedgerCounts($boundedRows, $fullLedger['counts'] ?? null);
        if (array_sum($bounded['counts']['release_counts']) !== Career1046ImmutableCandidateGenerator::TARGET_COUNT) {
            throw new Career1046ImmutableCandidateArtifactFailure('TARGET_BOUNDED_LEDGER_COUNTS_INVALID');
        }
        $bounded['target_boundary'] = [
            'target_slug_count' => count($boundedSlugs),
            'target_locale_row_count' => count($localeRows),
            'missing_count' => 0,
            'duplicate_count' => 0,
            'forbidden_count' => 0,
            'outside_target_count' => 0,
            'target_slug_set_sha256' => Career1046ImmutableCandidateGenerator::TARGET_SET_SHA256,
            'target_locale_row_set_sha256' => Career1046ImmutableCandidateGenerator::TARGET_LOCALE_ROW_SET_SHA256,
        ];

        return $bounded;
    }

    /** @return list<string> */
    private static function frozenSlugSet(mixed $values, string $field): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new Career1046ImmutableCandidateArtifactFailure('FROZEN_'.$field.'_INVALID');
        }
        $set = [];
        foreach ($values as $value) {
            if (! is_string($value) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) !== 1 || isset($set[$value])) {
                throw new Career1046ImmutableCandidateArtifactFailure('FROZEN_'.$field.'_INVALID');
            }
            $set[$value] = true;
        }
        $slugs = array_keys($set);
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function targetBoundedLedgerCounts(array $rows, mixed $sourceCounts): array
    {
        $source = is_array($sourceCounts) ? $sourceCounts : [];
        $sourceTracking = is_array($source['tracking_counts'] ?? null) ? $source['tracking_counts'] : [];
        $releaseCounts = is_array($source['release_counts'] ?? null)
            ? array_fill_keys(array_keys($source['release_counts']), 0)
            : [];
        $opsCounts = is_array($source['ops_handoff_counts'] ?? null)
            ? array_fill_keys(array_keys($source['ops_handoff_counts']), 0)
            : [];
        foreach ($rows as $row) {
            $cohort = $row['release_cohort'] ?? null;
            if (is_string($cohort) && $cohort !== '') {
                $releaseCounts[$cohort] = (int) ($releaseCounts[$cohort] ?? 0) + 1;
            }
            if (($row['review_queue_status'] ?? null) === 'queued') {
                $opsCounts['review_queue_total'] = (int) ($opsCounts['review_queue_total'] ?? 0) + 1;
            }
            foreach (['family_handoff', 'review_needed', 'explorer_only'] as $name) {
                if ($cohort === $name) {
                    $opsCounts[$name.'_total'] = (int) ($opsCounts[$name.'_total'] ?? 0) + 1;
                }
            }
            if (($row['override_applied'] ?? null) === true) {
                $opsCounts['override_applied_total'] = (int) ($opsCounts['override_applied_total'] ?? 0) + 1;
            }
            if (($row['current_crosswalk_mode'] ?? null) === 'unmapped') {
                $opsCounts['unmapped_total'] = (int) ($opsCounts['unmapped_total'] ?? 0) + 1;
            }
        }
        ksort($releaseCounts, SORT_STRING);
        ksort($opsCounts, SORT_STRING);
        $firstWaveMembers = count(array_filter(
            $rows,
            static fn (array $row): bool => in_array(
                $row['batch_origin'] ?? null,
                ['first_wave_manifest', 'b71x_excluded_first_wave'],
                true,
            ),
        ));

        return [
            'tracking_counts' => [
                'expected_total_occupations' => Career1046ImmutableCandidateGenerator::TARGET_COUNT,
                'tracked_total_occupations' => Career1046ImmutableCandidateGenerator::TARGET_COUNT,
                'missing_occupations' => 0,
                'tracking_complete' => true,
                'first_wave_members' => $firstWaveMembers,
                'batch_members' => Career1046ImmutableCandidateGenerator::TARGET_COUNT - $firstWaveMembers,
                'first_wave_audit_available' => (bool) ($sourceTracking['first_wave_audit_available'] ?? false),
            ],
            'release_counts' => $releaseCounts,
            'ops_handoff_counts' => $opsCounts,
        ];
    }

    /** @param array<string, mixed> $task3b */
    private static function assertTask3bAuthority(array $task3b): void
    {
        foreach (['run_id', 'run_attempt'] as $field) {
            if (! is_int($task3b[$field] ?? null) || $task3b[$field] < 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_RUN_IDENTITY_INVALID');
            }
        }
        foreach (['receipt_sha256', 'control_plane_sha', 'release_sha', 'release_name_sha256', 'database_state_sha256'] as $field) {
            if (! is_string($task3b[$field] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $task3b[$field]) !== 1 && ! in_array($field, ['control_plane_sha', 'release_sha'], true)) {
                throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_BINDING_INVALID');
            }
        }
        foreach (['control_plane_sha', 'release_sha'] as $field) {
            if (! is_string($task3b[$field] ?? null) || preg_match('/^[0-9a-f]{40}$/D', $task3b[$field]) !== 1) {
                throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_BINDING_INVALID');
            }
        }
        if (! is_string($task3b['artifact_digest'] ?? null) || preg_match('/^sha256:[0-9a-f]{64}$/D', $task3b['artifact_digest']) !== 1) {
            throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_ARTIFACT_INVALID');
        }
    }

    /** @param array<string, mixed> $manifest @param array<string, mixed> $task3b */
    private static function assertTask3bDatabaseState(string $applicationRoot, array $manifest, array $task3b): void
    {
        if (! class_exists(CareerPublicationIndexReconciliationApply::class)) {
            require_once $applicationRoot.'/scripts/operations/career_publication_index_reconciliation_apply.php';
        }
        $targetSlugs = array_values(array_unique([...$manifest['baseline_slugs'], ...$manifest['delta_slugs']]));
        sort($targetSlugs, SORT_STRING);
        $occupations = Occupation::query()
            ->whereIn('canonical_slug', $targetSlugs)
            ->orderBy('canonical_slug')
            ->get(['id', 'canonical_slug'])
            ->map(static fn (Occupation $occupation): array => [
                'id' => (string) $occupation->id,
                'canonical_slug' => strtolower(trim((string) $occupation->canonical_slug)),
            ])
            ->all();
        $occupationIds = array_column($occupations, 'id');
        $states = IndexState::query()
            ->whereIn('occupation_id', $occupationIds)
            ->orderBy('occupation_id')
            ->orderBy('changed_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'occupation_id', 'index_state', 'index_eligible', 'canonical_path', 'canonical_target', 'reason_codes', 'changed_at', 'created_at'])
            ->map(static fn (IndexState $state): array => [
                'id' => (string) $state->id,
                'occupation_id' => (string) $state->occupation_id,
                'index_state' => (string) $state->index_state,
                'index_eligible' => (bool) $state->index_eligible,
                'canonical_path' => (string) $state->canonical_path,
                'canonical_target' => $state->canonical_target === null ? '' : (string) $state->canonical_target,
                'reason_codes' => is_array($state->reason_codes) ? $state->reason_codes : [],
                'changed_at' => $state->changed_at instanceof \DateTimeInterface
                    ? $state->changed_at->format('Y-m-d\\TH:i:s.uP')
                    : trim((string) $state->changed_at),
                'created_at' => $state->created_at instanceof \DateTimeInterface
                    ? $state->created_at->format('Y-m-d\\TH:i:s.uP')
                    : trim((string) $state->created_at),
            ])
            ->all();
        $analysis = CareerPublicationIndexReconciliationApply::analyze($manifest, $manifest['delta_slugs'], $occupations, $states);
        $database = $analysis['database_latest_index_state'] ?? null;
        if (! is_array($database)
            || ($database['matching_count'] ?? null) !== Career1046ImmutableCandidateGenerator::RECEIPT_COUNT
            || ($database['missing_or_mismatching_count'] ?? null) !== 0
            || ($database['latest_state_tie_count'] ?? null) !== 0
            || ! hash_equals((string) $task3b['database_state_sha256'], (string) ($database['current_state_sha256'] ?? ''))) {
            throw new Career1046ImmutableCandidateArtifactFailure('TASK3B_DATABASE_STATE_DRIFT');
        }
    }

    private static function applicationRoot(): string
    {
        $configured = trim((string) getenv('CAREER_1046_APPLICATION_ROOT'));
        $root = $configured === '' ? dirname(__DIR__, 2) : $configured;
        if (! str_starts_with($root, '/') || str_contains($root, '..') || is_link($root) || ! is_dir($root)) {
            throw new Career1046ImmutableCandidateArtifactFailure('APPLICATION_ROOT_INVALID');
        }
        $real = realpath($root);
        if (! is_string($real) || ! is_file($real.'/bootstrap/app.php')) {
            throw new Career1046ImmutableCandidateArtifactFailure('APPLICATION_ROOT_INVALID');
        }

        return $real;
    }

    /** @return array<string, mixed> */
    private static function task3bFromEnvironment(): array
    {
        $read = static fn (string $name): string => strtolower(trim((string) getenv($name)));

        return [
            'run_id' => (int) $read('CAREER_1046_TASK3B_RUN_ID'),
            'run_attempt' => (int) $read('CAREER_1046_TASK3B_RUN_ATTEMPT'),
            'artifact_digest' => $read('CAREER_1046_TASK3B_ARTIFACT_DIGEST'),
            'receipt_sha256' => $read('CAREER_1046_TASK3B_RECEIPT_SHA256'),
            'control_plane_sha' => $read('CAREER_1046_TASK3B_CONTROL_PLANE_SHA'),
            'release_sha' => $read('CAREER_1046_TASK3B_RELEASE_SHA'),
            'release_name_sha256' => $read('CAREER_1046_TASK3B_RELEASE_NAME_SHA256'),
            'database_state_sha256' => $read('CAREER_1046_TASK3B_DATABASE_STATE_SHA256'),
        ];
    }

    /**
     * Collapse an unexpected throwable at a known producer boundary to one
     * allowlisted code. Existing fixed producer failures remain intact.
     */
    public static function executeStage(string $stage, callable $operation): mixed
    {
        $safeCode = self::STAGE_FAILURE_CODES[$stage] ?? null;
        if (! is_string($safeCode)) {
            throw new Career1046ImmutableCandidateArtifactFailure('CANDIDATE_STAGE_INVALID');
        }

        try {
            return $operation();
        } catch (Career1046ImmutableCandidateArtifactFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new Career1046ImmutableCandidateArtifactFailure($safeCode);
        }
    }

    public static function main(): int
    {
        try {
            $candidate = self::produceFromDatabase();
            $encoded = self::executeStage(
                'serialization',
                static fn (): string => CareerGenerationCanonicalJson::encode($candidate),
            );
            echo $encoded."\n";

            return 0;
        } catch (Career1046ImmutableCandidateArtifactFailure $failure) {
            fwrite(STDERR, $failure->safeCode."\n");

            return 1;
        } catch (Throwable) {
            fwrite(STDERR, "CANDIDATE_INITIALIZATION_STAGE_FAILURE\n");

            return 1;
        }
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__ || getenv('CAREER_1046_STREAMED_EXECUTION') === '1') {
    if (($argv[1] ?? null) === '--emit-streamed-runner') {
        Career1046ImmutableCandidateArtifactProducer::emitStreamedRunner();
        exit(0);
    }
    exit(Career1046ImmutableCandidateArtifactProducer::main());
}
