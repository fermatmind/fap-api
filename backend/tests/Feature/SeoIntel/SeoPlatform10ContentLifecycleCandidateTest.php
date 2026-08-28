<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Lifecycle\ContentLifecycleCandidateEvaluator;
use App\Services\SeoIntel\Lifecycle\ContentLifecycleReviewPolicy;
use InvalidArgumentException;
use Tests\TestCase;

final class SeoPlatform10ContentLifecycleCandidateTest extends TestCase
{
    public function test_review_policy_is_explicit_for_family_locale_and_claim_risk(): void
    {
        $policy = new ContentLifecycleReviewPolicy;

        $this->assertSame(120, $policy->resolve('career', 'zh-CN', 'low')['review_cycle_days']);
        $this->assertSame(90, $policy->resolve('career', 'en', 'medium')['review_cycle_days']);
        $this->assertSame(30, $policy->resolve('career', 'en', 'high')['review_cycle_days']);
        $this->assertFalse($policy->resolve('career', 'zh-CN', 'high')['translation_fallback_allowed']);
    }

    public function test_review_overdue_candidate_is_traceable_stable_and_read_only(): void
    {
        $input = $this->input([
            'last_reviewed_at' => '2026-04-01T00:00:00Z',
            'calculated_at' => '2026-08-01T00:00:00Z',
        ]);
        $evaluator = new ContentLifecycleCandidateEvaluator;

        $first = $evaluator->evaluate($input);
        $second = $evaluator->evaluate($input);

        $this->assertSame($first, $second);
        $this->assertSame(1, $first['candidate_count']);
        $this->assertSame(0, $first['hold_count']);
        $this->assertSame('review_overdue', data_get($first, 'candidates.0.candidate_type'));
        $this->assertSame('refresh', data_get($first, 'candidates.0.recommended_action'));
        $this->assertSame('candidate', data_get($first, 'candidates.0.status'));
        $this->assertSame($input['evidence_revision'], data_get($first, 'candidates.0.evidence_revision'));
        $this->assertSame($input['material_fingerprint'], data_get($first, 'candidates.0.material_fingerprint'));
        $this->assertSame('2026-08-01T00:00:00+00:00', data_get($first, 'candidates.0.calculated_at'));
        $this->assertFalse(data_get($first, 'candidates.0.execution_authorized'));
        $this->assertTrue(data_get($first, 'capabilities.read_only'));
        $this->assertFalse(data_get($first, 'capabilities.automatic_noindex'));
        $this->assertFalse(data_get($first, 'capabilities.authority_mutation'));
    }

    public function test_confirmed_decay_can_only_recommend_an_allowed_action(): void
    {
        $result = (new ContentLifecycleCandidateEvaluator)->evaluate($this->input([
            'last_reviewed_at' => '2026-07-05T00:00:00Z',
            'decay_evidence' => $this->decayEvidence('merge'),
        ]));

        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame('content_decay', data_get($result, 'candidates.0.candidate_type'));
        $this->assertSame('merge', data_get($result, 'candidates.0.recommended_action'));
        $this->assertSame('candidate', data_get($result, 'candidates.0.status'));
        $this->assertSame('opportunity', data_get($result, 'candidates.0.evidence.detector_result.outcome'));
        $this->assertFalse(data_get($result, 'candidates.0.execution_authorized'));
    }

    public function test_stale_gsc_insufficient_sample_and_runtime_incident_hold_candidates(): void
    {
        $evaluator = new ContentLifecycleCandidateEvaluator;

        $stale = $this->decayEvidence('retire');
        $stale['gsc_rows'][0]['report_date'] = '2026-07-01';
        $staleResult = $evaluator->evaluate($this->input([
            'last_reviewed_at' => '2026-07-05T00:00:00Z',
            'decay_evidence' => $stale,
        ]));
        $this->assertSame('hold', data_get($staleResult, 'candidates.0.status'));
        $this->assertSame('gsc_evidence_expired', data_get($staleResult, 'candidates.0.hold_reason'));
        $this->assertSame('retire', data_get($staleResult, 'candidates.0.recommended_action'));

        $insufficient = $this->decayEvidence();
        $insufficient['sample_sufficient'] = false;
        $sampleResult = $evaluator->evaluate($this->input([
            'last_reviewed_at' => '2026-07-05T00:00:00Z',
            'decay_evidence' => $insufficient,
        ]));
        $this->assertSame('hold', data_get($sampleResult, 'candidates.0.status'));
        $this->assertSame('gsc_sample_insufficient', data_get($sampleResult, 'candidates.0.hold_reason'));

        $incidentResult = $evaluator->evaluate($this->input([
            'last_reviewed_at' => '2026-01-01T00:00:00Z',
            'runtime_incident_active' => true,
            'decay_evidence' => $this->decayEvidence(),
        ]));
        $this->assertSame(0, $incidentResult['candidate_count']);
        $this->assertSame(2, $incidentResult['hold_count']);
        $this->assertSame(['runtime_incident_active', 'runtime_incident_active'], array_column($incidentResult['candidates'], 'hold_reason'));
    }

    public function test_non_confirmed_decay_does_not_generate_a_candidate(): void
    {
        $decay = $this->decayEvidence();
        $decay['detector_evidence']['consecutive_weekly_detection_count'] = 1;

        $result = (new ContentLifecycleCandidateEvaluator)->evaluate($this->input([
            'last_reviewed_at' => '2026-07-05T00:00:00Z',
            'decay_evidence' => $decay,
        ]));

        $this->assertSame([], $result['candidates']);
    }

    public function test_unknown_action_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('refresh, merge, or retire');

        $decay = $this->decayEvidence('noindex');
        (new ContentLifecycleCandidateEvaluator)->evaluate($this->input([
            'last_reviewed_at' => '2026-07-01T00:00:00Z',
            'decay_evidence' => $decay,
        ]));
    }

    public function test_cross_locale_fallback_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('independently supported locale');

        (new ContentLifecycleCandidateEvaluator)->evaluate($this->input(['locale' => 'zh']));
    }

    public function test_missing_review_evidence_holds_instead_of_inferring_overdue(): void
    {
        $result = (new ContentLifecycleCandidateEvaluator)->evaluate($this->input([
            'last_reviewed_at' => null,
        ]));

        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(1, $result['hold_count']);
        $this->assertSame('review_source_unavailable', data_get($result, 'candidates.0.hold_reason'));
        $this->assertNull(data_get($result, 'candidates.0.evidence.last_reviewed_at'));
        $this->assertNull(data_get($result, 'candidates.0.evidence.days_since_review'));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'page_family' => 'career',
            'locale' => 'zh-CN',
            'claim_risk' => 'high',
            'canonical_url_hash' => hash('sha256', 'https://fermatmind.com/zh/career/jobs/example'),
            'authority_revision' => 'career-current-r1',
            'evidence_revision' => 'gsc-export-r1',
            'material_fingerprint' => hash('sha256', 'material-r1'),
            'last_reviewed_at' => '2026-07-01T00:00:00Z',
            'calculated_at' => '2026-08-01T00:00:00Z',
            'runtime_incident_active' => false,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function decayEvidence(string $action = 'refresh'): array
    {
        $canonicalHash = hash('sha256', 'https://fermatmind.com/zh/career/jobs/example');

        return [
            'recommended_action' => $action,
            'sample_sufficient' => true,
            'gsc_rows' => [[
                'report_date' => '2026-07-28',
                'canonical_url_hash' => $canonicalHash,
                'query_hash' => hash('sha256', 'career query'),
                'source_engine' => 'google',
                'clicks' => 4,
                'impressions' => 100,
                'metadata_json' => ['data_origin' => 'live_gsc_api'],
            ]],
            'detector_evidence' => [
                'source_state' => 'available',
                'evidence_complete' => true,
                'direct_evidence' => true,
                'indexability_state' => 'indexable',
                'query_segment' => 'non_branded',
                'complete_windows' => true,
                'window_days' => 28,
                'comparison_window_days' => 28,
                'consecutive_weekly_detection_count' => 2,
                'baseline_impressions' => 1000,
                'policy_baseline_impression_threshold' => 100,
                'recent_28_day_impressions' => 500,
                'previous_28_day_impressions' => 1000,
                'inside_new_or_major_edit_protection' => false,
                'incident_excluded' => true,
                'seasonality_excluded' => true,
            ],
        ];
    }
}
