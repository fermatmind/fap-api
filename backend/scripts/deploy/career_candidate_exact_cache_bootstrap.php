<?php

declare(strict_types=1);

namespace FermatMind\Deploy;

use Closure;
use Illuminate\Contracts\Console\Kernel;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class CareerCandidateExactCacheBootstrapFailure extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        public readonly ?string $failureStage = null,
        public readonly ?string $errorCategory = null,
        public readonly int $attemptCount = 0,
        public readonly int $retryCount = 0,
        public readonly ?int $batchOffset = null,
    ) {
        parent::__construct($safeCode);
    }
}

final class CareerCandidateExactCacheBootstrapRunner
{
    public const CONTRACT_VERSION = 'career.candidate_exact_cache_bootstrap.v2';

    public const AUTHORIZATION_CONTRACT_VERSION = 'career.candidate_exact_cache_bootstrap.authorization.v2';

    public const BATCH_SIZE = 50;

    public const OFFLINE_BUILD_BUDGET_MS = 5000;

    public const RETRY_LIMIT = 1;

    public const RETRY_DELAY_MS = 500;

    /** @var list<string> */
    private const INPUT_NAMES = [
        'FM_CAREER_MODE',
        'FM_CAREER_MANAGED_RELEASES_ROOT',
        'FM_CAREER_CANDIDATE_RELEASE',
        'FM_CAREER_CANDIDATE_SHA',
        'FM_CAREER_EXPECTED_TARGETS',
        'FM_CAREER_EXPECTED_MISSING',
        'FM_CAREER_EXPECTED_COVERAGE_FINGERPRINT',
        'FM_CAREER_AUTHORIZED_COVERAGE_STATE',
        'FM_CAREER_BATCH_OFFSET',
        'FM_CAREER_BATCH_SIZE',
        'FM_CAREER_OFFLINE_BUILD_BUDGET_MS',
        'FM_CAREER_RETRY_LIMIT',
        'FM_CAREER_TARGET_SLUG',
        'FM_CAREER_TARGET_LOCALE',
        'FM_CAREER_DIAGNOSTIC_WRITE',
    ];

    /** @var list<string> */
    private const RETRYABLE_ERROR_CATEGORIES = [
        'build_budget_exceeded',
        'database_transient_read',
    ];

    /** @var list<string> */
    private const SAFE_ERROR_CATEGORIES = [
        'build_budget_exceeded',
        'cache_publish_failed',
        'database_permanent_read',
        'database_transient_read',
        'payload_not_cached',
        'unexpected',
    ];

    /** @var list<string> */
    private const SAFE_FAILURE_STAGES = [
        'initialize_candidate_runtime',
        'load_candidate_application',
        'bootstrap_candidate_kernel',
        'validate_candidate_services',
        'install_database_guard',
        'resolve_candidate_services',
        'pre_batch_coverage',
        'precompute_conversion_closure',
        'build_detail_payload',
        'publish_cache_payload',
        'post_batch_coverage',
    ];

    /** @var list<string> */
    private const COVERED_CLASSIFICATIONS = [
        'ready_active',
        'ready_lkg',
        'legacy_migratable',
    ];

    public static function main(): int
    {
        $environment = self::environment();
        $batchOffset = self::safeBatchOffset($environment);

        try {
            $receipt = self::execute($environment);
            self::emit($receipt);

            return (int) ($receipt['failure_count'] ?? 0) === 0 ? 0 : 1;
        } catch (CareerCandidateExactCacheBootstrapFailure $failure) {
            self::emit(self::failureReceipt(
                $failure->safeCode,
                $failure->failureStage,
                $failure->errorCategory,
                $failure->attemptCount,
                $failure->retryCount,
                $failure->batchOffset ?? $batchOffset,
            ));

            return 1;
        } catch (Throwable) {
            self::emit(self::failureReceipt(
                'UNEXPECTED_RUNNER_FAILURE',
                'initialize_candidate_runtime',
                'unexpected',
                1,
                0,
                $batchOffset,
            ));

            return 1;
        }
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    public static function execute(array $environment): array
    {
        self::assertOnlyAllowlistedInputs($environment);

        $mode = self::required($environment, 'FM_CAREER_MODE');
        if (! in_array($mode, ['preflight', 'batch', 'diagnose_target'], true)) {
            self::fail('INVALID_MODE');
        }
        self::assertModeInputs($environment, $mode);

        $expectedCandidateSha = self::sha(self::required($environment, 'FM_CAREER_CANDIDATE_SHA'));
        $expectedTargets = self::integer(
            self::required($environment, 'FM_CAREER_EXPECTED_TARGETS'),
            1,
            100000,
            'INVALID_EXPECTED_TARGETS',
        );
        $expectedMissingInput = trim((string) ($environment['FM_CAREER_EXPECTED_MISSING'] ?? ''));
        $expectedMissing = null;
        if ($expectedMissingInput !== '') {
            $expectedMissing = self::integer(
                $expectedMissingInput,
                0,
                $expectedTargets,
                'INVALID_EXPECTED_MISSING',
            );
        } elseif ($mode !== 'preflight') {
            self::fail('INVALID_EXPECTED_MISSING');
        }
        self::integer(
            self::required($environment, 'FM_CAREER_OFFLINE_BUILD_BUDGET_MS'),
            self::OFFLINE_BUILD_BUDGET_MS,
            self::OFFLINE_BUILD_BUDGET_MS,
            'INVALID_OFFLINE_BUILD_BUDGET',
        );
        self::integer(
            self::required($environment, 'FM_CAREER_RETRY_LIMIT'),
            self::RETRY_LIMIT,
            self::RETRY_LIMIT,
            'INVALID_RETRY_LIMIT',
        );

        $candidateBackend = self::candidateBackend(
            self::required($environment, 'FM_CAREER_MANAGED_RELEASES_ROOT'),
            self::required($environment, 'FM_CAREER_CANDIDATE_RELEASE'),
            $expectedCandidateSha,
        );
        $autoload = $candidateBackend.'/vendor/autoload.php';
        $bootstrap = $candidateBackend.'/bootstrap/app.php';
        if (! is_file($autoload) || ! is_file($bootstrap)) {
            self::fail('CANDIDATE_BOOTSTRAP_MISSING');
        }

        $app = self::candidateRuntimeStage(
            'load_candidate_application',
            static function () use ($autoload, $bootstrap): object {
                require_once $autoload;
                $candidateApp = require $bootstrap;
                if (! is_object($candidateApp) || ! method_exists($candidateApp, 'make')) {
                    self::fail('CANDIDATE_BOOTSTRAP_INVALID');
                }

                return $candidateApp;
            },
        );

        self::candidateRuntimeStage(
            'bootstrap_candidate_kernel',
            static function () use ($app): null {
                $kernel = $app->make(Kernel::class);
                $kernel->bootstrap();

                return null;
            },
        );
        if (! method_exists($app, 'environment') || ! $app->environment('production')) {
            self::fail('NON_PRODUCTION_RUNTIME');
        }

        $coverageClass = 'App\\Services\\Career\\CareerJobDetailCacheCoverageService';
        $cacheClass = 'App\\Services\\Career\\PublicCareerAuthorityResponseCache';
        $conversionClass = 'App\\Services\\Analytics\\CareerConversionClosureBuilder';
        self::candidateRuntimeStage(
            'validate_candidate_services',
            static function () use ($coverageClass, $cacheClass, $conversionClass): null {
                self::assertServiceSignatures($coverageClass, $cacheClass, $conversionClass);

                return null;
            },
        );
        self::candidateRuntimeStage(
            'install_database_guard',
            static function () use ($app): null {
                self::installDatabaseGuard($app);

                return null;
            },
        );

        [$coverage, $cache, $conversion] = self::candidateRuntimeStage(
            'resolve_candidate_services',
            static fn (): array => [
                $app->make($coverageClass),
                $app->make($cacheClass),
                $app->make($conversionClass),
            ],
        );
        $inspect = static fn (): array => $coverage->inspect(['en', 'zh-CN'], 0);
        $inspectionRead = self::coverageInspectionWithRetry($inspect);
        $inspection = $inspectionRead['inspection'];
        $coverageFingerprint = self::coverageFingerprint($inspection);
        $expectedCoverageFingerprint = trim(
            (string) ($environment['FM_CAREER_EXPECTED_COVERAGE_FINGERPRINT'] ?? ''),
        );
        $authorizedCoverageState = trim(
            (string) ($environment['FM_CAREER_AUTHORIZED_COVERAGE_STATE'] ?? ''),
        );
        $preflightConcurrentCoverageGain = 0;
        if ($authorizedCoverageState !== '') {
            if (
                ! in_array($mode, ['preflight', 'batch'], true)
                || $expectedMissing === null
                || preg_match('/^[0-9a-f]{64}$/D', $expectedCoverageFingerprint) !== 1
            ) {
                self::fail('INVALID_AUTHORIZED_COVERAGE_STATE');
            }
            $preflightConcurrentCoverageGain = self::assertAuthorizedPreflightTransition(
                $inspection,
                $authorizedCoverageState,
                $expectedCoverageFingerprint,
                $expectedTargets,
                $expectedMissing,
            );
        } else {
            self::assertInspection(
                $inspection,
                $expectedTargets,
                $expectedMissing ?? $expectedTargets,
                $expectedMissing !== null,
            );
            if (
                $expectedCoverageFingerprint !== ''
                && (
                    preg_match('/^[0-9a-f]{64}$/D', $expectedCoverageFingerprint) !== 1
                    || ! hash_equals($expectedCoverageFingerprint, $coverageFingerprint)
                )
            ) {
                self::fail('COVERAGE_FINGERPRINT_DRIFT');
            }
        }

        if ($mode === 'preflight') {
            return self::preflightReceipt(
                $expectedCandidateSha,
                $inspection,
                $preflightConcurrentCoverageGain,
                $authorizedCoverageState === '' ? null : $expectedCoverageFingerprint,
                $inspectionRead['attempt_count'],
                $inspectionRead['retry_count'],
            );
        }

        if ($mode === 'diagnose_target') {
            return self::diagnosticReceipt(
                $expectedCandidateSha,
                $inspection,
                self::required($environment, 'FM_CAREER_TARGET_SLUG'),
                self::required($environment, 'FM_CAREER_TARGET_LOCALE'),
                self::boolean(self::required($environment, 'FM_CAREER_DIAGNOSTIC_WRITE')),
                static fn (array $slugs): array => $conversion->buildForSubjectSlugs($slugs),
                static fn (string $slug, string $locale, array $closure): array => $cache
                    ->warmJobDetailPayloadForOfflineBootstrap($slug, $locale, $closure),
                $inspect,
                $expectedTargets,
            );
        }

        $offset = self::integer(
            self::required($environment, 'FM_CAREER_BATCH_OFFSET'),
            0,
            $expectedTargets - 1,
            'INVALID_BATCH_OFFSET',
        );
        $batchSize = self::integer(
            self::required($environment, 'FM_CAREER_BATCH_SIZE'),
            self::BATCH_SIZE,
            self::BATCH_SIZE,
            'INVALID_BATCH_SIZE',
        );
        if (! self::isValidBatchOffset($offset, $expectedTargets)) {
            self::fail('INVALID_BATCH_OFFSET');
        }

        return self::batchReceipt(
            $expectedCandidateSha,
            $inspection,
            $offset,
            $batchSize,
            static fn (array $slugs): array => $conversion->buildForSubjectSlugs($slugs),
            static fn (string $slug, string $locale, array $closure): array => $cache
                ->warmJobDetailPayloadForOfflineBootstrap($slug, $locale, $closure),
            $inspect,
            $expectedTargets,
            null,
            $preflightConcurrentCoverageGain,
            $authorizedCoverageState === '' ? null : $expectedCoverageFingerprint,
            $inspectionRead['attempt_count'],
            $inspectionRead['retry_count'],
        );
    }

    /**
     * Retry only an explicitly classified transient database read. This
     * envelope covers the candidate's initial full coverage inspection before
     * a batch can own any cache write.
     *
     * @param  Closure(): array<string, mixed>  $inspect
     * @return array{inspection: array<string, mixed>, attempt_count: int, retry_count: int}
     */
    public static function coverageInspectionWithRetry(
        Closure $inspect,
        ?Closure $retryDelay = null,
    ): array {
        $attemptCount = 0;
        $retryCount = 0;
        $delay = $retryDelay ?? static fn (): int => usleep(self::RETRY_DELAY_MS * 1000);

        for ($attempt = 0; $attempt <= self::RETRY_LIMIT; $attempt++) {
            $attemptCount++;

            try {
                $inspection = $inspect();
                if (! is_array($inspection)) {
                    throw new RuntimeException('Invalid coverage inspection.');
                }

                return [
                    'inspection' => $inspection,
                    'attempt_count' => $attemptCount,
                    'retry_count' => $retryCount,
                ];
            } catch (Throwable $throwable) {
                $category = self::safeThrowableCategory($throwable);
                if (
                    $attempt < self::RETRY_LIMIT
                    && $category === 'database_transient_read'
                ) {
                    $retryCount++;
                    $delay();

                    continue;
                }

                throw new CareerCandidateExactCacheBootstrapFailure(
                    'PRE_BATCH_COVERAGE_READ_FAILED',
                    'pre_batch_coverage',
                    $category,
                    $attemptCount,
                    $retryCount,
                );
            }
        }

        throw new CareerCandidateExactCacheBootstrapFailure(
            'PRE_BATCH_COVERAGE_READ_FAILED',
            'pre_batch_coverage',
            'unexpected',
            $attemptCount,
            $retryCount,
        );
    }

    /**
     * Classify candidate-runtime initialization failures without exposing
     * exception text or retrying a partially initialized Laravel process.
     * The workflow may start one fresh PHP process only when this classification
     * is an explicit transient database read.
     */
    public static function candidateRuntimeStage(string $stage, Closure $operation): mixed
    {
        if (! in_array($stage, [
            'load_candidate_application',
            'bootstrap_candidate_kernel',
            'validate_candidate_services',
            'install_database_guard',
            'resolve_candidate_services',
        ], true)) {
            self::fail('INVALID_CANDIDATE_RUNTIME_STAGE');
        }

        try {
            return $operation();
        } catch (CareerCandidateExactCacheBootstrapFailure $failure) {
            throw $failure;
        } catch (Throwable $throwable) {
            throw new CareerCandidateExactCacheBootstrapFailure(
                'CANDIDATE_RUNTIME_INITIALIZATION_FAILED',
                $stage,
                self::safeThrowableCategory($throwable),
                1,
                0,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    public static function preflightReceipt(
        string $candidateSha,
        array $inspection,
        int $concurrentCoverageGain = 0,
        ?string $authorizedCoverageFingerprint = null,
        int $preBatchReadAttemptCount = 1,
        int $preBatchReadRetryCount = 0,
    ): array {
        $coverageState = self::coverageState($inspection);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'preflight',
            'status' => 'ready',
            'candidate_revision' => $candidateSha,
            'batch_offset' => null,
            'batch_size' => self::BATCH_SIZE,
            'offline_build_budget_ms' => self::OFFLINE_BUILD_BUDGET_MS,
            'retry_limit' => self::RETRY_LIMIT,
            'pre_batch_read_attempt_count' => $preBatchReadAttemptCount,
            'pre_batch_read_retry_count' => $preBatchReadRetryCount,
            'inspected_target_count' => (int) ($inspection['report']['expected_target_count'] ?? 0),
            'repairable_target_count' => self::repairableCount($inspection),
            'cache_write_count' => 0,
            'owned_cache_write_count' => 0,
            'concurrent_coverage_gain_count' => $concurrentCoverageGain,
            'failure_count' => 0,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
            'authorized_coverage_fingerprint_sha256' => $authorizedCoverageFingerprint,
            'coverage_fingerprint_sha256' => self::coverageFingerprint($inspection),
            'coverage_state' => $coverageState,
            'coverage_state_sha256' => hash('sha256', $coverageState),
            'coverage' => self::safeCoverage($inspection['report'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  Closure(list<string>): array<string, array<string, mixed>>  $precompute
     * @param  Closure(string, string, array<string, mixed>): array<string, mixed>  $warmer
     * @param  Closure(): array<string, mixed>  $postInspect
     * @return array<string, mixed>
     */
    public static function batchReceipt(
        string $candidateSha,
        array $inspection,
        int $offset,
        int $batchSize,
        Closure $precompute,
        Closure $warmer,
        Closure $postInspect,
        int $expectedTargets,
        ?Closure $retryDelay = null,
        int $preBatchConcurrentCoverageGain = 0,
        ?string $authorizedPreFingerprint = null,
        int $preBatchReadAttemptCount = 1,
        int $preBatchReadRetryCount = 0,
    ): array {
        if (! self::isValidBatchOffset($offset, $expectedTargets) || $batchSize !== self::BATCH_SIZE) {
            self::fail('INVALID_BATCH_BOUNDARY');
        }
        if (
            $preBatchConcurrentCoverageGain < 0
            || $preBatchConcurrentCoverageGain > $expectedTargets
            || (
                $authorizedPreFingerprint !== null
                && preg_match('/^[0-9a-f]{64}$/D', $authorizedPreFingerprint) !== 1
            )
        ) {
            self::fail('INVALID_AUTHORIZED_BATCH_STATE');
        }

        $rows = $inspection['rows'] ?? null;
        if (! is_array($rows) || count($rows) !== $expectedTargets) {
            self::fail('TARGET_ROWS_DRIFT');
        }

        $batch = array_slice($rows, $offset, $batchSize);
        $repairableRows = [];
        foreach ($batch as $relativeIndex => $row) {
            if (
                ! is_array($row)
                || ! is_string($row['slug'] ?? null)
                || ! in_array($row['locale'] ?? null, ['en', 'zh-CN'], true)
                || ! is_bool($row['repairable'] ?? null)
            ) {
                self::fail('INVALID_TARGET_ROW');
            }
            if ($row['repairable'] === true) {
                $repairableRows[] = [
                    ...$row,
                    'absolute_index' => $offset + $relativeIndex,
                ];
            }
        }

        $preFingerprint = self::coverageFingerprint($inspection);
        try {
            $closures = $precompute(array_values(array_unique(array_map(
                static fn (array $row): string => $row['slug'],
                $repairableRows,
            ))));
        } catch (Throwable $throwable) {
            return self::failedBatchReceipt(
                $candidateSha,
                $offset,
                count($batch),
                count($repairableRows),
                0,
                0,
                'precompute_conversion_closure',
                self::safeThrowableCategory($throwable),
                0.0,
                0.0,
                0.0,
                1,
                0,
                null,
                $preFingerprint,
                $preFingerprint,
                $inspection,
                $inspection,
            );
        }

        $writes = 0;
        $attemptCount = 0;
        $retryCount = 0;
        $batchBuildMsTotal = 0.0;
        $batchBuildMsMax = 0.0;
        $failureStage = null;
        $errorCategory = null;
        $failureBuildMs = 0.0;
        $failedTargetHash = null;
        $ownedTargetIndexes = [];
        $delay = $retryDelay ?? static fn (): int => usleep(self::RETRY_DELAY_MS * 1000);

        foreach ($repairableRows as $row) {
            $closure = $closures[$row['slug']] ?? null;
            if (! is_array($closure)) {
                $failureStage = 'precompute_conversion_closure';
                $errorCategory = 'unexpected';
                $failedTargetHash = self::targetIndexHash(
                    $candidateSha,
                    $row['absolute_index'],
                    $row['locale'],
                    $row['slug'],
                );
                break;
            }

            for ($attempt = 0; $attempt <= self::RETRY_LIMIT; $attempt++) {
                $attemptCount++;
                try {
                    $result = $warmer($row['slug'], $row['locale'], $closure);
                } catch (Throwable $throwable) {
                    $result = [
                        'status' => 'failed',
                        'failure_stage' => 'build_detail_payload',
                        'error_category' => self::safeThrowableCategory($throwable),
                        'build_ms' => 0.0,
                    ];
                }

                $buildMs = self::safeMilliseconds($result['build_ms'] ?? 0);
                $batchBuildMsTotal = round($batchBuildMsTotal + $buildMs, 3);
                $batchBuildMsMax = max($batchBuildMsMax, $buildMs);
                if (($result['status'] ?? null) === 'cached') {
                    $failureStage = null;
                    $errorCategory = null;
                    $failureBuildMs = 0.0;
                    $writes++;
                    $ownedTargetIndexes[] = $row['absolute_index'];

                    continue 2;
                }

                $failureStage = self::safeFailureStage($result['failure_stage'] ?? null);
                $errorCategory = self::safeErrorCategory($result['error_category'] ?? null);
                $failureBuildMs = $buildMs;
                if (
                    $attempt < self::RETRY_LIMIT
                    && in_array($errorCategory, self::RETRYABLE_ERROR_CATEGORIES, true)
                ) {
                    $retryCount++;
                    $delay();

                    continue;
                }

                $failedTargetHash = self::targetIndexHash(
                    $candidateSha,
                    $row['absolute_index'],
                    $row['locale'],
                    $row['slug'],
                );
                break 2;
            }
        }

        try {
            $postInspection = $postInspect();
            self::assertInspectionBoundary($postInspection, $expectedTargets);
        } catch (Throwable) {
            $postInspection = $inspection;
            $failureStage = 'post_batch_coverage';
            $errorCategory = 'unexpected';
            $failedTargetHash = null;
        }

        $postFingerprint = self::coverageFingerprint($postInspection);
        $concurrentCoverageGain = 0;
        try {
            $transition = self::assertMonotonicCoverageTransition(
                $inspection,
                $postInspection,
                $ownedTargetIndexes,
                $expectedTargets,
            );
            $concurrentCoverageGain = $transition['concurrent_coverage_gain_count'];
        } catch (Throwable) {
            $failureStage = 'post_batch_coverage';
            $errorCategory = 'unexpected';
            $failedTargetHash = null;
        }

        if ($failureStage !== null || $errorCategory !== null) {
            return self::failedBatchReceipt(
                $candidateSha,
                $offset,
                count($batch),
                count($repairableRows),
                $writes,
                $concurrentCoverageGain,
                $failureStage ?? 'build_detail_payload',
                $errorCategory ?? 'unexpected',
                $failureBuildMs,
                $batchBuildMsTotal,
                $batchBuildMsMax,
                $attemptCount,
                $retryCount,
                $failedTargetHash,
                $preFingerprint,
                $postFingerprint,
                $inspection,
                $postInspection,
            );
        }

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'batch',
            'status' => 'completed',
            'candidate_revision' => $candidateSha,
            'batch_offset' => $offset,
            'batch_size' => $batchSize,
            'offline_build_budget_ms' => self::OFFLINE_BUILD_BUDGET_MS,
            'retry_limit' => self::RETRY_LIMIT,
            'pre_batch_read_attempt_count' => $preBatchReadAttemptCount,
            'pre_batch_read_retry_count' => $preBatchReadRetryCount,
            'inspected_target_count' => count($batch),
            'repairable_target_count' => count($repairableRows),
            'cache_write_count' => $writes,
            'owned_cache_write_count' => $writes,
            'concurrent_coverage_gain_count' => $concurrentCoverageGain,
            'pre_batch_concurrent_coverage_gain_count' => $preBatchConcurrentCoverageGain,
            'failure_count' => 0,
            'failure_stage' => null,
            'error_category' => null,
            'build_ms' => 0.0,
            'batch_build_ms_total' => $batchBuildMsTotal,
            'batch_build_ms_max' => round($batchBuildMsMax, 3),
            'attempt_count' => $attemptCount,
            'retry_count' => $retryCount,
            'failed_target_index_sha256' => null,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
            'authorized_pre_coverage_fingerprint_sha256' => $authorizedPreFingerprint,
            'pre_coverage_fingerprint_sha256' => $preFingerprint,
            'post_coverage_fingerprint_sha256' => $postFingerprint,
            'post_coverage_state' => self::coverageState($postInspection),
            'pre_batch_coverage' => self::safeCoverage($inspection['report'] ?? []),
            'post_batch_coverage' => self::safeCoverage($postInspection['report'] ?? []),
        ];
    }

    public static function isValidBatchOffset(int $offset, int $expectedTargets): bool
    {
        return $offset >= 0
            && $offset < $expectedTargets
            && $offset % self::BATCH_SIZE === 0;
    }

    /**
     * Allow only monotonic cache coverage growth while proving that every
     * successful write owned by this batch is covered in the post-readback.
     * Live HTTP traffic may independently warm other missing targets, but it
     * may not change the target set or hide any coverage regression.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  list<int>  $ownedTargetIndexes
     * @return array{coverage_gain_count: int, concurrent_coverage_gain_count: int}
     */
    public static function assertMonotonicCoverageTransition(
        array $before,
        array $after,
        array $ownedTargetIndexes,
        int $expectedTargets,
    ): array {
        self::assertInspectionBoundary($before, $expectedTargets);
        self::assertInspectionBoundary($after, $expectedTargets);

        $beforeRows = array_values($before['rows']);
        $afterRows = array_values($after['rows']);
        $owned = [];
        foreach ($ownedTargetIndexes as $index) {
            if (! is_int($index) || $index < 0 || $index >= $expectedTargets || isset($owned[$index])) {
                self::fail('INVALID_OWNED_TARGET_INDEX');
            }
            $owned[$index] = true;
        }

        $coverageGain = 0;
        $ownedCovered = 0;
        foreach ($beforeRows as $index => $beforeRow) {
            $afterRow = $afterRows[$index] ?? null;
            if (
                ! is_array($afterRow)
                || $beforeRow['slug'] !== $afterRow['slug']
                || $beforeRow['locale'] !== $afterRow['locale']
            ) {
                self::fail('TARGET_SET_DRIFT');
            }

            $beforeClassification = (string) $beforeRow['classification'];
            $afterClassification = (string) $afterRow['classification'];
            $beforeCovered = self::classificationIsCovered($beforeClassification);
            $afterCovered = self::classificationIsCovered($afterClassification);

            if ($beforeCovered && ! $afterCovered) {
                self::fail('COVERAGE_REGRESSION');
            }
            if ($beforeClassification === 'missing_pointer') {
                if ($afterClassification === 'missing_pointer') {
                    if (isset($owned[$index])) {
                        self::fail('OWNED_TARGET_NOT_COVERED');
                    }

                    continue;
                }
                if (! $afterCovered) {
                    self::fail('NON_MONOTONIC_COVERAGE_TRANSITION');
                }
                $coverageGain++;
            } elseif (! $beforeCovered) {
                self::fail('NON_MONOTONIC_COVERAGE_TRANSITION');
            }

            if (isset($owned[$index])) {
                if ($beforeClassification !== 'missing_pointer' || ! $afterCovered) {
                    self::fail('OWNED_TARGET_NOT_COVERED');
                }
                $ownedCovered++;
            }
        }

        if ($ownedCovered !== count($owned) || $coverageGain < $ownedCovered) {
            self::fail('OWNED_TARGET_NOT_COVERED');
        }

        $beforeMissing = (int) $before['report']['missing_pointer_count'];
        $afterMissing = (int) $after['report']['missing_pointer_count'];
        $beforeCovered = (int) $before['report']['covered_target_count'];
        $afterCovered = (int) $after['report']['covered_target_count'];
        if (
            $beforeMissing - $afterMissing !== $coverageGain
            || $afterCovered - $beforeCovered !== $coverageGain
        ) {
            self::fail('COVERAGE_REPORT_DRIFT');
        }

        return [
            'coverage_gain_count' => $coverageGain,
            'concurrent_coverage_gain_count' => $coverageGain - $ownedCovered,
        ];
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  Closure(list<string>): array<string, array<string, mixed>>  $precompute
     * @param  Closure(string, string, array<string, mixed>): array<string, mixed>  $warmer
     * @param  Closure(): array<string, mixed>  $postInspect
     * @return array<string, mixed>
     */
    public static function diagnosticReceipt(
        string $candidateSha,
        array $inspection,
        string $slug,
        string $locale,
        bool $executeWrite,
        Closure $precompute,
        Closure $warmer,
        Closure $postInspect,
        int $expectedTargets,
    ): array {
        $target = self::diagnosticTarget($inspection, $slug, $locale, $expectedTargets);
        $preFingerprint = self::coverageFingerprint($inspection);
        $preCoverage = self::safeCoverage($inspection['report'] ?? []);
        $targetHash = self::targetIndexHash($candidateSha, $target['index'], $locale, $slug);
        if (! $executeWrite) {
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'mode' => 'diagnose_target',
                'status' => 'ready',
                'candidate_revision' => $candidateSha,
                'target_index_sha256' => $targetHash,
                'diagnostic_write' => false,
                'target_classification' => $target['classification'],
                'offline_build_budget_ms' => self::OFFLINE_BUILD_BUDGET_MS,
                'cache_write_count' => 0,
                'owned_cache_write_count' => 0,
                'concurrent_coverage_gain_count' => 0,
                'failure_count' => 0,
                'failure_stage' => null,
                'error_category' => null,
                'build_ms' => 0.0,
                'queue_dispatch_count' => 0,
                'database_write_count' => 0,
                'pre_coverage_fingerprint_sha256' => $preFingerprint,
                'post_coverage_fingerprint_sha256' => $preFingerprint,
                'pre_target_coverage' => $preCoverage,
                'post_target_coverage' => $preCoverage,
            ];
        }

        $failureStage = null;
        $errorCategory = null;
        $buildMs = 0.0;
        $writes = 0;
        try {
            $closures = $precompute([$slug]);
            $closure = $closures[$slug] ?? null;
            if (! is_array($closure)) {
                $failureStage = 'precompute_conversion_closure';
                $errorCategory = 'unexpected';
            } else {
                $result = $warmer($slug, $locale, $closure);
                $buildMs = self::safeMilliseconds($result['build_ms'] ?? 0);
                if (($result['status'] ?? null) === 'cached') {
                    $writes = 1;
                } else {
                    $failureStage = self::safeFailureStage($result['failure_stage'] ?? null);
                    $errorCategory = self::safeErrorCategory($result['error_category'] ?? null);
                }
            }
        } catch (Throwable $throwable) {
            $failureStage = 'precompute_conversion_closure';
            $errorCategory = self::safeThrowableCategory($throwable);
        }

        try {
            $postInspection = $postInspect();
            self::assertInspectionBoundary($postInspection, $expectedTargets);
        } catch (Throwable) {
            $postInspection = $inspection;
            $failureStage = 'post_batch_coverage';
            $errorCategory = 'unexpected';
            $writes = 0;
        }
        $postFingerprint = self::coverageFingerprint($postInspection);
        $preMissing = (int) ($inspection['report']['missing_pointer_count'] ?? -1);
        $postMissing = (int) ($postInspection['report']['missing_pointer_count'] ?? -1);
        $postTarget = self::findTarget($postInspection, $slug, $locale);
        if (
            $writes === 1
            && (
                ! is_array($postTarget)
                || ($postTarget['repairable'] ?? true) !== false
                || ($postTarget['classification'] ?? null) !== 'ready_active'
                || $postMissing !== $preMissing - 1
            )
        ) {
            $failureStage = 'post_batch_coverage';
            $errorCategory = 'unexpected';
        } elseif ($writes === 0 && $postMissing !== $preMissing) {
            $failureStage = 'post_batch_coverage';
            $errorCategory = 'unexpected';
        }

        $failed = $failureStage !== null || $errorCategory !== null;

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'diagnose_target',
            'status' => $failed ? 'failed' : 'completed',
            'candidate_revision' => $candidateSha,
            'target_index_sha256' => $targetHash,
            'diagnostic_write' => true,
            'target_classification' => $target['classification'],
            'offline_build_budget_ms' => self::OFFLINE_BUILD_BUDGET_MS,
            'cache_write_count' => $writes,
            'owned_cache_write_count' => $writes,
            'concurrent_coverage_gain_count' => 0,
            'failure_count' => $failed ? 1 : 0,
            'failure_stage' => $failed ? self::safeFailureStage($failureStage) : null,
            'error_category' => $failed ? self::safeErrorCategory($errorCategory) : null,
            'build_ms' => $buildMs,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
            'pre_coverage_fingerprint_sha256' => $preFingerprint,
            'post_coverage_fingerprint_sha256' => $postFingerprint,
            'pre_target_coverage' => $preCoverage,
            'post_target_coverage' => self::safeCoverage($postInspection['report'] ?? []),
        ];
    }

    /** @param array<string, mixed> $inspection */
    public static function coverageFingerprint(array $inspection): string
    {
        $rows = [];
        foreach (array_values($inspection['rows'] ?? []) as $index => $row) {
            if (! is_array($row)) {
                self::fail('INVALID_TARGET_ROW');
            }
            $rows[] = [
                'index' => $index,
                'slug' => (string) ($row['slug'] ?? ''),
                'locale' => (string) ($row['locale'] ?? ''),
                'classification' => (string) ($row['classification'] ?? ''),
                'repairable' => ($row['repairable'] ?? false) === true,
            ];
        }

        return hash('sha256', (string) json_encode([
            'rows' => $rows,
            'coverage' => self::safeCoverage($inspection['report'] ?? []),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $inspection */
    public static function coverageState(array $inspection): string
    {
        $codes = [
            'missing_pointer' => 'M',
            'ready_active' => 'A',
            'ready_lkg' => 'L',
            'legacy_migratable' => 'G',
        ];
        $state = '';
        foreach (array_values($inspection['rows'] ?? []) as $row) {
            if (! is_array($row) || ! isset($codes[$row['classification'] ?? ''])) {
                self::fail('INVALID_COVERAGE_STATE');
            }
            $state .= $codes[$row['classification']];
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    public static function assertAuthorizedPreflightTransition(
        array $inspection,
        string $authorizedState,
        string $authorizedFingerprint,
        int $expectedTargets,
        int $authorizedMissing,
    ): int {
        self::assertInspectionBoundary($inspection, $expectedTargets);
        if (
            strlen($authorizedState) !== $expectedTargets
            || preg_match('/^[MALG]+$/D', $authorizedState) !== 1
        ) {
            self::fail('INVALID_AUTHORIZED_COVERAGE_STATE');
        }
        if (substr_count($authorizedState, 'M') !== $authorizedMissing) {
            self::fail('AUTHORIZED_MISSING_COUNT_DRIFT');
        }

        $classifications = [
            'M' => 'missing_pointer',
            'A' => 'ready_active',
            'L' => 'ready_lkg',
            'G' => 'legacy_migratable',
        ];
        $authorizedRows = [];
        foreach (array_values($inspection['rows']) as $index => $row) {
            $classification = $classifications[$authorizedState[$index]];
            $authorizedRows[] = [
                'slug' => $row['slug'],
                'locale' => $row['locale'],
                'classification' => $classification,
                'repairable' => $classification === 'missing_pointer',
            ];
        }
        $authorizedCovered = $expectedTargets - $authorizedMissing;
        $authorizedInspection = [
            'rows' => $authorizedRows,
            'report' => [
                'contract_version' => 'career.job_detail_cache_coverage.v1',
                'expected_target_count' => $expectedTargets,
                'eligible_target_count' => $expectedTargets,
                'covered_target_count' => $authorizedCovered,
                'missing_pointer_count' => $authorizedMissing,
                'missing_payload_count' => 0,
                'broken_count' => 0,
                'excluded_count' => 0,
                'coverage_ratio' => $expectedTargets === 0
                    ? 1.0
                    : round($authorizedCovered / $expectedTargets, 6),
                'status' => $authorizedMissing === 0 ? 'ready' : 'incomplete',
            ],
        ];
        if (! hash_equals($authorizedFingerprint, self::coverageFingerprint($authorizedInspection))) {
            self::fail('AUTHORIZED_COVERAGE_FINGERPRINT_DRIFT');
        }

        foreach ($authorizedRows as $index => $authorizedRow) {
            $currentClassification = (string) $inspection['rows'][$index]['classification'];
            if (
                self::classificationIsCovered((string) $authorizedRow['classification'])
                && $currentClassification !== $authorizedRow['classification']
            ) {
                self::fail('AUTHORIZED_COVERED_CLASSIFICATION_DRIFT');
            }
        }
        $transition = self::assertMonotonicCoverageTransition(
            $authorizedInspection,
            $inspection,
            [],
            $expectedTargets,
        );
        $gain = $transition['concurrent_coverage_gain_count'];
        $currentMissing = (int) $inspection['report']['missing_pointer_count'];
        if ($gain !== $authorizedMissing - $currentMissing) {
            self::fail('AUTHORIZED_COVERAGE_GAIN_DRIFT');
        }

        return $gain;
    }

    public static function targetIndexHash(
        string $candidateSha,
        int $rowIndex,
        string $locale,
        string $slug,
    ): string {
        return hash('sha256', (string) json_encode([
            $candidateSha,
            sprintf('%06d', $rowIndex),
            $locale,
            $slug,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function assertReadOnlySql(string $query): void
    {
        $normalized = ltrim($query);
        if (preg_match('/^(?:select|show|describe|explain|pragma)\b/i', $normalized) !== 1) {
            self::fail('DATABASE_WRITE_BLOCKED');
        }
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    public static function assertInspection(
        array $inspection,
        int $expectedTargets,
        int $expectedMissing,
        bool $requireExactMissing,
    ): void {
        self::assertInspectionBoundary($inspection, $expectedTargets);
        $report = $inspection['report'];
        $missing = (int) ($report['missing_pointer_count'] ?? -1);
        if (($requireExactMissing && $missing !== $expectedMissing) || (! $requireExactMissing && $missing > $expectedMissing)) {
            self::fail('MISSING_POINTER_COUNT_DRIFT');
        }
        if (self::repairableCount($inspection) !== $missing) {
            self::fail('REPAIRABLE_TARGET_COUNT_DRIFT');
        }
    }

    /**
     * @param  array<string, string>  $environment
     */
    public static function assertOnlyAllowlistedInputs(array $environment): void
    {
        if (array_diff(array_keys($environment), self::INPUT_NAMES) !== []) {
            self::fail('UNEXPECTED_INPUT');
        }
    }

    /**
     * @param  array<string, string>  $environment
     */
    public static function assertModeInputs(array $environment, string $mode): void
    {
        $batchInputs = ['FM_CAREER_BATCH_OFFSET', 'FM_CAREER_BATCH_SIZE'];
        $diagnosticInputs = ['FM_CAREER_TARGET_SLUG', 'FM_CAREER_TARGET_LOCALE', 'FM_CAREER_DIAGNOSTIC_WRITE'];
        if ($mode !== 'batch' && array_intersect($batchInputs, array_keys($environment)) !== []) {
            self::fail('MODE_INPUT_CONFLICT');
        }
        if ($mode !== 'diagnose_target' && array_intersect($diagnosticInputs, array_keys($environment)) !== []) {
            self::fail('MODE_INPUT_CONFLICT');
        }
        if ($mode === 'batch' && array_diff($batchInputs, array_keys($environment)) !== []) {
            self::fail('MISSING_REQUIRED_INPUT');
        }
        if ($mode === 'diagnose_target' && array_diff($diagnosticInputs, array_keys($environment)) !== []) {
            self::fail('MISSING_REQUIRED_INPUT');
        }
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private static function assertInspectionBoundary(array $inspection, int $expectedTargets): void
    {
        $report = $inspection['report'] ?? null;
        $rows = $inspection['rows'] ?? null;
        if (! is_array($report) || ! is_array($rows)) {
            self::fail('INVALID_COVERAGE_CONTRACT');
        }
        if (($report['contract_version'] ?? null) !== 'career.job_detail_cache_coverage.v1') {
            self::fail('COVERAGE_CONTRACT_DRIFT');
        }
        if (
            (int) ($report['expected_target_count'] ?? -1) !== $expectedTargets
            || (int) ($report['eligible_target_count'] ?? -1) !== $expectedTargets
            || count($rows) !== $expectedTargets
        ) {
            self::fail('TARGET_COUNT_DRIFT');
        }
        if (
            (int) ($report['missing_payload_count'] ?? -1) !== 0
            || (int) ($report['broken_count'] ?? -1) !== 0
            || (int) ($report['excluded_count'] ?? -1) !== 0
        ) {
            self::fail('COVERAGE_BOUNDARY_FAILED');
        }
        foreach ($rows as $row) {
            if (
                ! is_array($row)
                || ! is_string($row['slug'] ?? null)
                || ! in_array($row['locale'] ?? null, ['en', 'zh-CN'], true)
                || ! is_string($row['classification'] ?? null)
                || ! is_bool($row['repairable'] ?? null)
            ) {
                self::fail('INVALID_TARGET_ROW');
            }
            $classification = (string) $row['classification'];
            if (
                $classification !== 'missing_pointer'
                && ! self::classificationIsCovered($classification)
            ) {
                self::fail('COVERAGE_BOUNDARY_FAILED');
            }
            if (($classification === 'missing_pointer') !== $row['repairable']) {
                self::fail('REPAIRABLE_TARGET_COUNT_DRIFT');
            }
        }
    }

    private static function assertServiceSignatures(
        string $coverageClass,
        string $cacheClass,
        string $conversionClass,
    ): void {
        if (! class_exists($coverageClass) || ! class_exists($cacheClass) || ! class_exists($conversionClass)) {
            self::fail('CANDIDATE_SERVICE_MISSING');
        }

        $inspect = new ReflectionMethod($coverageClass, 'inspect');
        $warm = new ReflectionMethod($cacheClass, 'warmJobDetailPayloadForOfflineBootstrap');
        $batchConversion = new ReflectionMethod($conversionClass, 'buildForSubjectSlugs');
        $cacheReflection = new ReflectionClass($cacheClass);
        if (! $inspect->isPublic() || $inspect->getNumberOfParameters() !== 2) {
            self::fail('COVERAGE_SERVICE_INCOMPATIBLE');
        }
        if (! $warm->isPublic() || $warm->getNumberOfParameters() !== 3) {
            self::fail('CACHE_SERVICE_INCOMPATIBLE');
        }
        if (! $batchConversion->isPublic() || $batchConversion->getNumberOfParameters() !== 1) {
            self::fail('CONVERSION_SERVICE_INCOMPATIBLE');
        }
        if (
            ! $cacheReflection->hasConstant('JOB_DETAIL_OFFLINE_BOOTSTRAP_BUILD_BUDGET_MS')
            || $cacheReflection->getConstant('JOB_DETAIL_OFFLINE_BOOTSTRAP_BUILD_BUDGET_MS')
                !== self::OFFLINE_BUILD_BUDGET_MS
        ) {
            self::fail('OFFLINE_BUILD_BUDGET_INCOMPATIBLE');
        }

        $inspectParameters = $inspect->getParameters();
        $warmParameters = $warm->getParameters();
        $batchParameters = $batchConversion->getParameters();
        if (
            (string) $inspectParameters[0]->getType() !== 'array'
            || (string) $inspectParameters[1]->getType() !== 'int'
            || (string) $warmParameters[0]->getType() !== 'string'
            || (string) $warmParameters[1]->getType() !== 'string'
            || (string) $warmParameters[2]->getType() !== 'array'
            || (string) $batchParameters[0]->getType() !== 'array'
        ) {
            self::fail('CANDIDATE_SERVICE_INCOMPATIBLE');
        }
    }

    private static function installDatabaseGuard(object $app): void
    {
        $database = $app->make('db');
        $connection = $database->connection();
        if (! method_exists($connection, 'beforeExecuting')) {
            self::fail('DATABASE_GUARD_UNAVAILABLE');
        }
        $connection->beforeExecuting(
            static function (string $query): void {
                self::assertReadOnlySql($query);
            },
        );
    }

    private static function candidateBackend(string $managedRoot, string $release, string $expectedSha): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $release) !== 1) {
            self::fail('INVALID_CANDIDATE_RELEASE');
        }

        $root = realpath($managedRoot);
        $candidate = realpath(rtrim($managedRoot, '/').'/'.$release);
        if ($root === false || $candidate === false || ! is_dir($candidate)) {
            self::fail('CANDIDATE_PATH_MISSING');
        }
        $root = rtrim($root, '/');
        if (! str_starts_with($candidate.'/', $root.'/') || dirname($candidate) !== $root) {
            self::fail('CANDIDATE_PATH_OUTSIDE_MANAGED_ROOT');
        }

        $revision = @file_get_contents($candidate.'/REVISION');
        if (! is_string($revision) || trim($revision) !== $expectedSha) {
            self::fail('CANDIDATE_REVISION_DRIFT');
        }

        $backend = realpath($candidate.'/backend');
        if ($backend === false || ! str_starts_with($backend.'/', $candidate.'/')) {
            self::fail('CANDIDATE_BACKEND_INVALID');
        }

        return $backend;
    }

    /**
     * @param  array<string, mixed>  $inspection
     */
    private static function repairableCount(array $inspection): int
    {
        return count(array_filter(
            $inspection['rows'] ?? [],
            static fn (mixed $row): bool => is_array($row) && ($row['repairable'] ?? false) === true,
        ));
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @return array{index: int, classification: string, repairable: bool}
     */
    private static function diagnosticTarget(array $inspection, string $slug, string $locale, int $expectedTargets): array
    {
        self::assertInspectionBoundary($inspection, $expectedTargets);
        if (
            preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
            || ! in_array($locale, ['en', 'zh-CN'], true)
        ) {
            self::fail('INVALID_DIAGNOSTIC_TARGET');
        }
        $matches = [];
        foreach (array_values($inspection['rows'] ?? []) as $index => $row) {
            if (
                is_array($row)
                && ($row['slug'] ?? null) === $slug
                && ($row['locale'] ?? null) === $locale
            ) {
                $matches[] = ['index' => $index, ...$row];
            }
        }
        if (count($matches) > 1) {
            self::fail('DUPLICATE_DIAGNOSTIC_TARGET');
        }
        $target = $matches[0] ?? null;
        if (! is_array($target)) {
            self::fail('DIAGNOSTIC_TARGET_NOT_FOUND');
        }
        if (($target['repairable'] ?? false) !== true || ($target['classification'] ?? null) !== 'missing_pointer') {
            self::fail('DIAGNOSTIC_TARGET_NOT_REPAIRABLE');
        }

        return [
            'index' => (int) $target['index'],
            'classification' => (string) $target['classification'],
            'repairable' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>|null
     */
    private static function findTarget(array $inspection, string $slug, string $locale): ?array
    {
        $matches = array_values(array_filter(
            $inspection['rows'] ?? [],
            static fn (mixed $row): bool => is_array($row)
                && ($row['slug'] ?? null) === $slug
                && ($row['locale'] ?? null) === $locale,
        ));
        if (count($matches) > 1) {
            self::fail('DUPLICATE_DIAGNOSTIC_TARGET');
        }

        return $matches[0] ?? null;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, int|float|string>
     */
    private static function safeCoverage(array $report): array
    {
        return [
            'status' => (string) ($report['status'] ?? 'invalid'),
            'expected_target_count' => (int) ($report['expected_target_count'] ?? 0),
            'eligible_target_count' => (int) ($report['eligible_target_count'] ?? 0),
            'covered_target_count' => (int) ($report['covered_target_count'] ?? 0),
            'missing_pointer_count' => (int) ($report['missing_pointer_count'] ?? 0),
            'missing_payload_count' => (int) ($report['missing_payload_count'] ?? 0),
            'broken_count' => (int) ($report['broken_count'] ?? 0),
            'excluded_count' => (int) ($report['excluded_count'] ?? 0),
            'coverage_ratio' => (float) ($report['coverage_ratio'] ?? 0),
        ];
    }

    private static function classificationIsCovered(string $classification): bool
    {
        return in_array($classification, self::COVERED_CLASSIFICATIONS, true);
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  array<string, mixed>  $postInspection
     * @return array<string, mixed>
     */
    private static function failedBatchReceipt(
        string $candidateSha,
        int $offset,
        int $inspectedTargets,
        int $repairableTargets,
        int $writes,
        int $concurrentCoverageGain,
        string $failureStage,
        string $errorCategory,
        float $buildMs,
        float $batchBuildMsTotal,
        float $batchBuildMsMax,
        int $attemptCount,
        int $retryCount,
        ?string $failedTargetHash,
        string $preFingerprint,
        string $postFingerprint,
        array $inspection,
        array $postInspection,
    ): array {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'batch',
            'status' => 'failed',
            'candidate_revision' => $candidateSha,
            'batch_offset' => $offset,
            'batch_size' => self::BATCH_SIZE,
            'offline_build_budget_ms' => self::OFFLINE_BUILD_BUDGET_MS,
            'retry_limit' => self::RETRY_LIMIT,
            'inspected_target_count' => $inspectedTargets,
            'repairable_target_count' => $repairableTargets,
            'cache_write_count' => $writes,
            'owned_cache_write_count' => $writes,
            'concurrent_coverage_gain_count' => $concurrentCoverageGain,
            'failure_count' => 1,
            'failure_stage' => self::safeFailureStage($failureStage),
            'error_category' => self::safeErrorCategory($errorCategory),
            'build_ms' => round($buildMs, 3),
            'batch_build_ms_total' => round($batchBuildMsTotal, 3),
            'batch_build_ms_max' => round($batchBuildMsMax, 3),
            'attempt_count' => $attemptCount,
            'retry_count' => $retryCount,
            'failed_target_index_sha256' => $failedTargetHash,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
            'pre_coverage_fingerprint_sha256' => $preFingerprint,
            'post_coverage_fingerprint_sha256' => $postFingerprint,
            'pre_batch_coverage' => self::safeCoverage($inspection['report'] ?? []),
            'post_batch_coverage' => self::safeCoverage($postInspection['report'] ?? []),
        ];
    }

    private static function safeFailureStage(mixed $value): string
    {
        return is_string($value) && in_array($value, self::SAFE_FAILURE_STAGES, true)
            ? $value
            : 'build_detail_payload';
    }

    private static function safeErrorCategory(mixed $value): string
    {
        return is_string($value) && in_array($value, self::SAFE_ERROR_CATEGORIES, true)
            ? $value
            : 'unexpected';
    }

    private static function safeThrowableCategory(Throwable $throwable): string
    {
        for ($candidate = $throwable; $candidate instanceof Throwable; $candidate = $candidate->getPrevious()) {
            $sqlState = (string) $candidate->getCode();
            $driverCode = null;
            if ($candidate instanceof \Illuminate\Database\QueryException) {
                $sqlState = (string) ($candidate->errorInfo[0] ?? $sqlState);
                $driverCode = $candidate->errorInfo[1] ?? null;
            } elseif ($candidate instanceof \PDOException) {
                $driverCode = $candidate->errorInfo[1] ?? null;
            }
            if (
                str_starts_with($sqlState, '08')
                || $sqlState === '40001'
                || in_array((int) $driverCode, [1205, 1213, 2006, 2013], true)
            ) {
                return 'database_transient_read';
            }
            if ($candidate instanceof \Illuminate\Database\QueryException || $candidate instanceof \PDOException) {
                return 'database_permanent_read';
            }
        }

        return 'unexpected';
    }

    private static function safeMilliseconds(mixed $value): float
    {
        return is_numeric($value) && (float) $value >= 0
            ? round((float) $value, 3)
            : 0.0;
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $environment = [];
        foreach (self::INPUT_NAMES as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }

    /**
     * @param  array<string, string>  $environment
     */
    private static function required(array $environment, string $name): string
    {
        $value = $environment[$name] ?? '';
        if ($value === '') {
            self::fail('MISSING_REQUIRED_INPUT');
        }

        return $value;
    }

    private static function sha(string $value): string
    {
        if (preg_match('/^[0-9a-f]{40}$/D', $value) !== 1) {
            self::fail('INVALID_CANDIDATE_REVISION');
        }

        return $value;
    }

    private static function integer(string $value, int $minimum, int $maximum, string $errorCode): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            self::fail($errorCode);
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            self::fail($errorCode);
        }

        return $integer;
    }

    private static function boolean(string $value): bool
    {
        return match ($value) {
            '0' => false,
            '1' => true,
            default => self::fail('INVALID_BOOLEAN_INPUT'),
        };
    }

    /** @return array<string, float|int|string|null> */
    public static function failureReceipt(
        string $errorCode,
        ?string $failureStage = null,
        ?string $errorCategory = null,
        int $attemptCount = 0,
        int $retryCount = 0,
        ?int $batchOffset = null,
    ): array {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'failed',
            'error_code' => $errorCode,
            'batch_offset' => $batchOffset,
            'cache_write_count' => 0,
            'owned_cache_write_count' => 0,
            'concurrent_coverage_gain_count' => 0,
            'failure_count' => 1,
            'failure_stage' => $failureStage === null ? null : self::safeFailureStage($failureStage),
            'error_category' => $errorCategory === null ? null : self::safeErrorCategory($errorCategory),
            'build_ms' => 0.0,
            'batch_build_ms_total' => 0.0,
            'batch_build_ms_max' => 0.0,
            'attempt_count' => max(0, $attemptCount),
            'retry_count' => max(0, min($retryCount, $attemptCount)),
            'failed_target_index_sha256' => null,
            'pre_coverage_fingerprint_sha256' => null,
            'post_coverage_fingerprint_sha256' => null,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
        ];
    }

    /**
     * @param  array<string, string>  $environment
     */
    private static function safeBatchOffset(array $environment): ?int
    {
        $value = $environment['FM_CAREER_BATCH_OFFSET'] ?? '';
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $offset = (int) $value;

        return $offset <= 100000 && $offset % self::BATCH_SIZE === 0
            ? $offset
            : null;
    }

    /** @param array<string, mixed> $receipt */
    private static function emit(array $receipt): void
    {
        fwrite(STDOUT, (string) json_encode(
            $receipt,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ).PHP_EOL);
    }

    private static function fail(string $safeCode): never
    {
        throw new CareerCandidateExactCacheBootstrapFailure($safeCode);
    }
}

if (getenv('FM_CAREER_RUNNER_EXECUTE') === '1') {
    exit(CareerCandidateExactCacheBootstrapRunner::main());
}
