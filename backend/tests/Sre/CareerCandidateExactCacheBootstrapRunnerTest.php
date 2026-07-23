<?php

declare(strict_types=1);

namespace Tests\Sre;

use FermatMind\Deploy\CareerCandidateExactCacheBootstrapFailure;
use FermatMind\Deploy\CareerCandidateExactCacheBootstrapRunner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/deploy/career_candidate_exact_cache_bootstrap.php';

final class CareerCandidateExactCacheBootstrapRunnerTest extends TestCase
{
    #[Test]
    public function preflight_receipt_is_v2_read_only_and_fingerprint_bound(): void
    {
        $inspection = $this->inspection([
            $this->row('ready', 'en', false, 'ready_active'),
            $this->row('missing', 'zh-CN', true, 'missing_pointer'),
        ]);
        $receipt = CareerCandidateExactCacheBootstrapRunner::preflightReceipt(
            str_repeat('a', 40),
            $inspection,
        );

        $this->assertSame('career.candidate_exact_cache_bootstrap.v2', $receipt['contract_version']);
        $this->assertSame(50, $receipt['batch_size']);
        $this->assertSame(5000, $receipt['offline_build_budget_ms']);
        $this->assertSame(1, $receipt['retry_limit']);
        $this->assertSame(0, $receipt['cache_write_count']);
        $this->assertSame(0, $receipt['queue_dispatch_count']);
        $this->assertSame(0, $receipt['database_write_count']);
        $this->assertSame(
            CareerCandidateExactCacheBootstrapRunner::coverageFingerprint($inspection),
            $receipt['coverage_fingerprint_sha256'],
        );
        $this->assertArrayNotHasKey('rows', $receipt);
    }

    #[Test]
    public function database_guard_allows_only_explicit_read_statements(): void
    {
        foreach ([
            'SELECT * FROM career_jobs',
            ' show tables',
            'DESCRIBE career_jobs',
            'EXPLAIN SELECT * FROM career_jobs',
            'PRAGMA table_info(career_jobs)',
        ] as $query) {
            CareerCandidateExactCacheBootstrapRunner::assertReadOnlySql($query);
            $this->addToAssertionCount(1);
        }

        foreach ([
            'INSERT INTO career_jobs VALUES (1)',
            'UPDATE career_jobs SET slug = "x"',
            'DELETE FROM career_jobs',
            'REPLACE INTO career_jobs VALUES (1)',
            'WITH changed AS (DELETE FROM career_jobs RETURNING *) SELECT * FROM changed',
            '/* hidden */ SELECT * FROM career_jobs',
        ] as $query) {
            try {
                CareerCandidateExactCacheBootstrapRunner::assertReadOnlySql($query);
                $this->fail('Expected database guard rejection.');
            } catch (CareerCandidateExactCacheBootstrapFailure $failure) {
                $this->assertSame('DATABASE_WRITE_BLOCKED', $failure->safeCode);
            }
        }
    }

    #[Test]
    public function build_budget_failure_retries_once_then_succeeds(): void
    {
        $inspection = $this->inspection([
            $this->row('private-target', 'en', true, 'missing_pointer'),
        ]);
        $calls = 0;
        $delays = 0;

        $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('b', 40),
            $inspection,
            0,
            50,
            fn (array $slugs): array => $this->closures($slugs),
            function () use (&$calls): array {
                $calls++;

                return $calls === 1
                    ? $this->warmFailure('build_detail_payload', 'build_budget_exceeded', 5123.4)
                    : $this->warmSuccess(1200.5);
            },
            fn (): array => $this->inspection([
                $this->row('private-target', 'en', false, 'ready_active'),
            ]),
            1,
            function () use (&$delays): void {
                $delays++;
            },
        );

        $this->assertSame('completed', $receipt['status']);
        $this->assertSame(2, $receipt['attempt_count']);
        $this->assertSame(1, $receipt['retry_count']);
        $this->assertSame(1, $delays);
        $this->assertSame(6323.9, $receipt['batch_build_ms_total']);
        $this->assertSame(5123.4, $receipt['batch_build_ms_max']);
    }

    #[Test]
    public function transient_database_failure_retries_but_permanent_failures_do_not(): void
    {
        $inspection = $this->inspection([
            $this->row('private-target', 'en', true, 'missing_pointer'),
        ]);
        $transientCalls = 0;
        $transient = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('c', 40),
            $inspection,
            0,
            50,
            fn (array $slugs): array => $this->closures($slugs),
            function () use (&$transientCalls): array {
                $transientCalls++;

                return $transientCalls === 1
                    ? $this->warmFailure('build_detail_payload', 'database_transient_read', 20)
                    : $this->warmSuccess(30);
            },
            fn (): array => $this->inspection([
                $this->row('private-target', 'en', false, 'ready_active'),
            ]),
            1,
            static fn (): null => null,
        );
        $this->assertSame('completed', $transient['status']);
        $this->assertSame(2, $transientCalls);

        foreach ([
            ['build_detail_payload', 'database_permanent_read'],
            ['publish_cache_payload', 'cache_publish_failed'],
            ['build_detail_payload', 'payload_not_cached'],
            ['build_detail_payload', 'unexpected'],
        ] as [$stage, $category]) {
            $calls = 0;
            $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
                str_repeat('d', 40),
                $inspection,
                0,
                50,
                fn (array $slugs): array => $this->closures($slugs),
                function () use (&$calls, $stage, $category): array {
                    $calls++;

                    return $this->warmFailure($stage, $category, 10);
                },
                static fn (): array => $inspection,
                1,
                static fn (): null => null,
            );
            $this->assertSame(1, $calls);
            $this->assertSame(0, $receipt['retry_count']);
            $this->assertSame($category, $receipt['error_category']);
        }
    }

    #[Test]
    public function second_retry_failure_stops_and_receipt_redacts_target_and_exception_details(): void
    {
        $inspection = $this->inspection([
            $this->row('first-private-slug', 'en', true, 'missing_pointer'),
            $this->row('must-not-run', 'zh-CN', true, 'missing_pointer'),
        ]);
        $calls = 0;

        $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('e', 40),
            $inspection,
            0,
            50,
            fn (array $slugs): array => $this->closures($slugs),
            function () use (&$calls): array {
                $calls++;

                return $this->warmFailure('build_detail_payload', 'build_budget_exceeded', 5100);
            },
            static fn (): array => $inspection,
            2,
            static fn (): null => null,
        );

        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $calls);
        $this->assertSame('failed', $receipt['status']);
        $this->assertSame(2, $receipt['attempt_count']);
        $this->assertSame(1, $receipt['retry_count']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $receipt['failed_target_index_sha256']);
        $this->assertStringNotContainsString('private-slug', $encoded);
        $this->assertStringNotContainsString('must-not-run', $encoded);
        $this->assertStringNotContainsString('exception', $encoded);
        $this->assertStringNotContainsString('cache_key', $encoded);
    }

    #[Test]
    public function offset_zero_recovery_skips_ready_rows_and_preserves_partial_success(): void
    {
        $inspection = $this->inspection([
            $this->row('already-complete', 'en', false, 'ready_active'),
            $this->row('remaining', 'zh-CN', true, 'missing_pointer'),
        ]);
        $warmed = [];

        $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('f', 40),
            $inspection,
            0,
            50,
            fn (array $slugs): array => $this->closures($slugs),
            function (string $slug) use (&$warmed): array {
                $warmed[] = $slug;

                return $this->warmSuccess(1);
            },
            fn (): array => $this->inspection([
                $this->row('already-complete', 'en', false, 'ready_active'),
                $this->row('remaining', 'zh-CN', false, 'ready_active'),
            ]),
            2,
        );

        $this->assertSame(['remaining'], $warmed);
        $this->assertSame(1, $receipt['cache_write_count']);
        $this->assertSame(0, $receipt['post_batch_coverage']['missing_pointer_count']);
    }

    #[Test]
    public function offsets_are_exact_multiples_of_fifty_and_final_batch_has_forty_two_rows(): void
    {
        $this->assertSame(42, count(array_filter(
            range(0, 2091),
            static fn (int $offset): bool => CareerCandidateExactCacheBootstrapRunner::isValidBatchOffset(
                $offset,
                2092,
            ),
        )));
        $this->assertTrue(CareerCandidateExactCacheBootstrapRunner::isValidBatchOffset(2050, 2092));
        $this->assertFalse(CareerCandidateExactCacheBootstrapRunner::isValidBatchOffset(2092, 2092));
        $this->assertFalse(CareerCandidateExactCacheBootstrapRunner::isValidBatchOffset(51, 2092));

        $rows = [];
        for ($index = 0; $index < 2092; $index++) {
            $rows[] = $this->row('ready-'.$index, $index % 2 === 0 ? 'en' : 'zh-CN', false, 'ready_active');
        }
        $inspection = $this->inspection($rows);
        $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('1', 40),
            $inspection,
            2050,
            50,
            static fn (): array => [],
            static fn (): array => throw new \RuntimeException('Ready targets must never warm.'),
            static fn (): array => $inspection,
            2092,
        );

        $this->assertSame(42, $receipt['inspected_target_count']);
        $this->assertSame(0, $receipt['cache_write_count']);
    }

    #[Test]
    public function coverage_and_target_hashes_are_deterministic_and_detect_classification_drift(): void
    {
        $inspection = $this->inspection([
            $this->row('one', 'en', true, 'missing_pointer'),
        ]);
        $same = $this->inspection([
            $this->row('one', 'en', true, 'missing_pointer'),
        ]);
        $drifted = $this->inspection([
            $this->row('one', 'en', false, 'ready_active'),
        ]);

        $this->assertSame(
            CareerCandidateExactCacheBootstrapRunner::coverageFingerprint($inspection),
            CareerCandidateExactCacheBootstrapRunner::coverageFingerprint($same),
        );
        $this->assertNotSame(
            CareerCandidateExactCacheBootstrapRunner::coverageFingerprint($inspection),
            CareerCandidateExactCacheBootstrapRunner::coverageFingerprint($drifted),
        );
        $this->assertSame(
            CareerCandidateExactCacheBootstrapRunner::targetIndexHash(str_repeat('2', 40), 7, 'en', 'one'),
            CareerCandidateExactCacheBootstrapRunner::targetIndexHash(str_repeat('2', 40), 7, 'en', 'one'),
        );
        $this->assertNotSame(
            CareerCandidateExactCacheBootstrapRunner::targetIndexHash(str_repeat('2', 40), 7, 'en', 'one'),
            CareerCandidateExactCacheBootstrapRunner::targetIndexHash(str_repeat('2', 40), 8, 'en', 'one'),
        );
    }

    #[Test]
    public function candidate_revision_drift_fails_before_candidate_bootstrap(): void
    {
        $root = storage_path('framework/testing/candidate-exact-runner-'.bin2hex(random_bytes(4)));
        $release = 'candidate-release';
        mkdir($root.'/'.$release.'/backend', 0777, true);
        file_put_contents($root.'/'.$release.'/REVISION', str_repeat('1', 40));

        try {
            CareerCandidateExactCacheBootstrapRunner::execute([
                'FM_CAREER_MODE' => 'preflight',
                'FM_CAREER_MANAGED_RELEASES_ROOT' => $root,
                'FM_CAREER_CANDIDATE_RELEASE' => $release,
                'FM_CAREER_CANDIDATE_SHA' => str_repeat('2', 40),
                'FM_CAREER_EXPECTED_TARGETS' => '2092',
                'FM_CAREER_EXPECTED_MISSING' => '2090',
                'FM_CAREER_OFFLINE_BUILD_BUDGET_MS' => '5000',
                'FM_CAREER_RETRY_LIMIT' => '1',
            ]);
            $this->fail('Expected candidate revision drift rejection.');
        } catch (CareerCandidateExactCacheBootstrapFailure $failure) {
            $this->assertSame('CANDIDATE_REVISION_DRIFT', $failure->safeCode);
        } finally {
            @unlink($root.'/'.$release.'/REVISION');
            @rmdir($root.'/'.$release.'/backend');
            @rmdir($root.'/'.$release);
            @rmdir($root);
        }
    }

    #[Test]
    public function unexpected_inputs_fail_closed(): void
    {
        $this->expectException(CareerCandidateExactCacheBootstrapFailure::class);
        $this->expectExceptionMessage('UNEXPECTED_INPUT');

        CareerCandidateExactCacheBootstrapRunner::assertOnlyAllowlistedInputs([
            'FM_CAREER_MODE' => 'preflight',
            'UNSAFE_INPUT' => 'value',
        ]);
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, array<string, mixed>>
     */
    private function closures(array $slugs): array
    {
        $closures = [];
        foreach ($slugs as $slug) {
            $closures[$slug] = [
                'subject_slug' => $slug,
                'counts' => [],
                'readiness' => [],
            ];
        }

        return $closures;
    }

    /** @return array<string, mixed> */
    private function warmSuccess(float $buildMs): array
    {
        return [
            'status' => 'cached',
            'failure_stage' => null,
            'error_category' => null,
            'build_ms' => $buildMs,
        ];
    }

    /** @return array<string, mixed> */
    private function warmFailure(string $stage, string $category, float $buildMs): array
    {
        return [
            'status' => 'failed',
            'failure_stage' => $stage,
            'error_category' => $category,
            'build_ms' => $buildMs,
        ];
    }

    /**
     * @param  list<array{slug: string, locale: string, classification: string, repairable: bool}>  $rows
     * @return array{report: array<string, mixed>, rows: list<array{slug: string, locale: string, classification: string, repairable: bool}>}
     */
    private function inspection(array $rows): array
    {
        $counts = [
            'ready_active' => 0,
            'ready_lkg' => 0,
            'legacy_migratable' => 0,
            'missing_pointer' => 0,
            'missing_payload' => 0,
            'broken_pointer' => 0,
            'invalid_payload' => 0,
            'held_or_unpublished_excluded' => 0,
        ];
        foreach ($rows as $row) {
            $counts[$row['classification']]++;
        }
        $expected = count($rows);
        $excluded = $counts['held_or_unpublished_excluded'];
        $eligible = $expected - $excluded;
        $covered = $counts['ready_active'] + $counts['ready_lkg'] + $counts['legacy_migratable'];
        $broken = $counts['broken_pointer'] + $counts['invalid_payload'];

        return [
            'report' => [
                'contract_version' => 'career.job_detail_cache_coverage.v1',
                'expected_target_count' => $expected,
                'eligible_target_count' => $eligible,
                'covered_target_count' => $covered,
                'missing_pointer_count' => $counts['missing_pointer'],
                'missing_payload_count' => $counts['missing_payload'],
                'broken_count' => $broken,
                'excluded_count' => $excluded,
                'coverage_ratio' => $eligible === 0 ? 1 : $covered / $eligible,
                'status' => ($counts['missing_pointer'] + $counts['missing_payload'] + $broken) === 0
                    ? 'ready'
                    : 'incomplete',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{slug: string, locale: string, classification: string, repairable: bool}
     */
    private function row(string $slug, string $locale, bool $repairable, string $classification): array
    {
        return compact('slug', 'locale', 'classification', 'repairable');
    }
}
