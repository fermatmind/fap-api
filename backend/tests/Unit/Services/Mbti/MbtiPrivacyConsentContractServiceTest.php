<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Mbti;

use App\Services\Attempts\AttemptDataLifecycleService;
use App\Services\Attempts\UserDataLifecycleService;
use App\Services\Mbti\MbtiPrivacyConsentContractService;
use Tests\TestCase;

final class MbtiPrivacyConsentContractServiceTest extends TestCase
{
    public function test_build_contract_exposes_subject_export_norming_and_erasure_boundaries(): void
    {
        $service = app(MbtiPrivacyConsentContractService::class);

        $contract = $service->buildContract($this->samplePersonalization(), [
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
        ]);

        $this->assertSame('mbti.privacy_contract.v1', $contract['version']);
        $this->assertSame('CN_MAINLAND', data_get($contract, 'policy.region'));
        $this->assertSame('2026-01-01', data_get($contract, 'policy.privacy_policy_version'));
        $this->assertSame(true, data_get($contract, 'consent_scope.subject_export'));
        $this->assertSame(true, data_get($contract, 'consent_scope.telemetry_product_improvement'));
        $this->assertSame(true, data_get($contract, 'consent_scope.experimentation_pseudonymous'));
        $this->assertSame(true, data_get($contract, 'consent_scope.norming_anonymized_only'));
        $this->assertContains('report.sections', data_get($contract, 'exportable_assets.canonical_result_paths', []));
        $this->assertContains('user_state', data_get($contract, 'exportable_assets.derived_personalization_fields', []));
        $this->assertContains('action_journey_v1', data_get($contract, 'exportable_assets.derived_personalization_fields', []));
        $this->assertContains('pulse_check_v1', data_get($contract, 'exportable_assets.derived_personalization_fields', []));
        $this->assertContains('share_id', data_get($contract, 'anonymized_vector_contract.forbidden_direct_identifiers', []));
        $this->assertSame(
            AttemptDataLifecycleService::class,
            data_get($contract, 'erasure_scope.execution_services.attempt')
        );
        $this->assertSame(
            UserDataLifecycleService::class,
            data_get($contract, 'erasure_scope.execution_services.subject')
        );
    }

    public function test_build_contract_downgrades_to_public_safe_scope_for_share_surfaces(): void
    {
        $service = app(MbtiPrivacyConsentContractService::class);

        $contract = $service->buildContract($this->samplePersonalization(), [
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'public_safe' => true,
        ]);

        $this->assertSame(false, data_get($contract, 'consent_scope.subject_export'));
        $this->assertSame(false, data_get($contract, 'consent_scope.telemetry_product_improvement'));
        $this->assertSame(false, data_get($contract, 'consent_scope.experimentation_pseudonymous'));
        $this->assertSame(false, data_get($contract, 'consent_scope.norming_anonymized_only'));
        $this->assertSame(true, data_get($contract, 'consent_scope.public_share_summary'));
        $this->assertSame(false, data_get($contract, 'exportable_assets.subject_bundle_available'));
        $this->assertSame(false, data_get($contract, 'anonymized_vector_contract.available'));
    }

    public function test_build_subject_export_bundle_contains_canonical_and_derived_assets_without_direct_identifiers(): void
    {
        $service = app(MbtiPrivacyConsentContractService::class);
        $bundle = $service->buildSubjectExportBundle(
            [
                'type_code' => 'INTJ-A',
                'scores_json' => ['EI' => ['sum' => -6]],
                'scores_pct' => ['EI' => 35],
                'axis_states' => ['EI' => 'clear'],
            ],
            [
                'report' => [
                    'summary' => 'Canonical summary',
                    'sections' => [['key' => 'overview']],
                ],
                'mbti_public_summary_v1' => ['title' => 'INTJ - Architect'],
                'mbti_public_projection_v1' => ['display_type' => 'INTJ-A'],
            ],
            $this->samplePersonalization(),
            [
                'attempt_id' => 'attempt-export-1',
                'region' => 'CN_MAINLAND',
                'locale' => 'zh-CN',
            ]
        );

        $this->assertSame('mbti.subject_export.v1', $bundle['schema']);
        $this->assertSame('attempt-export-1', data_get($bundle, 'subject_ref.attempt_id'));
        $this->assertSame('INTJ-A', data_get($bundle, 'canonical_assets.type_code'));
        $this->assertSame('Canonical summary', data_get($bundle, 'canonical_assets.report.summary'));
        $this->assertSame('做决定前先写出一个命名规则。', data_get($bundle, 'derived_assets.personalization.action_plan_summary'));
        $this->assertSame('INTJ - Architect', data_get($bundle, 'derived_assets.mbti_public_summary_v1.title'));
        $this->assertArrayNotHasKey('user_id', $bundle['subject_ref']);
        $this->assertArrayNotHasKey('anon_id', $bundle['subject_ref']);
        $this->assertContains('events.meta_json', $bundle['excluded_meta_paths']);
    }

    public function test_build_anonymized_vector_bundle_keeps_only_norming_safe_features(): void
    {
        $service = app(MbtiPrivacyConsentContractService::class);
        $bundle = $service->buildAnonymizedVectorBundle($this->samplePersonalization(), [
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
        ]);

        $this->assertSame('mbti.anonymized_vector.v1', $bundle['schema']);
        $this->assertSame(true, data_get($bundle, 'consent_scope.norming_anonymized_only'));
        $this->assertSame('INTJ', data_get($bundle, 'vector.canonical_type_code'));
        $this->assertSame('A', data_get($bundle, 'vector.identity'));
        $this->assertSame('work.primary.EI.I.clear', data_get($bundle, 'vector.scene_fingerprint.work'));
        $this->assertSame(['JP'], data_get($bundle, 'vector.close_call_axes'));
        $this->assertArrayNotHasKey('attempt_id', $bundle['vector']);
        $this->assertArrayNotHasKey('share_id', $bundle['vector']);
        $this->assertArrayNotHasKey('anon_id', $bundle['vector']);
    }

    public function test_build_erasure_scope_manifest_maps_attempt_subject_and_future_norming_candidates(): void
    {
        $service = app(MbtiPrivacyConsentContractService::class);
        $manifest = $service->buildErasureScopeManifest([
            'attempt_id' => 'attempt-erase-1',
            'mode' => 'delete',
            'has_user_subject' => true,
            'has_anon_subject' => true,
        ]);

        $this->assertSame('mbti.erasure_scope.v1', $manifest['schema']);
        $this->assertSame('delete', $manifest['mode']);
        $this->assertSame('attempt-erase-1', data_get($manifest, 'subject_ref.attempt_id'));
        $this->assertContains('results', $manifest['attempt_objects']);
        $this->assertContains('events', $manifest['subject_objects']);
        $this->assertContains('events:share_result', $manifest['share_public_records']);
        $this->assertContains('anonymized_vector_exports', $manifest['future_norming_candidates']);
    }

    /**
     * @return array<string, mixed>
     */
    private function samplePersonalization(): array
    {
        return [
            'locale' => 'zh-CN',
            'type_code' => 'INTJ-A',
            'identity' => 'A',
            'axis_bands' => [
                'EI' => 'clear',
                'JP' => 'boundary',
            ],
            'boundary_flags' => [
                'EI' => false,
                'JP' => true,
            ],
            'dominant_axes' => [
                [
                    'axis' => 'EI',
                    'side' => 'I',
                    'percent' => 65,
                    'state' => 'clear',
                ],
            ],
            'scene_fingerprint' => [
                'work' => ['style_key' => 'work.primary.EI.I.clear'],
                'growth' => ['style_key' => 'growth.primary.JP.J.boundary'],
            ],
            'close_call_axes' => [
                ['axis' => 'JP'],
            ],
            'confidence_or_stability_keys' => ['stability.bucket.context_sensitive'],
            'ordered_recommendation_keys' => ['read-explain', 'read-action'],
            'ordered_action_keys' => ['weekly_action.theme.name_decision_rule'],
            'recommendation_priority_keys' => ['read-explain'],
            'action_priority_keys' => ['weekly_action.theme.name_decision_rule'],
            'reading_focus_key' => 'read-explain',
            'action_focus_key' => 'weekly_action.theme.name_decision_rule',
            'action_plan_summary' => '做决定前先写出一个命名规则。',
            'user_state' => [
                'is_first_view' => true,
                'is_revisit' => false,
            ],
            'orchestration' => [
                'primary_focus_key' => 'traits.close_call_axes',
            ],
            'continuity' => [
                'carryover_focus_key' => 'traits.close_call_axes',
            ],
            'variant_keys' => [
                'overview' => 'overview:INTJ-A',
            ],
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'engine_version' => 'report_phase4a_contract',
            'dynamic_sections_version' => 'phase9c.v1',
        ];
    }
}
