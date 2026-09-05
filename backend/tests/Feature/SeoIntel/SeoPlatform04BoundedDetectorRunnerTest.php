<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Detector\BoundedDetectorRunner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform04BoundedDetectorRunnerTest extends TestCase
{
    #[Test]
    public function dry_run_is_bounded_by_url_count_and_resumes_from_a_bound_cursor(): void
    {
        $runner = new BoundedDetectorRunner;
        $jobs = [
            $this->job('http_404', ['observed_status' => 404, 'affected_url_count' => 2]),
            $this->job('false_noindex', ['authority_indexable' => true, 'observed_noindex' => true, 'affected_url_count' => 2]),
        ];

        $first = $runner->run($jobs, $this->runOptions(['max_urls' => 2]));
        $second = $runner->run($jobs, $this->runOptions([
            'max_urls' => 2,
            'cursor' => $first['next_cursor'],
        ]));

        $this->assertSame('dry_run', $first['mode']);
        $this->assertSame('max_urls', $first['stop_reason']);
        $this->assertSame(1, $first['processed_result_count']);
        $this->assertSame(2, $first['processed_url_count']);
        $this->assertFalse($first['complete']);
        $this->assertNotNull($first['next_cursor']);
        $this->assertSame(1, $second['start_offset']);
        $this->assertSame(1, $second['processed_result_count']);
        $this->assertTrue($second['complete']);
        $this->assertNull($second['next_cursor']);
    }

    #[Test]
    public function family_and_locale_scope_skip_nonmatching_jobs_without_expanding_the_bound(): void
    {
        $jobs = [
            $this->job('http_404', ['observed_status' => 404]),
            $this->job('http_404', ['observed_status' => 404, 'locale' => 'zh-CN']),
            $this->job('http_404', ['observed_status' => 404, 'page_family' => 'career_jobs']),
        ];

        $artifact = (new BoundedDetectorRunner)->run($jobs, $this->runOptions([
            'page_family' => 'articles_topics',
            'locale' => 'en',
        ]));

        $this->assertTrue($artifact['complete']);
        $this->assertSame(1, $artifact['processed_result_count']);
        $this->assertSame(2, $artifact['scope_skipped_count']);
        $this->assertSame('articles_topics', data_get($artifact, 'scope.page_family'));
        $this->assertSame('en', data_get($artifact, 'scope.locale'));
    }

    #[Test]
    public function stale_revision_mismatch_and_missing_private_guard_become_measurement_holds(): void
    {
        $jobs = [
            $this->job('http_404', [
                'observed_status' => 404,
                'evidence_observed_at' => '2026-08-20T00:00:00Z',
            ]),
            $this->job('http_404', [
                'observed_status' => 404,
                'policy_version' => 'wrong-policy',
            ]),
            $this->job('http_404', [
                'observed_status' => 404,
                'private_negative_set_checked' => false,
            ]),
        ];

        $artifact = (new BoundedDetectorRunner)->run($jobs, $this->runOptions());

        $this->assertSame(3, data_get($artifact, 'outcome_counts.measurement_hold'));
        $this->assertSame([
            'evidence_stale',
            'policy_version_mismatch',
            'private_negative_set_not_checked',
        ], collect($artifact['results'])->pluck('root_cause_or_error_code')->all());
        foreach ($artifact['results'] as $result) {
            $this->assertNull($result['severity']);
            $this->assertFalse($result['human_intervention_required']);
        }
    }

    #[Test]
    public function timeout_returns_a_resume_cursor_without_reprocessing_completed_input(): void
    {
        $ticks = collect([0.0, 0.0, 2.0]);
        $runner = new BoundedDetectorRunner(monotonicClock: static fn (): float => (float) $ticks->shift());
        $jobs = [
            $this->job('http_404', ['observed_status' => 404]),
            $this->job('http_404', ['observed_status' => 404]),
        ];

        $artifact = $runner->run($jobs, $this->runOptions(['timeout_ms' => 1]));

        $this->assertSame('timeout', $artifact['stop_reason']);
        $this->assertSame(1, $artifact['processed_result_count']);
        $this->assertSame(1, $artifact['next_offset']);
        $this->assertNotNull($artifact['next_cursor']);
    }

    #[Test]
    public function artifact_is_hash_bound_idempotent_and_contains_no_raw_private_input(): void
    {
        $jobs = [
            $this->job('query_page_owner_conflict', [
                'query_hash' => hash('sha256', 'sensitive query'),
                'current_owner_count' => 2,
                'raw_query' => 'private phrase must not escape',
                'private_url' => 'https://example.test/results/private',
                'session' => 'session-must-not-escape',
            ]),
        ];
        $runner = new BoundedDetectorRunner;

        $first = $runner->run($jobs, $this->runOptions());
        $second = $runner->run($jobs, $this->runOptions());

        $this->assertSame($first['artifact_hash'], $second['artifact_hash']);
        $this->assertTrue($runner->verifyArtifact($first));
        $tampered = $first;
        $tampered['processed_url_count'] = 99;
        $this->assertFalse($runner->verifyArtifact($tampered));
        $encoded = json_encode($first, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private phrase', $encoded);
        $this->assertStringNotContainsString('/results/private', $encoded);
        $this->assertStringNotContainsString('session-must-not-escape', $encoded);
        $this->assertFalse(data_get($first, 'boundaries.writes_attempted'));
        $this->assertFalse(data_get($first, 'boundaries.search_submission_allowed'));
        $this->assertTrue(data_get($first, 'boundaries.read_only_gsc'));
    }

    #[Test]
    public function issue_and_recovery_pass_share_the_same_root_cause_cluster(): void
    {
        $runner = new BoundedDetectorRunner;
        $failure = $runner->run([
            $this->job('http_404', [
                'observed_status' => 404,
                'root_cause_or_error_code' => 'cms_route_missing',
            ]),
        ], $this->runOptions());
        $recovery = $runner->run([
            $this->job('http_404', [
                'observed_status' => 200,
                'root_cause_or_error_code' => 'cms_route_missing',
            ]),
        ], $this->runOptions());

        $this->assertSame('issue', data_get($failure, 'results.0.outcome'));
        $this->assertSame('pass', data_get($recovery, 'results.0.outcome'));
        $this->assertSame(data_get($failure, 'results.0.cluster_uid'), data_get($recovery, 'results.0.cluster_uid'));
    }

    #[Test]
    public function controlled_candidate_still_never_writes_and_invalid_cursor_or_bounds_fail_closed(): void
    {
        $runner = new BoundedDetectorRunner;
        $artifact = $runner->run([
            $this->job('http_404', ['observed_status' => 404]),
        ], $this->runOptions(['dry_run' => false]));

        $this->assertSame('controlled_materialization_candidate', $artifact['mode']);
        $this->assertFalse(data_get($artifact, 'boundaries.writes_attempted'));

        $this->expectException(InvalidArgumentException::class);
        $runner->run([
            $this->job('http_404', ['observed_status' => 404]),
        ], $this->runOptions(['cursor' => 'not-a-valid-cursor']));
    }

    #[Test]
    public function cursor_cannot_be_replayed_for_changed_input(): void
    {
        $runner = new BoundedDetectorRunner;
        $jobs = [
            $this->job('http_404', ['observed_status' => 404, 'affected_url_count' => 2]),
            $this->job('http_404', ['observed_status' => 404, 'affected_url_count' => 2]),
        ];
        $first = $runner->run($jobs, $this->runOptions(['max_urls' => 2]));

        $this->expectException(InvalidArgumentException::class);
        $runner->run([
            ...$jobs,
            $this->job('http_404', ['observed_status' => 404]),
        ], $this->runOptions([
            'max_urls' => 2,
            'cursor' => $first['next_cursor'],
        ]));
    }

    #[Test]
    public function cursor_signature_and_runner_bounds_fail_closed(): void
    {
        $runner = new BoundedDetectorRunner(cursorSigningKey: 'test-signing-key');
        $jobs = [
            $this->job('http_404', ['observed_status' => 404, 'affected_url_count' => 2]),
            $this->job('http_404', ['observed_status' => 404, 'affected_url_count' => 2]),
        ];
        $first = $runner->run($jobs, $this->runOptions(['max_urls' => 2]));
        $cursor = (string) $first['next_cursor'];
        $tamperedCursor = substr($cursor, 0, -1).($cursor[-1] === 'A' ? 'B' : 'A');

        try {
            $runner->run($jobs, $this->runOptions([
                'max_urls' => 2,
                'cursor' => $tamperedCursor,
            ]));
            $this->fail('A modified cursor must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('cursor', strtolower($exception->getMessage()));
        }

        try {
            $runner->run($jobs, $this->runOptions(['max_urls' => 501]));
            $this->fail('An unbounded URL request must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('max_urls', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $runner->run($jobs, $this->runOptions(['page_family' => '../unsafe']));
    }

    #[Test]
    public function invalid_evidence_timestamps_always_hold_without_parsing_now(): void
    {
        foreach (self::invalidTimestampInputs() as $label => $input) {
            $job = $this->job('http_404', ['observed_status' => 404]);
            unset($job['evidence']['evidence_observed_at']);
            $job['evidence'] += $input;
            $artifact = (new BoundedDetectorRunner)->run([$job], $this->runOptions());
            $this->assertSame('measurement_hold', $artifact['results'][0]['outcome'], $label);
            $this->assertSame('evidence_timestamp_missing_or_invalid', $artifact['results'][0]['root_cause_or_error_code'], $label);
        }
    }

    public static function invalidTimestampInputs(): array
    {
        $inputs = ['missing' => []];
        foreach ([null, '', '   ', 1787688000, 1787688000.5, true, false, [], (object) [], 'broken', 'now', 'tomorrow', '+1 minute',
            '2026-02-30T00:00:00Z', '2025-02-29T00:00:00Z', '2026-13-01T00:00:00Z', '2026-08-24T24:00:00Z', '2026-08-24T20:60:00Z',
            '2026-08-24T20:00:60Z', '2026-08-24T20:00:00+25:00', '2026-08-24T20:00:00Z trailing',
        ] as $index => $value) {
            $inputs['invalid-'.$index] = ['evidence_observed_at' => $value];
        }

        return $inputs;
    }

    #[Test]
    public function absolute_timestamp_offsets_precision_and_age_boundaries_remain_valid(): void
    {
        foreach (['2026-08-24T20:00:00Z', '2026-08-25T04:00:00+08:00', '2026-08-24T15:00:00-05:00',
            '2026-08-24T20:00:00.123456Z', '2026-08-24T20:00:00.1+00:00',
        ] as $timestamp) {
            $artifact = (new BoundedDetectorRunner)->run([
                $this->job('http_404', ['observed_status' => 404, 'evidence_observed_at' => $timestamp]),
            ], $this->runOptions());
            $this->assertSame('issue', $artifact['results'][0]['outcome'], $timestamp);
            $this->assertSame('2026-08-25T20:00:00+00:00', $artifact['materialize_before']);
        }
        foreach ([
            '2026-08-24T20:35:00Z' => 'issue',
            '2026-08-24T20:35:01Z' => 'evidence_timestamp_in_future',
            '2026-08-23T20:30:00Z' => 'issue',
            '2026-08-23T20:29:59Z' => 'evidence_stale',
        ] as $timestamp => $expected) {
            $artifact = (new BoundedDetectorRunner)->run([
                $this->job('http_404', ['observed_status' => 404, 'evidence_observed_at' => $timestamp]),
            ], $this->runOptions());
            $result = $artifact['results'][0];
            $this->assertSame($expected, $result['outcome'] === 'issue' ? 'issue' : $result['root_cause_or_error_code']);
        }
    }

    /** @param array<string, mixed> $evidence */
    private function job(string $detectorId, array $evidence): array
    {
        return [
            'detector_id' => $detectorId,
            'evidence' => array_replace([
                'source_state' => 'available',
                'evidence_complete' => true,
                'direct_evidence' => true,
                'page_family' => 'articles_topics',
                'locale' => 'en',
                'indexability_state' => 'indexable',
                'canonical_url_hash' => hash('sha256', $detectorId),
                'authority_revision' => 'authority-r1',
                'url_truth_revision' => 'url-truth-r1',
                'policy_version' => 'seo-page-family-policy.v1',
                'evidence_observed_at' => '2026-08-24T20:00:00Z',
                'private_negative_set_checked' => true,
                'affected_url_count' => 1,
            ], $evidence),
        ];
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function runOptions(array $overrides = []): array
    {
        return array_replace([
            'dry_run' => true,
            'max_urls' => 100,
            'timeout_ms' => 1_000,
            'max_evidence_age_seconds' => 86_400,
            'expected_policy_version' => 'seo-page-family-policy.v1',
            'expected_authority_revision' => 'authority-r1',
            'now' => '2026-08-24T20:30:00Z',
        ], $overrides);
    }
}
