<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CareerL3DynamicSlotArchitecture01Test extends TestCase
{
    #[Test]
    public function generated_artifact_records_dynamic_slot_only_architecture(): void
    {
        $payload = $this->payload();

        $this->assertSame('career_l3_dynamic_slot_architecture.v1', $payload['schema_version'] ?? null);
        $this->assertSame('CAREER-L3-DYNAMIC-SLOT-ARCHITECTURE-01', $payload['task'] ?? null);
        $this->assertSame('dynamic_slot_only', $payload['architecture_mode'] ?? null);
        $this->assertSame('backend_career_runtime_authority_plus_backend_approved_assessment_context', $payload['source_of_truth'] ?? null);
        $this->assertSame(1046, $payload['public_career_detail_expected_count'] ?? null);
        $this->assertSame(2092, $payload['localized_public_career_url_expected_count'] ?? null);

        $this->assertEqualsCanonicalizing(['mbti', 'big_five', 'riasec'], $payload['supported_signal_families'] ?? []);

        foreach ([
            'user_or_session_assessment_context_required',
            'backend_authority_required',
            'claim_boundary_required',
            'budgeted_runtime_fetch_required',
            'fail_closed_when_authority_missing',
            'no_frontend_fallback_authority',
        ] as $requirement) {
            $this->assertContains($requirement, $payload['dynamic_slot_requirements'] ?? []);
        }
    }

    #[Test]
    public function static_combinatorial_pseo_and_discoverability_are_blocked(): void
    {
        $payload = $this->payload();

        $this->assertFalse((bool) ($payload['static_combinatorial_pages_allowed'] ?? true));
        $this->assertSame(0, $payload['static_cross_matrix_url_count'] ?? null);
        $this->assertSame(16736, $payload['estimated_mbti_career_static_matrix_count'] ?? null);

        foreach ([
            'pseo_generation_performed',
            'static_l3_generation_performed',
            'sitemap_llms_exposure_changed',
            'sitemap_llms_exposure_allowed_for_slots',
            'search_channel_action_performed',
            'url_submission_performed',
            'cms_mutation_performed',
            'production_write_performed',
            'deploy_performed',
            'frontend_fallback_authority_used',
            'fap_web_change_performed',
        ] as $flag) {
            $this->assertFalse((bool) ($payload[$flag] ?? true), $flag);
        }

        foreach ([
            '/career/jobs/{slug}/mbti/{type}',
            '/career/jobs/{slug}/big-five/{profile}',
            '/career/jobs/{slug}/riasec/{code}',
            '/mbti/{type}/careers/{slug}',
            '/big-five/{profile}/careers/{slug}',
            '/riasec/{code}/careers/{slug}',
        ] as $pattern) {
            $this->assertContains($pattern, $payload['blocked_static_url_patterns'] ?? []);
        }
    }

    #[Test]
    public function claim_boundary_stays_bounded_for_l3_slots(): void
    {
        $payload = $this->payload();

        $this->assertSame([], data_get($payload, 'claim_boundary.forbidden_claim_hits'));

        foreach (['exploratory_guidance', 'decision_support', 'workstyle_tendency', 'interest_signal'] as $framing) {
            $this->assertContains($framing, data_get($payload, 'claim_boundary.allowed_framing', []));
        }

        foreach ([
            'best_career_for_user',
            'hiring_fit',
            'job_suitability_guarantee',
            'salary_prediction_or_guarantee',
            'turnover_prediction',
            'career_success_guarantee',
            'diagnosis_treatment_cure',
            'psychometric_determines_career',
        ] as $claim) {
            $this->assertContains($claim, data_get($payload, 'claim_boundary.blocked_claims', []));
        }
    }

    #[Test]
    public function report_has_required_sections_and_next_task(): void
    {
        $path = base_path('docs/seo/career-l3-dynamic-slot-architecture-01.md');

        $this->assertFileExists($path);

        $report = (string) file_get_contents($path);

        foreach ([
            '## 1. Executive Summary',
            '## 2. Dynamic Slot Model',
            '## 3. Static pSEO Block',
            '## 4. Discoverability Boundary',
            '## 5. Claim Boundary',
            '## 6. Validation',
            '## 7. What Was Not Done',
            '## 8. Final Decision',
            '## 9. Next Task',
        ] as $heading) {
            $this->assertStringContainsString($heading, $report);
        }

        $this->assertSame(
            'career_l3_dynamic_slot_architecture_completed_ready_for_release_train_sidecar_soft_alert',
            $this->payload()['final_decision'] ?? null,
        );
        $this->assertSame('RELEASE-TRAIN-SIDECAR-SOFT-ALERT-01', $this->payload()['next_task'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = base_path('docs/seo/generated/career-l3-dynamic-slot-architecture-01.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
