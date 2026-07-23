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
    public function preflight_receipt_is_read_only_and_contains_only_bounded_coverage(): void
    {
        $receipt = CareerCandidateExactCacheBootstrapRunner::preflightReceipt(
            str_repeat('a', 40),
            $this->inspection([
                $this->row('ready', 'en', false, 'ready_active'),
                $this->row('missing', 'zh-CN', true, 'missing_pointer'),
            ]),
        );

        $this->assertSame('preflight', $receipt['mode']);
        $this->assertSame('ready', $receipt['status']);
        $this->assertSame(0, $receipt['cache_write_count']);
        $this->assertSame(0, $receipt['queue_dispatch_count']);
        $this->assertSame(0, $receipt['database_write_count']);
        $this->assertSame(1, $receipt['repairable_target_count']);
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
    public function batch_uses_fixed_row_offset_skips_ready_targets_and_preserves_ready_pointer_state(): void
    {
        $inspection = $this->inspection([
            $this->row('ready', 'en', false, 'ready_active'),
            $this->row('missing-a', 'en', true, 'missing_pointer'),
            $this->row('missing-b', 'zh-CN', true, 'missing_pointer'),
        ]);
        $warmed = [];

        $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('b', 40),
            $inspection,
            0,
            250,
            function (string $slug, string $locale) use (&$warmed): array {
                $warmed[] = $slug.'|'.$locale;

                return ['status' => 'cached'];
            },
            fn (): array => $this->inspection([
                $this->row('ready', 'en', false, 'ready_active'),
                $this->row('missing-a', 'en', false, 'ready_active'),
                $this->row('missing-b', 'zh-CN', false, 'ready_active'),
            ]),
            3,
        );

        $this->assertSame(['missing-a|en', 'missing-b|zh-CN'], $warmed);
        $this->assertSame(3, $receipt['inspected_target_count']);
        $this->assertSame(2, $receipt['repairable_target_count']);
        $this->assertSame(2, $receipt['cache_write_count']);
        $this->assertSame(0, $receipt['failure_count']);
        $this->assertSame(0, $receipt['queue_dispatch_count']);
    }

    #[Test]
    public function batch_stops_on_first_failure_and_receipt_never_contains_target_or_exception_details(): void
    {
        $inspection = $this->inspection([
            $this->row('first-private-slug', 'en', true, 'missing_pointer'),
            $this->row('second-private-slug', 'zh-CN', true, 'missing_pointer'),
            $this->row('must-not-run', 'en', true, 'missing_pointer'),
        ]);
        $calls = 0;

        $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('c', 40),
            $inspection,
            0,
            250,
            function () use (&$calls): array {
                $calls++;
                if ($calls === 2) {
                    throw new \RuntimeException('secret cache key and private slug');
                }

                return ['status' => 'cached'];
            },
            fn (): array => $inspection,
            3,
        );

        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $calls);
        $this->assertSame('failed', $receipt['status']);
        $this->assertSame(1, $receipt['cache_write_count']);
        $this->assertSame(1, $receipt['failure_count']);
        $this->assertSame('TARGET_WARM_FAILED', $receipt['error_code']);
        $this->assertStringNotContainsString('private-slug', $encoded);
        $this->assertStringNotContainsString('secret cache key', $encoded);
        $this->assertStringNotContainsString('must-not-run', $encoded);
    }

    #[Test]
    public function partial_success_is_recoverable_from_offset_zero_without_rewarming_ready_rows(): void
    {
        $secondInspection = $this->inspection([
            $this->row('already-complete', 'en', false, 'ready_active'),
            $this->row('remaining', 'zh-CN', true, 'missing_pointer'),
        ]);
        $warmed = [];

        $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('d', 40),
            $secondInspection,
            0,
            250,
            function (string $slug) use (&$warmed): array {
                $warmed[] = $slug;

                return ['status' => 'cached'];
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
    public function runner_accepts_only_the_nine_fixed_offsets_and_exact_batch_size(): void
    {
        $this->assertSame(
            [0, 250, 500, 750, 1000, 1250, 1500, 1750, 2000],
            CareerCandidateExactCacheBootstrapRunner::BATCH_OFFSETS,
        );

        foreach ([1, 249, 251, 2250] as $offset) {
            try {
                CareerCandidateExactCacheBootstrapRunner::batchReceipt(
                    str_repeat('e', 40),
                    $this->inspection([]),
                    $offset,
                    250,
                    static fn (): array => ['status' => 'cached'],
                    fn (): array => $this->inspection([]),
                    0,
                );
                $this->fail('Expected batch boundary rejection.');
            } catch (CareerCandidateExactCacheBootstrapFailure $failure) {
                $this->assertSame('INVALID_BATCH_BOUNDARY', $failure->safeCode);
            }
        }
    }

    #[Test]
    public function final_fixed_batch_inspects_exactly_the_remaining_ninety_two_rows(): void
    {
        $rows = [];
        for ($index = 0; $index < 2092; $index++) {
            $rows[] = $this->row('ready-'.$index, $index % 2 === 0 ? 'en' : 'zh-CN', false, 'ready_active');
        }
        $inspection = $this->inspection($rows);

        $receipt = CareerCandidateExactCacheBootstrapRunner::batchReceipt(
            str_repeat('f', 40),
            $inspection,
            2000,
            250,
            static fn (): array => throw new \RuntimeException('Ready targets must never warm.'),
            static fn (): array => $inspection,
            2092,
        );

        $this->assertSame(92, $receipt['inspected_target_count']);
        $this->assertSame(0, $receipt['repairable_target_count']);
        $this->assertSame(0, $receipt['cache_write_count']);
    }

    #[Test]
    public function preflight_rejects_count_drift_broken_or_excluded_targets(): void
    {
        $inspection = $this->inspection([
            $this->row('missing', 'en', true, 'missing_pointer'),
        ]);
        CareerCandidateExactCacheBootstrapRunner::assertInspection($inspection, 1, 1, true);
        $this->addToAssertionCount(1);

        foreach ([
            [$inspection, 1, 0],
            [$this->inspection([$this->row('broken', 'en', true, 'broken_pointer')]), 1, 0],
            [$this->inspection([$this->row('held', 'en', false, 'held_or_unpublished_excluded')]), 1, 0],
        ] as [$candidate, $targets, $missing]) {
            try {
                CareerCandidateExactCacheBootstrapRunner::assertInspection($candidate, $targets, $missing, true);
                $this->fail('Expected coverage boundary rejection.');
            } catch (CareerCandidateExactCacheBootstrapFailure $failure) {
                $this->assertContains($failure->safeCode, [
                    'MISSING_POINTER_COUNT_DRIFT',
                    'COVERAGE_BOUNDARY_FAILED',
                    'TARGET_COUNT_DRIFT',
                ]);
            }
        }
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
