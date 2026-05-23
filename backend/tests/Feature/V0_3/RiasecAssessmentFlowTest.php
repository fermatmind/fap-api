<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RiasecAssessmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_riasec_questions_support_standard_60_and_enhanced_140_forms(): void
    {
        $this->seedScales();

        $standard = $this->getJson('/api/v0.3/scales/RIASEC/questions?form_code=riasec_60&locale=zh-CN');
        $standard->assertStatus(200);
        $standard->assertJsonPath('ok', true);
        $standard->assertJsonPath('scale_code', 'RIASEC');
        $standard->assertJsonPath('form_code', 'riasec_60');
        $standard->assertJsonPath('dir_version', 'v1-standard-60');
        $standard->assertJsonPath('questions.schema', 'fap.questions.v1');
        $this->assertCount(60, (array) $standard->json('questions.items'));
        $standard->assertJsonPath('questions.items.0.options.0.code', '1');
        $standard->assertJsonPath('questions.items.0.options.4.code', '5');

        $enhanced = $this->getJson('/api/v0.3/scales/RIASEC/questions?form_code=riasec_140&locale=zh-CN');
        $enhanced->assertStatus(200);
        $enhanced->assertJsonPath('ok', true);
        $enhanced->assertJsonPath('scale_code', 'RIASEC');
        $enhanced->assertJsonPath('form_code', 'riasec_140');
        $enhanced->assertJsonPath('dir_version', 'v1-enhanced-140');
        $this->assertCount(140, (array) $enhanced->json('questions.items'));
        $enhanced->assertJsonPath('meta.form_kind', 'enhanced');
    }

    public function test_riasec_standard_60_start_submit_and_result_readback_use_backend_result(): void
    {
        $this->seedScales();

        $anonId = 'anon_riasec_standard';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'RIASEC',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => '60',
        ]);
        $start->assertStatus(200);
        $start->assertJsonPath('ok', true);
        $start->assertJsonPath('scale_code', 'RIASEC');
        $start->assertJsonPath('form_code', 'riasec_60');
        $start->assertJsonPath('dir_version', 'v1-standard-60');
        $start->assertJsonPath('question_count', 60);
        $start->assertJsonPath('scoring_spec_version', 'riasec_standard_60_v1');
        $start->assertJsonPath('measurement_contract_version', 'riasec.measurement_contract.v1');
        $start->assertJsonPath('score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $start->assertJsonPath('raw_score_delta_allowed', false);

        $attemptId = (string) $start->json('attempt_id');
        $answers = $this->answers(60);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 180000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('attempt_id', $attemptId);
        $submit->assertJsonPath('result.top_code', 'RIA');
        $submit->assertJsonPath('result.score_R', 100);

        $stored = Result::query()->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($stored);
        $this->assertSame('RIASEC', (string) $stored->scale_code);
        $this->assertSame('v1-standard-60', (string) $stored->dir_version);
        $this->assertSame('RIA', (string) data_get($stored->result_json, 'top_code'));
        $this->assertSame('riasec_60', (string) data_get($stored->result_json, 'form_code'));
        $this->assertSame('riasec_60_likert5_activity_sum_space.v1', (string) data_get($stored->result_json, 'score_space_version'));
        $this->assertSame('riasec.measurement_contract.v1', (string) data_get($stored->result_json, 'measurement_contract_v1.schema_version'));
        $this->assertFalse((bool) data_get($stored->result_json, 'raw_score_delta_allowed'));

        $readback = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");
        $readback->assertStatus(200);
        $readback->assertJsonPath('ok', true);
        $readback->assertJsonPath('type_code', 'RIA');
        $readback->assertJsonPath('result.top_code', 'RIA');
        $readback->assertJsonPath('riasec_public_projection_v1.top_code', 'RIA');
        $readback->assertJsonPath('riasec_form_v1.form_code', 'riasec_60');
        $readback->assertJsonPath('riasec_form_v1.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $readback->assertJsonPath('riasec_form_v1.raw_score_delta_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v1.form.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $readback->assertJsonPath('riasec_public_projection_v1.measurement_contract_v1.claim_boundary.does_not_measure.0', 'ability');
        $readback->assertJsonPath('riasec_public_projection_v2.schema_version', 'riasec.public_projection.v2');
        $readback->assertJsonPath('riasec_public_projection_v2.form.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $readback->assertJsonPath('riasec_public_projection_v2.form.raw_score_delta_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.measurement_contract_version', 'riasec.measurement_contract.v1');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.scoring_spec_version', 'riasec_standard_60_v1');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.normalization_method', 'raw_sum_per_dimension_min10_max50_to_0_100');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.quality_rule_status', 'minimal_answer_completion_only');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.quality_rule_version', 'riasec_quality_rule_spec_v2');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.interpretation_rule_version', 'riasec_interpretation_rule_spec_v2');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.snapshot_bound', false);
        $readback->assertJsonPath('riasec_public_projection_v2.quality.quality_state', 'normal');
        $readback->assertJsonPath('riasec_public_projection_v2.quality.score_mutation_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.quality.measured_holland_code_mutation_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.interpretation_state.interpretation_rule_version', 'riasec_interpretation_rule_spec_v2');
        $readback->assertJsonPath('riasec_public_projection_v2.interpretation_state.profile_shape_version', 'riasec_profile_shape_v2_0');
        $readback->assertJsonPath('riasec_public_projection_v2.interpretation_state.module_visibility_policy_id', 'riasec_module_visibility_policy_v1');
        $readback->assertJsonPath('riasec_public_projection_v2.interpretation_state.top_code_confidence.meaning', 'readability strength, not probability');
        $readback->assertJsonPath('riasec_public_projection_v2.interpretation_state.field_authority.profile_shape', 'backend_owned');
        $readback->assertJsonPath('riasec_public_projection_v2.module_visibility_policy.schema_version', 'riasec.module_visibility_policy.v1');
        $readback->assertJsonPath('riasec_public_projection_v2.module_visibility_policy.policy_id', 'riasec_module_visibility_policy_v1');
        $readback->assertJsonPath('riasec_public_projection_v2.module_visibility_policy.fallback_policy.frontend_inference_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.deep_content_slots_v1.schema_version', 'riasec.deep_content_slots.v1');
        $readback->assertJsonPath('riasec_public_projection_v2.deep_content_slots_v1.source_policy.frontend_fallback_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.deep_content_slots_v1.slot_visibility_policy.frontend_inference_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.deep_content_slots_v1.snapshot_bound', false);
        $this->assertRiasecDeepContentSlots($readback->json('riasec_public_projection_v2.deep_content_slots_v1'), false);
        $readback->assertJsonPath('riasec_public_projection_v2.claim_boundary.does_not_measure.3', 'career_success_probability');
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.schema_version', 'riasec.activity_explorer.v0.1');
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.status', 'content_examples_only');
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.source_status', 'content_example_not_registry_match');
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.boundary.registry_source_connected', false);
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.boundary.fit_score_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.dimension_activity_families.0.dimension', 'R');
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.code_activity_pack.status', 'available');
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.code_activity_pack.activities.0.source_status', 'content_example_not_registry_match');
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.code_activity_pack.activities.0.occupation_examples.0.source_status', 'content_example_not_registry_match');
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.code_activity_pack.activities.0.occupation_examples.0.not_a_recommendation', true);
        $readback->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.code_activity_pack.activities.0.occupation_examples.0.fit_score_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.schema_version', 'riasec.exploration_feedback_overlay.v0.1');
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.status', 'safe_static_content_bridge_v0_1');
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.feedback_stream_status', 'not_connected_v0_1');
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.action_lab_v1.public_raw_feedback_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.next_exploration_nodes_v1.affects_score', false);
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.snapshot_identity.measured_holland_code', 'RIA');
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.snapshot_identity.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.measured_result_guard.scores_mutation_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.measured_result_guard.holland_code_mutation_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.surface_policy.raw_feedback_public_exposure_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.read_model.raw_feedback_included', false);
        $readback->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.schema_version', 'riasec.lifecycle_copy.v1');
        $readback->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.frontend_fallback_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.measured_payload_mutation_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.raw_feedback_public_exposure_allowed', false);
        $readback->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.surfaces.0.surface', 'share_safe_card');
        $this->assertNull($readback->json('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.attempt_id'));

        $report = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $report->assertStatus(200);
        $report->assertJsonPath('ok', true);
        $report->assertJsonPath('locked', false);
        $report->assertJsonPath('report.schema_version', 'riasec.report.v1');
        $report->assertJsonPath('report.top_code', 'RIA');
        $report->assertJsonPath('riasec_public_projection_v1.top_code', 'RIA');
        $report->assertJsonPath('riasec_form_v1.form_code', 'riasec_60');
        $report->assertJsonPath('riasec_form_v1.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $report->assertJsonPath('report._meta.riasec_public_projection_v2.schema_version', 'riasec.public_projection.v2');
        $report->assertJsonPath('riasec_public_projection_v2.measurement_evidence.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $report->assertJsonPath('riasec_public_projection_v2.measurement_evidence.interpretation_rule_version', 'riasec_interpretation_rule_spec_v2');
        $report->assertJsonPath('riasec_public_projection_v2.measurement_evidence.snapshot_bound', true);
        $report->assertJsonPath('riasec_public_projection_v2.interpretation_state.field_authority.reading_strength', 'backend_owned');
        $report->assertJsonPath('riasec_public_projection_v2.module_visibility_policy.policy_id', 'riasec_module_visibility_policy_v1');
        $report->assertJsonPath('riasec_public_projection_v2.deep_content_slots_v1.schema_version', 'riasec.deep_content_slots.v1');
        $report->assertJsonPath('riasec_public_projection_v2.deep_content_slots_v1.snapshot_bound', true);
        $report->assertJsonPath('report._meta.snapshot_binding_v1.deep_content_slots_schema_version', 'riasec.deep_content_slots.v1');
        $this->assertSame(
            $report->json('riasec_public_projection_v2.deep_content_slots_v1'),
            $report->json('report._meta.riasec_public_projection_v2.deep_content_slots_v1')
        );
        $report->assertJsonPath('riasec_public_projection_v2.activity_explorer_v0_1.boundary.occupation_examples_policy', 'content_example_not_registry_match_without_reviewed_registry_source');
        $report->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.snapshot_bound', true);
        $report->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.surface_policy.formal_report_mutation_allowed', false);
        $report->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.claim_boundary.feedback_changes_measured_holland_code', false);
        $report->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.snapshot_bound', true);
        $report->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.internal_snapshot_id_public_exposure_allowed', false);
        $report->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.faq_markdown_reference_available', true);

        $reportAccess = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");
        $reportAccess->assertStatus(200);
        $reportAccess->assertJsonPath('ok', true);
        $reportAccess->assertJsonPath('access_state', 'ready');
        $reportAccess->assertJsonPath('report_state', 'ready');
        $reportAccess->assertJsonPath('payload.access_level', 'full');
        $reportAccess->assertJsonPath('payload.variant', 'full');
        $reportAccess->assertJsonPath('riasec_form_v1.form_code', 'riasec_60');
        $reportAccess->assertJsonPath('riasec_form_v1.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');

        $share = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");
        $share->assertStatus(200);
        $share->assertJsonPath('scale_code', 'RIASEC');
        $share->assertJsonPath('type_code', 'RIA');
        $share->assertJsonPath('riasec_public_projection_v1.top_code', 'RIA');
        $share->assertJsonPath('landing_surface_v1.entry_surface', 'riasec_share_entry');
        $share->assertJsonPath('seo_surface_v1.surface_type', 'riasec_share_public_safe');
        $share->assertJsonPath('answer_surface_v1.surface_type', 'riasec_share_public_safe');
        $share->assertJsonPath('public_surface_v1.entry_surface', 'riasec_share_landing');
        $this->assertStringContainsString('/zh/tests/holland-career-interest-test-riasec', (string) $share->json('primary_cta_path'));
        $this->assertIsArray($share->json('dimensions'));
        $this->assertNotEmpty($share->json('dimensions'));
        $this->assertNull($share->json('mbti_public_projection_v1'));
        $share->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.raw_feedback_public_exposure_allowed', false);
        $share->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.surfaces.0.public_safe', true);

        $history = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/me/attempts?scale=RIASEC');
        $history->assertStatus(200);
        $history->assertJsonPath('ok', true);
        $history->assertJsonPath('scale_code', 'RIASEC');
        $history->assertJsonPath('items.0.attempt_id', $attemptId);
        $history->assertJsonPath('items.0.riasec_form_v1.form_code', 'riasec_60');
        $history->assertJsonPath('items.0.riasec_form_v1.question_count', 60);
        $history->assertJsonPath('items.0.compare_policy_v1.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $history->assertJsonPath('items.0.compare_policy_v1.raw_score_delta_allowed', false);
        $history->assertJsonPath('items.0.riasec_public_projection_v2.lifecycle_copy_v1.measured_payload_mutation_allowed', false);
        $history->assertJsonPath('history_compare.current_compare_policy_v1.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
    }

    public function test_riasec_report_reads_from_snapshot_after_first_formal_report_build(): void
    {
        $this->seedScales();

        $anonId = 'anon_riasec_snapshot_bound';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'RIASEC',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => 'riasec_60',
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $this->answers(60),
            'duration_ms' => 180000,
        ]);
        $submit->assertStatus(200);

        $first = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $first->assertStatus(200);
        $first->assertJsonPath('ok', true);
        $first->assertJsonPath('locked', false);
        $first->assertJsonPath('report.top_code', 'RIA');
        $first->assertJsonPath('report._meta.riasec_public_projection_v2.measurement_evidence.snapshot_bound', true);
        $first->assertJsonPath('report._meta.snapshot_binding_v1.schema_version', 'riasec.snapshot_binding.v1');
        $first->assertJsonPath('report._meta.snapshot_binding_v1.snapshot_bound', true);

        $snapshot = DB::table('report_snapshots')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('ready', (string) ($snapshot->status ?? ''));
        $reportFull = json_decode((string) ($snapshot->report_full_json ?? '{}'), true);
        $this->assertIsArray($reportFull);
        $this->assertSame('RIA', (string) data_get($reportFull, 'top_code'));
        $this->assertTrue((bool) data_get($reportFull, '_meta.riasec_public_projection_v2.measurement_evidence.snapshot_bound'));
        $this->assertSame('riasec.deep_content_slots.v1', (string) data_get($reportFull, '_meta.riasec_public_projection_v2.deep_content_slots_v1.schema_version'));
        $this->assertTrue((bool) data_get($reportFull, '_meta.riasec_public_projection_v2.deep_content_slots_v1.snapshot_bound'));
        $this->assertSame('riasec.deep_content_slots.v1', (string) data_get($reportFull, '_meta.snapshot_binding_v1.deep_content_slots_schema_version'));

        /** @var Result $stored */
        $stored = Result::query()->where('attempt_id', $attemptId)->firstOrFail();
        $resultJson = is_array($stored->result_json) ? $stored->result_json : [];
        data_set($resultJson, 'top_code', 'SEC');
        data_set($resultJson, 'primary_type', 'Social');
        $stored->type_code = 'SEC';
        $stored->result_json = $resultJson;
        $stored->save();

        $second = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report");
        $second->assertStatus(200);
        $second->assertJsonPath('ok', true);
        $second->assertJsonPath('report.top_code', 'RIA');
        $second->assertJsonPath('riasec_public_projection_v2.holland_code.code', 'RIA');
        $second->assertJsonPath('riasec_public_projection_v2.measurement_evidence.snapshot_bound', true);
        $this->assertSame($first->json('report.generated_at'), $second->json('report.generated_at'));
        $this->assertSame(1, DB::table('report_snapshots')->where('attempt_id', $attemptId)->count());

        $share = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");
        $share->assertStatus(200);
        $share->assertJsonPath('type_code', 'RIA');
        $share->assertJsonPath('riasec_public_projection_v1.top_code', 'RIA');
        $share->assertJsonPath('riasec_public_projection_v2.measurement_evidence.snapshot_bound', true);
        $share->assertJsonPath('riasec_snapshot_binding_v1.snapshot_bound', true);
        $share->assertJsonPath('riasec_public_projection_v2.deep_content_slots_v1.snapshot_bound', true);
        $share->assertJsonPath('riasec_public_projection_v2.deep_content_slots_v1.source_policy.frontend_fallback_allowed', false);
        $share->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.read_model.raw_feedback_included', false);
        $share->assertJsonPath('riasec_public_projection_v2.exploration_feedback_overlay_v0_1.surface_policy.share_pdf_exposure_allowed', false);
        $share->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.snapshot_bound', true);
        $share->assertJsonPath('riasec_public_projection_v2.lifecycle_copy_v1.raw_feedback_public_exposure_allowed', false);

        $history = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/me/attempts?scale=RIASEC');
        $history->assertStatus(200);
        $history->assertJsonPath('items.0.type_code', 'RIA');
        $history->assertJsonPath('items.0.riasec_public_projection_v1.top_code', 'RIA');
        $history->assertJsonPath('items.0.riasec_public_projection_v2.measurement_evidence.snapshot_bound', true);
        $history->assertJsonPath('items.0.riasec_snapshot_binding_v1.snapshot_bound', true);
        $history->assertJsonPath('items.0.riasec_public_projection_v2.deep_content_slots_v1.snapshot_bound', true);
        $history->assertJsonPath('items.0.riasec_public_projection_v2.deep_content_slots_v1.slot_visibility_policy.frontend_inference_allowed', false);
        $history->assertJsonPath('items.0.riasec_public_projection_v2.exploration_feedback_overlay_v0_1.measured_result_guard.scores_mutation_allowed', false);
        $history->assertJsonPath('items.0.riasec_public_projection_v2.exploration_feedback_overlay_v0_1.claim_boundary.feedback_is_career_match', false);
        $history->assertJsonPath('items.0.riasec_public_projection_v2.lifecycle_copy_v1.internal_snapshot_id_public_exposure_allowed', false);
        $history->assertJsonPath('history_compare.current_top_code', 'RIA');

        $pdf = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->get("/api/v0.3/attempts/{$attemptId}/report.pdf?inline=1");
        $pdf->assertStatus(200);
        $this->assertSame('riasec.pdf_surface.v1', $pdf->headers->get('X-Pdf-Surface-Version'));
        $this->assertSame('riasec.report.v1', $pdf->headers->get('X-Report-Schema-Version'));
        $this->assertSame('riasec.public_projection.v2', $pdf->headers->get('X-Projection-Version'));
        $this->assertSame('riasec_60', $pdf->headers->get('X-Report-Form-Code'));
        $this->assertSame('false', $pdf->headers->get('X-Cross-Form-Comparable'));
    }

    public function test_riasec_enhanced_140_persists_quality_and_layer_scores(): void
    {
        $this->seedScales();

        $anonId = 'anon_riasec_enhanced';
        $token = $this->issueAnonToken($anonId);

        $start = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'RIASEC',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => '140',
        ]);
        $start->assertStatus(200);
        $start->assertJsonPath('form_code', 'riasec_140');
        $start->assertJsonPath('question_count', 140);

        $attemptId = (string) $start->json('attempt_id');
        $answers = $this->answers(140);

        $submit = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 360000,
        ]);
        $submit->assertStatus(200);
        $submit->assertJsonPath('ok', true);
        $submit->assertJsonPath('result.top_code', 'RIA');
        $submit->assertJsonPath('result.activity_R', 100);
        $submit->assertJsonPath('result.env_R', 100);
        $submit->assertJsonPath('result.role_R', 100);
        $submit->assertJsonPath('result.quality_grade', 'A');

        $stored = Result::query()->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($stored);
        $this->assertSame('riasec_quality_v1', (string) data_get($stored->result_json, 'version_snapshot.quality_rule_version'));

        $readback = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/result");
        $readback->assertStatus(200);
        $readback->assertJsonPath('riasec_public_projection_v2.schema_version', 'riasec.public_projection.v2');
        $readback->assertJsonPath('riasec_public_projection_v2.form.score_space_version', 'riasec_140_likert5_activity_context_space.v1');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.normalization_method', 'activity_environment_role_weighted_0_100');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.quality_rule_version', 'riasec_quality_v1');
        $readback->assertJsonPath('riasec_public_projection_v2.measurement_evidence.quality_rule_status', 'quality_flags_available');
        $this->assertRiasecDeepContentSlots($readback->json('riasec_public_projection_v2.deep_content_slots_v1'), false);
        $slotIds = array_map(
            static fn (array $slot): string => (string) data_get($slot, 'slot_id'),
            (array) $readback->json('riasec_public_projection_v2.deep_content_slots_v1.slots')
        );
        $this->assertContains('140q_task_card_copy:R_task_agreement', $slotIds);
        $this->assertContains('140q_environment_card_copy:R_environment_agreement', $slotIds);
        $this->assertContains('140q_role_card_copy:R_role_agreement', $slotIds);
        $this->assertContains('140q_task_card_copy:task_activity_card', $slotIds);
        $this->assertContains('140q_environment_card_copy:environment_card', $slotIds);
        $this->assertContains('140q_role_card_copy:role_responsibility_card', $slotIds);
        $this->assertContains('structural_difference_copy:summary', $slotIds);
    }

    public function test_riasec_history_compare_guard_blocks_60_and_140_raw_delta(): void
    {
        $this->seedScales();

        $anonId = 'anon_riasec_compare_guard';
        $token = $this->issueAnonToken($anonId);

        $standardStart = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'RIASEC',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => '60',
        ]);
        $standardStart->assertStatus(200);
        $standardAttemptId = (string) $standardStart->json('attempt_id');
        $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $standardAttemptId,
            'answers' => $this->answers(60),
            'duration_ms' => 180000,
        ])->assertStatus(200);

        $this->travel(1)->seconds();

        $enhancedStart = $this->withHeaders(['X-Anon-Id' => $anonId])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'RIASEC',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
            'form_code' => '140',
        ]);
        $enhancedStart->assertStatus(200);
        $enhancedAttemptId = (string) $enhancedStart->json('attempt_id');
        $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $enhancedAttemptId,
            'answers' => $this->answers(140),
            'duration_ms' => 360000,
        ])->assertStatus(200);

        $history = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/me/attempts?scale=RIASEC');
        $history->assertStatus(200);
        $history->assertJsonPath('history_compare.current_attempt_id', $enhancedAttemptId);
        $history->assertJsonPath('history_compare.previous_attempt_id', $standardAttemptId);
        $history->assertJsonPath('history_compare.compare_guard_v1.can_compare', false);
        $history->assertJsonPath('history_compare.compare_guard_v1.reason', 'cross_form_score_space_mismatch');
        $history->assertJsonPath('history_compare.compare_guard_v1.raw_score_delta_allowed', false);
        $this->assertNull($history->json('history_compare.top_code_changed'));
        $history->assertJsonPath('history_compare.current_compare_policy_v1.score_space_version', 'riasec_140_likert5_activity_context_space.v1');
        $history->assertJsonPath('history_compare.previous_compare_policy_v1.score_space_version', 'riasec_60_likert5_activity_sum_space.v1');
        $this->assertNull($history->json('history_compare.raw_scores_delta'));
        $this->assertNull($history->json('history_compare.domains_delta'));

        $this->travelBack();
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    private function assertRiasecDeepContentSlots(mixed $envelope, bool $snapshotBound): void
    {
        $this->assertIsArray($envelope);
        $this->assertSame('riasec.deep_content_slots.v1', (string) data_get($envelope, 'schema_version'));
        $this->assertSame($snapshotBound, (bool) data_get($envelope, 'snapshot_bound'));
        $this->assertFalse((bool) data_get($envelope, 'source_policy.frontend_fallback_allowed', true));
        $this->assertFalse((bool) data_get($envelope, 'slot_visibility_policy.frontend_inference_allowed', true));

        $slots = data_get($envelope, 'slots');
        $this->assertIsArray($slots);
        $this->assertNotEmpty($slots);

        $slotIds = [];
        foreach ($slots as $slot) {
            $this->assertIsArray($slot);
            $this->assertSame('authored', (string) data_get($slot, 'status'));
            $this->assertSame('authored', (string) data_get($slot, 'content_status'));
            $this->assertFalse((bool) data_get($slot, 'frontend_fallback_allowed', true));
            $this->assertContains((string) data_get($slot, 'slot_visibility'), ['visible', 'collapsed']);
            $this->assertNotSame('', (string) data_get($slot, 'content_version'));
            $this->assertNotSame('', (string) data_get($slot, 'review_status'));
            $this->assertNotSame('', (string) data_get($slot, 'source_status'));
            $this->assertNotSame([], data_get($slot, 'content'));
            if ((string) data_get($slot, 'slot_key') === 'dimension_deep_copy') {
                $this->assertNotSame('', (string) data_get($slot, 'content.medium_score_reading'));
            }
            $slotIds[] = (string) data_get($slot, 'slot_id');
        }

        $this->assertContains('dimension_deep_copy:R', $slotIds);
        $this->assertContains('dimension_deep_copy:I', $slotIds);
        $this->assertContains('pair_blend_copy:I_A', $slotIds);
        $this->assertContains('pair_blend_copy:R_I', $slotIds, 'PACK-03 imports 15/15 pair blend slots as backend-authored content.');
        $this->assertContains('triad_blend_copy:R_I_A', $slotIds, 'PACK-04 imports 20/20 top3 activity chain slots as backend-authored content.');

        $serialized = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ([
            'Matches',
            'career recommendation',
            'job fit',
            'success prediction',
            'success probability',
            'hiring suitability',
            '140Q more accurate',
            'raw score delta',
            '60Q wrong',
            '更准确',
            '更准',
            '岗位匹配',
            '职业推荐',
            '职业成功',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    /**
     * @return list<array{question_id:string,code:string}>
     */
    private function answers(int $count): array
    {
        $answers = [];
        for ($qid = 1; $qid <= $count; $qid++) {
            $code = '1';
            if ($qid <= 10 || ($qid >= 61 && $qid <= 72)) {
                $code = '5';
            }
            if ($qid === 133) {
                $code = '3';
            } elseif ($qid === 137) {
                $code = '2';
            } elseif (in_array($qid, [138, 139, 140], true)) {
                $code = '1';
            }

            $answers[] = [
                'question_id' => (string) $qid,
                'code' => $code,
            ];
        }

        return $answers;
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
