<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use Tests\TestCase;

final class CareerSearchChannelReadinessGate01Test extends TestCase
{
    public function test_career_search_channel_readiness_gate_holds_submission_without_writes(): void
    {
        $reportPath = base_path('docs/seo/generated/career-search-channel-readiness-gate-01.v1.json');

        $this->assertFileExists($reportPath);

        $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('career_search_channel_readiness_gate.v1', $report['schema_version'] ?? null);
        $this->assertSame('CAREER-SEARCH-CHANNEL-READINESS-GATE-01', $report['task'] ?? null);
        $this->assertSame(
            'career_search_channel_readiness_gate_completed_hold_submission_ready_for_staged_plan',
            $report['final_decision'] ?? null,
        );
        $this->assertSame('HOLD', $report['search_channel_decision'] ?? null);
        $this->assertSame(1046, $report['public_detail_count'] ?? null);
        $this->assertSame(['en', 'zh'], $report['locales'] ?? null);
        $this->assertSame(2092, $report['public_career_detail_url_count'] ?? null);
        $this->assertSame($report['public_career_detail_url_count'], $report['sitemap_career_detail_url_count'] ?? null);
        $this->assertSame($report['sitemap_career_detail_url_count'], $report['llms_career_detail_url_count'] ?? null);
        $this->assertSame($report['sitemap_career_detail_url_count'], $report['llms_full_complete_career_detail_url_count'] ?? null);

        foreach ([
            'directory_authority_aligned',
            'sitemap_aligned',
            'llms_aligned',
            'llms_full_complete_artifact_aligned',
            'robots_index_follow_expected',
            'canonical_exact_expected',
            'claim_boundary_review_required',
        ] as $field) {
            $this->assertTrue($report['readiness_inputs'][$field] ?? false, $field);
        }

        $this->assertSame([
            'software-developers',
            'digital-forensics-analysts',
            'computer-occupations-all-other',
        ], $report['held_slugs'] ?? null);

        foreach (['runtime_detail', 'sitemap', 'llms', 'llms_full', 'search_channel_candidate_batch'] as $surface) {
            $this->assertTrue($report['held_slug_absence'][$surface] ?? false, $surface);
        }

        $this->assertSame(20, $report['staged_rollout_proposal']['canary_size'] ?? null);
        $this->assertSame(24, $report['staged_rollout_proposal']['observation_window_hours'] ?? null);
        $this->assertTrue($report['staged_rollout_proposal']['requires_explicit_future_approval'] ?? false);

        foreach ([
            'held_slug_exposure',
            'noindex_or_canonical_drift',
            'search_channel_queue_anomaly',
            'claim_boundary_regression',
            'staging_contamination',
            'external_search_api_failure',
        ] as $stopCondition) {
            $this->assertContains($stopCondition, $report['stop_conditions'] ?? []);
        }

        foreach ([
            'search_channel_enqueue_performed',
            'live_submission_performed',
            'url_submission_performed',
            'external_search_api_call_performed',
            'cms_mutation_performed',
            'database_mutation_performed',
            'runtime_promotion_performed',
            'sitemap_mutation_performed',
            'llms_mutation_performed',
            'deploy_performed',
            'fap_web_change_performed',
            'held_slug_release_performed',
        ] as $field) {
            $this->assertFalse($report['safety_boundaries'][$field] ?? true, $field);
        }

        $this->assertSame('CAREER-10K-ROLLOUT-ARCHITECTURE-SPEC-01', $report['next_task'] ?? null);
    }
}
