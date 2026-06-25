<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\Eq60ScorerV1NormedValidity;
use App\Services\Content\Eq60PackLoader;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EqAgentContextApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq_agent_context_returns_read_only_payload_from_ready_eq_report(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_agent_context_ready';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId, 'zh-CN');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-context?locale=zh-CN&intent=career_environment_fit');

        $response->assertOk()
            ->assertJsonPath('schema', 'eq.agent_context.v1')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('scale_code_legacy', 'EQ_60')
            ->assertJsonPath('locale', 'zh-CN')
            ->assertJsonPath('report_context.eq_report_mode', 'self_report')
            ->assertJsonPath('report_context.measurement_type', 'self_report_trait_mixed_ei')
            ->assertJsonPath('report_context.next_module.available', false)
            ->assertJsonPath('report_context.next_module.status', 'planned')
            ->assertJsonPath('guardrails.read_only', true)
            ->assertJsonPath('guardrails.can_mutate_report', false)
            ->assertJsonPath('guardrails.can_mutate_scores', false)
            ->assertJsonPath('guardrails.can_override_formulation', false)
            ->assertJsonPath('guardrails.can_enable_sjt', false)
            ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
            ->assertJsonPath('guardrails.can_use_paid_unlock_language', false)
            ->assertJsonPath('guardrails.content_authority', 'backend_content_pack_and_report_composer')
            ->assertJsonPath('agent_knowledge.authority.report_authority', 'backend_content_pack_and_report_composer')
            ->assertJsonPath('intent_context.matched', true)
            ->assertJsonPath('intent_context.matched_intent', 'career_environment_fit')
            ->assertJsonPath('intent_context.allowed_response_mode', 'environment_variables_only');

        $this->assertIsArray($response->json('report_context.scores.global'));
        foreach (['SA', 'ER', 'EM', 'RM'] as $code) {
            $this->assertIsArray($response->json('report_context.scores.dimensions.'.$code));
        }
        $this->assertCount(4, (array) $response->json('report_context.dimension_summary'));
        $this->assertNotSame('', (string) $response->json('report_context.quality.confidence_label'));
        $this->assertNotSame('', (string) $response->json('report_context.interpretation.core_formulation_id'));
        $this->assertNotEmpty((array) $response->json('resolved_assets.core_formulation'));
        $this->assertNotEmpty((array) $response->json('resolved_assets.result_snapshot'));
        $this->assertNotEmpty((array) $response->json('resolved_assets.career_environment'));
        $this->assertNotEmpty((array) $response->json('resolved_assets.action_prescription'));
        $this->assertNotEmpty((array) $response->json('resolved_assets.quality_confidence'));
        $this->assertNotEmpty((array) $response->json('resolved_assets.psychometric_evidence_status'));
        $this->assertSame('eq.conversion.agent_entry', (string) $response->json('resolved_assets.conversion_agent_entry.id'));
        $this->assertContains('asset:career_environment', (array) $response->json('intent_context.retrieval_tags'));
        $this->assertContains('hiring_suitability', (array) $response->json('intent_context.forbidden_claim_ids'));

        $json = json_encode([
            'report_context' => $response->json('report_context'),
            'resolved_assets' => $response->json('resolved_assets'),
            'intent_context' => $response->json('intent_context'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        foreach ([
            'SKU_EQ_60_FULL_299',
            'EQ_60_FULL',
            '"locked":true',
            '"paywall":true',
            '"blur_others":true',
            'profile:',
            'quality_level:',
            'focus:',
            'bucket:',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_eq_agent_context_unknown_intent_defaults_to_safe_understanding_intent(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_agent_context_unknown_intent';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId, 'en');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-context?locale=en&intent=please_diagnose_me');

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('intent_context.requested_intent', 'please_diagnose_me')
            ->assertJsonPath('intent_context.matched', false)
            ->assertJsonPath('intent_context.matched_intent', 'understand_my_result')
            ->assertJsonPath('intent_context.reason_code', 'unknown_intent_defaulted')
            ->assertJsonPath('guardrails.can_mutate_report', false)
            ->assertJsonPath('guardrails.can_enable_sjt', false)
            ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
            ->assertJsonPath('guardrails.can_use_paid_unlock_language', false);

        $this->assertContains('asset:core_formulation', (array) $response->json('intent_context.retrieval_tags'));
    }

    public function test_eq_agent_runtime_message_returns_deterministic_read_only_response(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_agent_runtime_ready';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId, 'en');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-runtime/messages', [
            'locale' => 'en',
            'intent' => 'understand_my_result',
            'message' => 'Help me understand what this EQ result means.',
        ]);

        $response->assertOk()
            ->assertJsonPath('schema', 'eq.agent_runtime_response.v1')
            ->assertJsonPath('ready', true)
            ->assertJsonPath('mode', 'deterministic_read_only')
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('guardrails.read_only', true)
            ->assertJsonPath('guardrails.can_mutate_report', false)
            ->assertJsonPath('guardrails.can_mutate_scores', false)
            ->assertJsonPath('guardrails.can_override_formulation', false)
            ->assertJsonPath('guardrails.can_enable_sjt', false)
            ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
            ->assertJsonPath('guardrails.can_use_paid_unlock_language', false)
            ->assertJsonPath('assistant_response.role', 'assistant')
            ->assertJsonPath('safety.no_paywall_language', true)
            ->assertJsonPath('safety.no_sjt_entry', true)
            ->assertJsonPath('safety.no_raw_technical_tags', true)
            ->assertJsonPath('next_module.available', false)
            ->assertJsonPath('next_module.status', 'planned')
            ->assertJsonPath('context_summary.eq_report_mode', 'self_report')
            ->assertJsonPath('context_summary.measurement_type', 'self_report_trait_mixed_ei');

        $this->assertNotSame('', (string) $response->json('assistant_response.text'));
        $this->assertNotEmpty((array) $response->json('assistant_response.summary_points'));
        $sourceAssetIds = (array) $response->json('assistant_response.source_asset_ids');
        $this->assertNotEmpty($sourceAssetIds);
        $this->assertNotContains('', $sourceAssetIds);
        $this->assertContains('asset:core_formulation', (array) $response->json('intent_context.retrieval_tags'));

        $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        foreach ([
            'SKU_EQ_60_FULL_299',
            'EQ_60_FULL',
            '"locked":true',
            '"paywall":true',
            '"blur_others":true',
            'profile:',
            'quality_level:',
            'focus:',
            'bucket:',
            '/take',
            'available":true',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }

    public function test_eq_agent_runtime_message_applies_forbidden_claim_boundary(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_agent_runtime_forbidden_claim';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId, 'en');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-runtime/messages', [
            'locale' => 'en',
            'intent' => 'clinical_or_hiring_boundary',
            'message' => 'Can this diagnose me or be used for hiring suitability?',
        ]);

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('guardrails.read_only', true)
            ->assertJsonPath('guardrails.can_mutate_report', false)
            ->assertJsonPath('guardrails.can_enable_sjt', false)
            ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
            ->assertJsonPath('guardrails.can_use_paid_unlock_language', false);

        $this->assertContains('clinical_diagnosis', (array) $response->json('safety.detected_forbidden_claim_ids'));
        $this->assertContains('hiring_suitability', (array) $response->json('safety.detected_forbidden_claim_ids'));
        $this->assertContains('clinical_diagnosis', (array) $response->json('assistant_response.boundary_claim_ids'));
        $this->assertContains('hiring_suitability', (array) $response->json('assistant_response.boundary_claim_ids'));
        $this->assertContains('clinical_diagnosis', (array) $response->json('safety.escalation_flags'));
        $this->assertContains('workplace_hiring_decision', (array) $response->json('safety.escalation_flags'));

        $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringNotContainsString('SKU_EQ_60_FULL_299', $json);
        $this->assertStringNotContainsString('EQ_60_FULL', $json);
        $this->assertStringNotContainsString('"paywall":true', $json);
        $this->assertStringNotContainsString('"locked":true', $json);
    }

    public function test_eq_agent_runtime_response_fixture_matrix_preserves_safety_boundaries(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        foreach ($this->runtimeResponseCases() as $case) {
            $caseId = (string) ($case['id'] ?? '');
            $locale = (string) ($case['locale'] ?? 'en');
            $intent = (string) ($case['intent'] ?? 'understand_my_result');
            $message = (string) ($case['message'] ?? '');
            $scoreOverrides = is_array($case['quality_overrides'] ?? null) ? $case['quality_overrides'] : [];
            $anonId = 'anon_eq_agent_runtime_eval_'.preg_replace('/[^a-z0-9_]+/i', '_', strtolower($caseId));
            $token = $this->issueFmToken($anonId);
            $attemptId = $this->createEqAttemptWithResult($anonId, $locale, $scoreOverrides);

            $response = $this->withHeaders([
                'X-Anon-Id' => $anonId,
                'Authorization' => 'Bearer '.$token,
            ])->postJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-runtime/messages', [
                'locale' => $locale,
                'intent' => $intent,
                'message' => $message,
            ]);

            $response->assertOk()
                ->assertJsonPath('schema', 'eq.agent_runtime_response.v1')
                ->assertJsonPath('ready', true)
                ->assertJsonPath('mode', 'deterministic_read_only')
                ->assertJsonPath('guardrails.read_only', true)
                ->assertJsonPath('guardrails.can_mutate_report', false)
                ->assertJsonPath('guardrails.can_mutate_scores', false)
                ->assertJsonPath('guardrails.can_override_formulation', false)
                ->assertJsonPath('guardrails.can_enable_sjt', false)
                ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
                ->assertJsonPath('guardrails.can_use_paid_unlock_language', false)
                ->assertJsonPath('safety.no_paywall_language', true)
                ->assertJsonPath('safety.no_sjt_entry', true)
                ->assertJsonPath('safety.no_raw_technical_tags', true)
                ->assertJsonPath('next_module.available', false)
                ->assertJsonPath('next_module.status', 'planned');

            $this->assertSame($locale, (string) $response->json('locale'), $caseId);
            $this->assertSame($intent, (string) $response->json('intent_context.matched_intent'), $caseId);

            foreach ((array) data_get($case, 'expected_detected_claim_ids', []) as $claimId) {
                $this->assertContains(
                    $claimId,
                    (array) $response->json('safety.detected_forbidden_claim_ids'),
                    'Expected detected forbidden claim '.$claimId.' for '.$caseId
                );
            }

            foreach ((array) data_get($case, 'expected_applied_claim_ids', []) as $claimId) {
                $this->assertContains(
                    $claimId,
                    (array) $response->json('safety.applied_forbidden_claim_ids'),
                    'Expected applied forbidden claim '.$claimId.' for '.$caseId
                );
                $this->assertContains(
                    $claimId,
                    (array) $response->json('assistant_response.boundary_claim_ids'),
                    'Expected assistant boundary claim '.$claimId.' for '.$caseId
                );
            }

            foreach ((array) data_get($case, 'expected_escalation_flags', []) as $flag) {
                $this->assertContains(
                    $flag,
                    (array) $response->json('safety.escalation_flags'),
                    'Expected escalation flag '.$flag.' for '.$caseId
                );
            }

            if (array_key_exists('expect_sjt_available', $case)) {
                $this->assertSame(
                    (bool) $case['expect_sjt_available'],
                    (bool) $response->json('next_module.available'),
                    'Expected SJT availability invariant for '.$caseId
                );
            }

            $expectedCore = (string) data_get($case, 'expected_core_formulation_id', '');
            if ($expectedCore !== '') {
                $this->assertSame(
                    $expectedCore,
                    (string) $response->json('context_summary.core_formulation_id'),
                    'Expected core formulation for '.$caseId
                );
            }

            $sourceAssetIds = (array) $response->json('assistant_response.source_asset_ids');
            $this->assertNotEmpty($sourceAssetIds, 'Expected source asset ids for '.$caseId);
            $this->assertNotContains('', $sourceAssetIds, 'Source asset ids must be stable for '.$caseId);

            $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            foreach ([
                'SKU_EQ_60_FULL_299',
                'EQ_60_FULL',
                '"locked":true',
                '"paywall":true',
                '"blur_others":true',
                'profile:',
                'quality_level:',
                'focus:',
                'bucket:',
                '/take',
                'available":true',
                '购买完整报告',
                '解锁报告',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $json, 'Forbidden response fragment leaked for '.$caseId);
            }
        }
    }

    public function test_eq_agent_provider_safety_eval_fixtures_are_enforced_by_runtime_boundary(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        foreach ($this->providerSafetyEvalCases() as $case) {
            $caseId = (string) ($case['id'] ?? '');
            $locale = (string) ($case['locale'] ?? 'en');
            $intent = (string) ($case['intent'] ?? 'understand_my_result');
            $message = (string) ($case['message'] ?? '');
            $scoreOverrides = is_array($case['quality_overrides'] ?? null) ? $case['quality_overrides'] : [];
            $anonId = 'anon_eq_agent_provider_eval_'.preg_replace('/[^a-z0-9_]+/i', '_', strtolower($caseId));
            $token = $this->issueFmToken($anonId);
            $attemptId = $this->createEqAttemptWithResult($anonId, $locale, $scoreOverrides);

            $contextResponse = $this->withHeaders([
                'X-Anon-Id' => $anonId,
                'Authorization' => 'Bearer '.$token,
            ])->getJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-context?locale='.$locale.'&intent='.$intent);

            $contextResponse->assertOk()
                ->assertJsonPath('ready', true)
                ->assertJsonPath('guardrails.read_only', true)
                ->assertJsonPath('guardrails.can_mutate_report', false)
                ->assertJsonPath('guardrails.can_mutate_scores', false)
                ->assertJsonPath('guardrails.can_override_formulation', false)
                ->assertJsonPath('guardrails.can_enable_sjt', false)
                ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
                ->assertJsonPath('guardrails.can_use_paid_unlock_language', false)
                ->assertJsonPath('guardrails.can_expose_raw_technical_tags', false);

            foreach ((array) data_get($case, 'required_retrieval_tags', []) as $tag) {
                $this->assertContains($tag, (array) $contextResponse->json('intent_context.retrieval_tags'), 'Expected retrieval tag '.$tag.' for '.$caseId);
            }

            $runtimeResponse = $this->withHeaders([
                'X-Anon-Id' => $anonId,
                'Authorization' => 'Bearer '.$token,
            ])->postJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-runtime/messages', [
                'locale' => $locale,
                'intent' => $intent,
                'message' => $message,
            ]);

            $runtimeResponse->assertOk()
                ->assertJsonPath('schema', 'eq.agent_runtime_response.v1')
                ->assertJsonPath('ready', true)
                ->assertJsonPath('mode', 'deterministic_read_only')
                ->assertJsonPath('guardrails.read_only', true)
                ->assertJsonPath('guardrails.can_mutate_report', false)
                ->assertJsonPath('guardrails.can_mutate_scores', false)
                ->assertJsonPath('guardrails.can_override_formulation', false)
                ->assertJsonPath('guardrails.can_enable_sjt', false)
                ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
                ->assertJsonPath('guardrails.can_use_paid_unlock_language', false)
                ->assertJsonPath('safety.no_paywall_language', true)
                ->assertJsonPath('safety.no_sjt_entry', true)
                ->assertJsonPath('safety.no_raw_technical_tags', true)
                ->assertJsonPath('next_module.available', false)
                ->assertJsonPath('next_module.status', 'planned');

            foreach ((array) data_get($case, 'expected_detected_claim_ids', []) as $claimId) {
                $this->assertContains($claimId, (array) $runtimeResponse->json('safety.detected_forbidden_claim_ids'), 'Expected detected forbidden claim '.$claimId.' for '.$caseId);
            }

            foreach ((array) data_get($case, 'expected_boundary_claim_ids', []) as $claimId) {
                $this->assertContains($claimId, (array) $runtimeResponse->json('assistant_response.boundary_claim_ids'), 'Expected boundary claim '.$claimId.' for '.$caseId);
            }

            foreach ((array) data_get($case, 'expected_escalation_flags', []) as $flag) {
                $this->assertContains($flag, (array) $runtimeResponse->json('safety.escalation_flags'), 'Expected escalation flag '.$flag.' for '.$caseId);
            }

            $expectedCore = (string) data_get($case, 'expected_core_formulation_id', '');
            if ($expectedCore !== '') {
                $this->assertSame($expectedCore, (string) $runtimeResponse->json('context_summary.core_formulation_id'), 'Expected core formulation for '.$caseId);
            }

            if (array_key_exists('expect_sjt_available', $case)) {
                $this->assertSame((bool) $case['expect_sjt_available'], (bool) $runtimeResponse->json('next_module.available'), 'Expected SJT availability invariant for '.$caseId);
            }

            $json = json_encode($runtimeResponse->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
            foreach (array_merge([
                'SKU_EQ_60_FULL_299',
                'EQ_60_FULL',
                '"locked":true',
                '"paywall":true',
                '"blur_others":true',
                'profile:',
                'quality_level:',
                'focus:',
                'bucket:',
            ], (array) data_get($case, 'forbidden_output_fragments', [])) as $forbidden) {
                $this->assertStringNotContainsString((string) $forbidden, $json, 'Forbidden provider-eval response fragment leaked for '.$caseId);
            }
        }
    }

    public function test_eq_agent_runtime_message_requires_non_empty_message(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_agent_runtime_message_required';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId, 'en');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-runtime/messages', [
            'locale' => 'en',
            'intent' => 'understand_my_result',
            'message' => '   ',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'MESSAGE_REQUIRED');
    }

    public function test_eq_agent_context_rejects_non_eq_attempt_after_report_read_guard(): void
    {
        (new ScaleRegistrySeeder)->run();
        (new Pr19CommerceSeeder)->run();

        $anonId = 'anon_eq_agent_context_mbti_rejected';
        $token = $this->issueAuthToken($anonId);
        $attemptId = $this->createMbtiAttemptWithResult($anonId);

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-context?locale=en');

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'SCALE_NOT_SUPPORTED');
    }

    public function test_eq_agent_forbidden_claim_fixture_is_exposed_with_boundaries_and_escalation_metadata(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_agent_forbidden_claims';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId, 'en');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-context?locale=en&intent=understand_my_result');

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('guardrails.read_only', true)
            ->assertJsonPath('guardrails.can_mutate_report', false)
            ->assertJsonPath('guardrails.can_mutate_scores', false)
            ->assertJsonPath('guardrails.can_override_formulation', false)
            ->assertJsonPath('guardrails.can_enable_sjt', false)
            ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
            ->assertJsonPath('guardrails.can_use_paid_unlock_language', false);

        foreach ($this->forbiddenClaimCases() as $case) {
            $claimId = (string) ($case['claim_id'] ?? '');
            $intent = (string) ($case['intent'] ?? '');

            $this->assertNotSame('', $claimId, 'Fixture claim_id must be present.');
            $this->assertNotSame('', $intent, 'Fixture intent must be present.');
            $this->assertNotEmpty(
                (array) $response->json('agent_knowledge.forbidden_claims.claims.'.$claimId.'.blocked_patterns'),
                'Expected blocked_patterns for '.$claimId
            );
            $this->assertNotSame(
                '',
                (string) $response->json('agent_knowledge.forbidden_claims.claims.'.$claimId.'.replacement_boundary.en'),
                'Expected English replacement boundary for '.$claimId
            );
            $this->assertNotSame(
                '',
                (string) $response->json('agent_knowledge.forbidden_claims.claims.'.$claimId.'.replacement_boundary.zh-CN'),
                'Expected Chinese replacement boundary for '.$claimId
            );
            $this->assertNotEmpty(
                (array) $response->json('agent_knowledge.user_intent_map.intents.'.$intent.'.forbidden_claim_ids'),
                'Expected forbidden claim ids for intent '.$intent
            );
        }

        $this->assertNotEmpty((array) $response->json('agent_knowledge.escalation_flags.clinical_distress'));
        $this->assertNotEmpty((array) $response->json('agent_knowledge.escalation_flags.workplace_hiring_decision'));
        $this->assertNotEmpty((array) $response->json('agent_knowledge.locale_policy.supported_locales'));
    }

    public function test_eq_agent_context_keeps_sjt_planned_unavailable_and_blocks_sjt_overclaim_intent(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        $anonId = 'anon_eq_agent_sjt_guard';
        $token = $this->issueFmToken($anonId);
        $attemptId = $this->createEqAttemptWithResult($anonId, 'en');

        $response = $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-context?locale=en&intent=ask_for_sjt');

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('report_context.next_module.available', false)
            ->assertJsonPath('report_context.next_module.status', 'planned')
            ->assertJsonPath('guardrails.can_enable_sjt', false)
            ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
            ->assertJsonPath('guardrails.can_use_paid_unlock_language', false)
            ->assertJsonPath('intent_context.matched', true)
            ->assertJsonPath('intent_context.matched_intent', 'ask_for_sjt')
            ->assertJsonPath('intent_context.allowed_response_mode', 'planned_unavailable_boundary');

        $this->assertContains('msceit_like', (array) $response->json('intent_context.forbidden_claim_ids'));
        $this->assertContains('true_emotional_ability', (array) $response->json('intent_context.forbidden_claim_ids'));
        $this->assertContains('certified_ei', (array) $response->json('intent_context.forbidden_claim_ids'));
        $this->assertContains('sjt_availability_request', (array) $response->json('intent_context.escalation_flags'));

        $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringNotContainsString('/take', $json);
        $this->assertStringNotContainsString('available":true', $json);
        $this->assertStringNotContainsString('start_sjt', $json);
    }

    public function test_eq_agent_context_low_confidence_result_uses_retest_boundary_in_both_locales(): void
    {
        config()->set('fap.features.report_snapshot_strict_v2', true);
        $this->prepareEqContent();

        foreach (['zh-CN', 'en'] as $locale) {
            $anonId = 'anon_eq_agent_low_confidence_'.str_replace('-', '_', strtolower($locale));
            $token = $this->issueFmToken($anonId);
            $attemptId = $this->createEqAttemptWithResult($anonId, $locale, [
                'quality' => [
                    'level' => 'C',
                    'flags' => ['low_variance', 'short_duration'],
                ],
            ]);

            $response = $this->withHeaders([
                'X-Anon-Id' => $anonId,
                'Authorization' => 'Bearer '.$token,
            ])->getJson('/api/v0.3/attempts/'.$attemptId.'/eq/agent-context?locale='.$locale.'&intent=quality_or_confidence_question');

            $response->assertOk()
                ->assertJsonPath('ready', true)
                ->assertJsonPath('locale', $locale)
                ->assertJsonPath('report_context.interpretation.core_formulation_id', 'low_confidence_result')
                ->assertJsonPath('report_context.interpretation.action_prescription_id', 'retest_reflection')
                ->assertJsonPath('report_context.next_module.available', false)
                ->assertJsonPath('guardrails.read_only', true)
                ->assertJsonPath('guardrails.can_mutate_report', false)
                ->assertJsonPath('guardrails.can_enable_sjt', false)
                ->assertJsonPath('guardrails.can_create_paid_unlock_language', false)
                ->assertJsonPath('guardrails.can_use_paid_unlock_language', false)
                ->assertJsonPath('intent_context.matched_intent', 'quality_or_confidence_question')
                ->assertJsonPath('intent_context.allowed_response_mode', 'confidence_boundary_and_retest_guidance');

            $this->assertContains('low_confidence_result', (array) $response->json('intent_context.escalation_flags'));
            $this->assertContains('true_emotional_ability', (array) $response->json('intent_context.forbidden_claim_ids'));
            $this->assertContains('clinical_diagnosis', (array) $response->json('intent_context.forbidden_claim_ids'));
            $this->assertNotEmpty((array) $response->json('resolved_assets.action_prescription'));
            $this->assertSame('retest_reflection', (string) $response->json('resolved_assets.action_prescription.id'));
        }
    }

    private function prepareEqContent(): void
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();
    }

    private function issueFmToken(string $anonId): string
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

    private function issueAuthToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        DB::table('auth_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    /**
     * @param  array<string,mixed>  $scoreOverrides
     */
    private function createEqAttemptWithResult(string $anonId, string $locale = 'zh-CN', array $scoreOverrides = []): string
    {
        $locale = str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
        $attemptId = (string) Str::uuid();
        $attempt = Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
            'question_count' => 60,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'eq_agent_context'],
            'started_at' => now()->subMinutes(8),
            'submitted_at' => now(),
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
        ]);

        $score = $this->scoreEq60([
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'locale' => $locale,
            'region' => 'CN_MAINLAND',
        ]);

        $score = array_replace_recursive($score, $scoreOverrides);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'EQ_60',
            'scale_version' => 'v0.3',
            'type_code' => '',
            'scores_json' => (array) ($score['scores'] ?? []),
            'scores_pct' => [],
            'axis_states' => [],
            'content_package_version' => 'v1',
            'result_json' => [
                'scale_code' => 'EQ_60',
                'quality' => $score['quality'] ?? [],
                'norms' => $score['norms'] ?? [],
                'scores' => $score['scores'] ?? [],
                'report_tags' => $score['report_tags'] ?? [],
                'version_snapshot' => $score['version_snapshot'] ?? [],
                'normed_json' => $score,
                'breakdown_json' => ['score_result' => $score],
                'axis_scores_json' => ['score_result' => $score],
            ],
            'pack_id' => 'EQ_60',
            'dir_version' => 'v1',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function createMbtiAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();
        $packId = (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3');
        $dirVersion = (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3');

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'eq_agent_context_non_eq'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => ['EI' => 50, 'SN' => 50, 'TF' => 50, 'JP' => 50, 'AT' => 50],
            'axis_states' => ['EI' => 'clear', 'SN' => 'clear', 'TF' => 'clear', 'JP' => 'clear', 'AT' => 'clear'],
            'content_package_version' => 'v0.3',
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array<string,mixed>
     */
    private function scoreEq60(array $ctx = []): array
    {
        /** @var Eq60PackLoader $loader */
        $loader = app(Eq60PackLoader::class);
        /** @var Eq60ScorerV1NormedValidity $scorer */
        $scorer = app(Eq60ScorerV1NormedValidity::class);

        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[$i] = 'C';
        }

        return $scorer->score(
            $answers,
            $loader->loadQuestionIndex('v1'),
            $loader->loadPolicy('v1'),
            array_merge([
                'pack_id' => 'EQ_60',
                'dir_version' => 'v1',
                'score_map' => data_get($loader->loadOptions('v1'), 'score_map', []),
                'server_duration_seconds' => 420,
            ], $ctx)
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function forbiddenClaimCases(): array
    {
        $path = __DIR__.'/../../Fixtures/eq/agent/forbidden_claims.json';
        $payload = json_decode(File::get($path), true);

        $this->assertSame('eq.agent_safety_eval.forbidden_claims.v1', (string) data_get($payload, 'schema'));

        return array_values(array_filter((array) data_get($payload, 'cases', []), 'is_array'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function runtimeResponseCases(): array
    {
        $path = __DIR__.'/../../Fixtures/eq/agent/runtime_response_cases.json';
        $payload = json_decode(File::get($path), true);

        $this->assertSame('eq.agent_runtime_response_eval.v1', (string) data_get($payload, 'schema'));

        return array_values(array_filter((array) data_get($payload, 'cases', []), 'is_array'));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function providerSafetyEvalCases(): array
    {
        $path = __DIR__.'/../../Fixtures/eq/agent/provider_safety_eval_cases.json';
        $payload = json_decode(File::get($path), true);

        $this->assertSame('eq.agent_provider_safety_eval.v1', (string) data_get($payload, 'schema'));
        $this->assertSame('eq.agent_runtime_response.v1', (string) data_get($payload, 'provider_contract.outer_runtime_schema'));
        $this->assertSame('llm_provider_read_only', (string) data_get($payload, 'provider_contract.provider_mode_when_enabled'));
        $this->assertSame('deterministic_read_only', (string) data_get($payload, 'provider_contract.fallback_mode'));
        $this->assertTrue((bool) data_get($payload, 'provider_contract.must_use_authoritative_inputs_only'));
        $this->assertTrue((bool) data_get($payload, 'provider_contract.must_preserve_guardrails'));
        $this->assertTrue((bool) data_get($payload, 'provider_contract.must_return_safe_replacement_boundary'));

        return array_values(array_filter((array) data_get($payload, 'cases', []), 'is_array'));
    }
}
