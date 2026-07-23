<?php

declare(strict_types=1);

namespace FermatMind\Deploy;

use App\Services\Career\CareerJobDetailWarmFailure;
use Closure;
use Illuminate\Contracts\Console\Kernel;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class CareerCandidateExactCacheBootstrapFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}

final class CareerCandidateExactCacheBootstrapRunner
{
    public const CONTRACT_VERSION = 'career.candidate_exact_cache_bootstrap.v1';

    public const BATCH_SIZE = 250;

    /** @var list<int> */
    public const BATCH_OFFSETS = [0, 250, 500, 750, 1000, 1250, 1500, 1750, 2000];

    /** @var list<string> */
    private const INPUT_NAMES = [
        'FM_CAREER_MODE',
        'FM_CAREER_MANAGED_RELEASES_ROOT',
        'FM_CAREER_CANDIDATE_RELEASE',
        'FM_CAREER_CANDIDATE_SHA',
        'FM_CAREER_EXPECTED_TARGETS',
        'FM_CAREER_EXPECTED_MISSING',
        'FM_CAREER_BATCH_OFFSET',
        'FM_CAREER_BATCH_SIZE',
        'FM_CAREER_TARGET_SLUG',
        'FM_CAREER_TARGET_LOCALE',
        'FM_CAREER_DIAGNOSTIC_WRITE',
    ];

    public static function main(): int
    {
        try {
            $receipt = self::execute(self::environment());
            self::emit($receipt);

            return (int) ($receipt['failure_count'] ?? 0) === 0 ? 0 : 1;
        } catch (CareerCandidateExactCacheBootstrapFailure $failure) {
            self::emit(self::failureReceipt($failure->safeCode));

            return 1;
        } catch (Throwable) {
            self::emit(self::failureReceipt('UNEXPECTED_RUNNER_FAILURE'));

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
        $expectedMissing = self::integer(
            self::required($environment, 'FM_CAREER_EXPECTED_MISSING'),
            0,
            $expectedTargets,
            'INVALID_EXPECTED_MISSING',
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

        require_once $autoload;
        $app = require $bootstrap;
        if (! is_object($app) || ! method_exists($app, 'make')) {
            self::fail('CANDIDATE_BOOTSTRAP_INVALID');
        }

        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        if (! method_exists($app, 'environment') || ! $app->environment('production')) {
            self::fail('NON_PRODUCTION_RUNTIME');
        }

        $coverageClass = 'App\\Services\\Career\\CareerJobDetailCacheCoverageService';
        $cacheClass = 'App\\Services\\Career\\PublicCareerAuthorityResponseCache';
        self::assertServiceSignatures($coverageClass, $cacheClass);
        if ($mode === 'diagnose_target') {
            self::assertDiagnosticFailureContract();
        }
        self::installDatabaseGuard($app);

        $coverage = $app->make($coverageClass);
        $cache = $app->make($cacheClass);
        $inspect = static fn (): array => $coverage->inspect(['en', 'zh-CN'], 0);
        $inspection = $inspect();
        self::assertInspection($inspection, $expectedTargets, $expectedMissing, true);

        if ($mode === 'preflight') {
            return self::preflightReceipt($expectedCandidateSha, $inspection);
        }

        if ($mode === 'diagnose_target') {
            return self::diagnosticReceipt(
                $expectedCandidateSha,
                $inspection,
                self::required($environment, 'FM_CAREER_TARGET_SLUG'),
                self::required($environment, 'FM_CAREER_TARGET_LOCALE'),
                self::boolean(self::required($environment, 'FM_CAREER_DIAGNOSTIC_WRITE')),
                static fn (string $slug, string $locale): array => $cache->warmJobDetailPayload($slug, $locale, false),
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
        if (! in_array($offset, self::BATCH_OFFSETS, true)) {
            self::fail('INVALID_BATCH_OFFSET');
        }
        $batchSize = self::integer(
            self::required($environment, 'FM_CAREER_BATCH_SIZE'),
            self::BATCH_SIZE,
            self::BATCH_SIZE,
            'INVALID_BATCH_SIZE',
        );

        return self::batchReceipt(
            $expectedCandidateSha,
            $inspection,
            $offset,
            $batchSize,
            static fn (string $slug, string $locale): array => $cache->warmJobDetailPayload($slug, $locale, false),
            $inspect,
            $expectedTargets,
        );
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @return array<string, mixed>
     */
    public static function preflightReceipt(string $candidateSha, array $inspection): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'preflight',
            'status' => 'ready',
            'candidate_revision' => $candidateSha,
            'batch_offset' => null,
            'batch_size' => null,
            'inspected_target_count' => (int) ($inspection['report']['expected_target_count'] ?? 0),
            'repairable_target_count' => self::repairableCount($inspection),
            'cache_write_count' => 0,
            'failure_count' => 0,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
            'coverage' => self::safeCoverage($inspection['report'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  Closure(string, string): array<string, mixed>  $warmer
     * @param  Closure(): array<string, mixed>  $postInspect
     * @return array<string, mixed>
     */
    public static function batchReceipt(
        string $candidateSha,
        array $inspection,
        int $offset,
        int $batchSize,
        Closure $warmer,
        Closure $postInspect,
        int $expectedTargets,
    ): array {
        if (! in_array($offset, self::BATCH_OFFSETS, true) || $batchSize !== self::BATCH_SIZE) {
            self::fail('INVALID_BATCH_BOUNDARY');
        }

        $rows = $inspection['rows'] ?? null;
        if (! is_array($rows) || count($rows) !== $expectedTargets) {
            self::fail('TARGET_ROWS_DRIFT');
        }

        $batch = array_slice($rows, $offset, $batchSize);
        $repairable = 0;
        $writes = 0;
        $failures = 0;
        $errorCode = null;

        foreach ($batch as $row) {
            if (! is_array($row) || ! is_bool($row['repairable'] ?? null)) {
                self::fail('INVALID_TARGET_ROW');
            }
            if ($row['repairable'] !== true) {
                continue;
            }

            $repairable++;
            $slug = $row['slug'] ?? null;
            $locale = $row['locale'] ?? null;
            if (! is_string($slug) || trim($slug) === '' || ! in_array($locale, ['en', 'zh-CN'], true)) {
                self::fail('INVALID_TARGET_ROW');
            }

            try {
                $result = $warmer($slug, $locale);
                if (($result['status'] ?? null) !== 'cached') {
                    $failures = 1;
                    $errorCode = 'TARGET_NOT_CACHED';
                    break;
                }
                $writes++;
            } catch (Throwable) {
                $failures = 1;
                $errorCode = 'TARGET_WARM_FAILED';
                break;
            }
        }

        $postInspection = $postInspect();
        self::assertInspectionBoundary($postInspection, $expectedTargets);

        return [
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'batch',
            'status' => $failures === 0 ? 'completed' : 'failed',
            'candidate_revision' => $candidateSha,
            'batch_offset' => $offset,
            'batch_size' => $batchSize,
            'inspected_target_count' => count($batch),
            'repairable_target_count' => $repairable,
            'cache_write_count' => $writes,
            'failure_count' => $failures,
            'error_code' => $errorCode,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
            'pre_batch_coverage' => self::safeCoverage($inspection['report'] ?? []),
            'post_batch_coverage' => self::safeCoverage($postInspection['report'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $inspection
     * @param  Closure(string, string): array<string, mixed>  $warmer
     * @param  Closure(): array<string, mixed>  $postInspect
     * @return array<string, mixed>
     */
    public static function diagnosticReceipt(
        string $candidateSha,
        array $inspection,
        string $slug,
        string $locale,
        bool $executeWrite,
        Closure $warmer,
        Closure $postInspect,
        int $expectedTargets,
    ): array {
        $target = self::diagnosticTarget($inspection, $slug, $locale, $expectedTargets);
        $preCoverage = self::safeCoverage($inspection['report'] ?? []);
        if (! $executeWrite) {
            return [
                'contract_version' => self::CONTRACT_VERSION,
                'mode' => 'diagnose_target',
                'status' => 'ready',
                'candidate_revision' => $candidateSha,
                'target' => ['slug' => $slug, 'locale' => $locale],
                'diagnostic_write' => false,
                'target_classification' => $target['classification'],
                'cache_write_count' => 0,
                'failure_count' => 0,
                'queue_dispatch_count' => 0,
                'database_write_count' => 0,
                'pre_target_coverage' => $preCoverage,
                'post_target_coverage' => $preCoverage,
            ];
        }

        $failureEvidence = null;
        $status = 'failed';
        $writes = 0;
        try {
            $result = $warmer($slug, $locale);
            if (($result['status'] ?? null) !== 'cached') {
                $failureEvidence = [
                    'failure_stage' => 'result_validation',
                    'safe_code' => 'CAREER_DETAIL_TARGET_NOT_CACHED',
                    'cause_class' => 'warm_result',
                    'build_ms' => self::safeTiming($result['build_ms'] ?? 0),
                    'publish_ms' => 0.0,
                ];
            } else {
                $status = 'completed';
                $writes = 1;
            }
        } catch (CareerJobDetailWarmFailure $failure) {
            $failureEvidence = $failure->safeEvidence();
        } catch (Throwable $failure) {
            $failureEvidence = [
                'failure_stage' => 'unrecognized_exception',
                'safe_code' => 'CAREER_DETAIL_UNRECOGNIZED_EXCEPTION',
                'cause_class' => CareerJobDetailWarmFailure::safeCauseClass($failure),
                'build_ms' => 0.0,
                'publish_ms' => 0.0,
            ];
        }

        $postInspection = $postInspect();
        self::assertInspectionBoundary($postInspection, $expectedTargets);
        if ($status === 'completed') {
            $postTarget = self::findTarget($postInspection, $slug, $locale);
            $preMissing = (int) ($inspection['report']['missing_pointer_count'] ?? -1);
            $postMissing = (int) ($postInspection['report']['missing_pointer_count'] ?? -1);
            if (
                ! is_array($postTarget)
                || ($postTarget['repairable'] ?? true) !== false
                || ($postTarget['classification'] ?? null) !== 'ready_active'
                || $postMissing !== $preMissing - 1
            ) {
                self::fail('DIAGNOSTIC_READBACK_FAILED');
            }
        }

        return array_filter([
            'contract_version' => self::CONTRACT_VERSION,
            'mode' => 'diagnose_target',
            'status' => $status,
            'candidate_revision' => $candidateSha,
            'target' => ['slug' => $slug, 'locale' => $locale],
            'diagnostic_write' => true,
            'target_classification' => $target['classification'],
            'cache_write_count' => $writes,
            'failure_count' => $status === 'completed' ? 0 : 1,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
            'failure_evidence' => $failureEvidence,
            'pre_target_coverage' => $preCoverage,
            'post_target_coverage' => self::safeCoverage($postInspection['report'] ?? []),
        ], static fn (mixed $value): bool => $value !== null);
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
        }
    }

    private static function assertServiceSignatures(string $coverageClass, string $cacheClass): void
    {
        if (! class_exists($coverageClass) || ! class_exists($cacheClass)) {
            self::fail('CANDIDATE_SERVICE_MISSING');
        }

        $inspect = new ReflectionMethod($coverageClass, 'inspect');
        $warm = new ReflectionMethod($cacheClass, 'warmJobDetailPayload');
        if (! $inspect->isPublic() || $inspect->getNumberOfParameters() !== 2) {
            self::fail('COVERAGE_SERVICE_INCOMPATIBLE');
        }
        if (! $warm->isPublic() || $warm->getNumberOfParameters() !== 3) {
            self::fail('CACHE_SERVICE_INCOMPATIBLE');
        }

        $inspectParameters = $inspect->getParameters();
        $warmParameters = $warm->getParameters();
        if (
            (string) $inspectParameters[0]->getType() !== 'array'
            || (string) $inspectParameters[1]->getType() !== 'int'
            || (string) $warmParameters[0]->getType() !== 'string'
            || (string) $warmParameters[1]->getType() !== 'string'
            || (string) $warmParameters[2]->getType() !== 'bool'
        ) {
            self::fail('CANDIDATE_SERVICE_INCOMPATIBLE');
        }
    }

    public static function assertDiagnosticFailureContract(): void
    {
        if (! class_exists(CareerJobDetailWarmFailure::class)) {
            self::fail('CANDIDATE_DIAGNOSTIC_CONTRACT_MISSING');
        }
        foreach (['safeEvidence', 'safeCauseClass'] as $method) {
            if (! method_exists(CareerJobDetailWarmFailure::class, $method)) {
                self::fail('CANDIDATE_DIAGNOSTIC_CONTRACT_INCOMPATIBLE');
            }
            $reflection = new ReflectionMethod(CareerJobDetailWarmFailure::class, $method);
            if (! $reflection->isPublic()) {
                self::fail('CANDIDATE_DIAGNOSTIC_CONTRACT_INCOMPATIBLE');
            }
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
     * @return array{slug: string, locale: string, classification: string, repairable: bool}
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
        $target = self::findTarget($inspection, $slug, $locale);
        if (! is_array($target)) {
            self::fail('DIAGNOSTIC_TARGET_NOT_FOUND');
        }
        if (($target['repairable'] ?? false) !== true || ($target['classification'] ?? null) !== 'missing_pointer') {
            self::fail('DIAGNOSTIC_TARGET_NOT_REPAIRABLE');
        }

        return $target;
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
            default => self::fail('INVALID_DIAGNOSTIC_WRITE'),
        };
    }

    private static function safeTiming(mixed $value): float
    {
        if (! is_int($value) && ! is_float($value)) {
            return 0.0;
        }

        return round(max(0.0, (float) $value), 3);
    }

    /** @return array<string, int|string> */
    private static function failureReceipt(string $errorCode): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => 'failed',
            'error_code' => $errorCode,
            'cache_write_count' => 0,
            'failure_count' => 1,
            'queue_dispatch_count' => 0,
            'database_write_count' => 0,
        ];
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
