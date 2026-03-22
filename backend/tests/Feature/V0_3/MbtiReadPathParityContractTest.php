<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Http\Controllers\API\V0_3\AttemptReadController;
use App\Models\Attempt;
use App\Models\Result;
use App\Services\Report\Composer\ReportComposeContext;
use App\Services\Report\Composer\ReportPayloadAssembler;
use App\Support\OrgContext;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiReadPathParityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_read_keeps_canonical_fields_stable_and_limits_overlay_to_contract_fields(): void
    {
        $this->seedScales();
        Config::set('fap_experiments.experiments', []);

        $anonId = 'mbti_read_path_parity_anon';
        $attemptId = $this->createMbtiAttemptWithResult($anonId);
        $this->seedHistoricalMemorySignals($anonId);
        $attempt = Attempt::query()->findOrFail($attemptId);
        $result = Result::query()->where('attempt_id', $attemptId)->firstOrFail();

        $canonicalPayload = app(ReportPayloadAssembler::class)->assemble(
            ReportComposeContext::fromAttempt($attempt, $result, [
                'org_id' => 0,
                'variant' => 'free',
                'report_access_level' => 'free',
                'modules_allowed' => [],
                'modules_preview' => [],
                'persist' => false,
                'strict' => false,
                'explain' => false,
            ])
        );

        $this->assertTrue((bool) ($canonicalPayload['ok'] ?? false));
        $canonicalPersonalization = (array) data_get($canonicalPayload, 'report._meta.personalization', []);
        $this->assertSame('mbti.read_contract.v1', data_get($canonicalPersonalization, 'read_contract_v1.version'));

        $this->seedOverlaySignals($attemptId, $anonId);
        $response = $this->invokeController('report', $attemptId, $anonId);

        $this->assertSame(200, $response->getStatusCode());

        $effectiveReportPersonalization = (array) $response->getData(true)['report']['_meta']['personalization'];
        $effectiveProjectionPersonalization = (array) $response->getData(true)['mbti_public_projection_v1']['_meta']['personalization'];
        $readContract = (array) data_get($effectiveReportPersonalization, 'read_contract_v1', []);
        $privacyContract = (array) data_get($effectiveReportPersonalization, 'privacy_contract_v1', []);

        $this->assertSame('mbti.read_contract.v1', $readContract['version'] ?? null);
        $this->assertSame($readContract, (array) ($response->getData(true)['mbti_read_contract_v1'] ?? []));
        $this->assertSame('mbti.privacy_contract.v1', $privacyContract['version'] ?? null);
        $this->assertSame($privacyContract, (array) ($response->getData(true)['mbti_privacy_contract_v1'] ?? []));

        foreach ((array) data_get($readContract, 'canonical_read_model.personalization_fields', []) as $field) {
            if ($field === 'narrative_runtime_contract_v1') {
                $this->assertEquals(
                    data_get($canonicalPersonalization, $field),
                    data_get($effectiveReportPersonalization, $field),
                    'canonical field drifted during read overlay: '.$field
                );

                continue;
            }

            $this->assertSame(
                data_get($canonicalPersonalization, $field),
                data_get($effectiveReportPersonalization, $field),
                'canonical field drifted during read overlay: '.$field
            );
        }

        foreach (array_merge(
            (array) data_get($readContract, 'canonical_read_model.personalization_fields', []),
            (array) data_get($readContract, 'overlay_patch.personalization_fields', [])
        ) as $field) {
            $this->assertSame(
                data_get($effectiveReportPersonalization, $field),
                data_get($effectiveProjectionPersonalization, $field),
                'report/projection parity mismatch: '.$field
            );
        }

        $this->assertSame(false, data_get($canonicalPersonalization, 'user_state.has_feedback'));
        $this->assertSame(true, data_get($effectiveReportPersonalization, 'user_state.has_feedback'));
        $this->assertSame(true, data_get($effectiveReportPersonalization, 'user_state.has_share'));
        $this->assertSame(true, data_get($effectiveReportPersonalization, 'user_state.has_action_engagement'));
        $this->assertSame('mbti.intra_type_profile.v1', data_get($effectiveReportPersonalization, 'intra_type_profile_v1.version'));
        $this->assertSame('mbti.longitudinal_memory.v1', data_get($effectiveReportPersonalization, 'longitudinal_memory_v1.memory_contract_version'));
        $this->assertSame('mbti.adaptive_selection.v1', data_get($effectiveReportPersonalization, 'adaptive_selection_v1.adaptive_contract_version'));
        $this->assertSame('mbti.tone_profile.v1', data_get($effectiveReportPersonalization, 'tone_profile_v1.tone_contract_version'));
        $this->assertNotSame('', trim((string) data_get($effectiveReportPersonalization, 'tone_profile_v1.tone_fingerprint')));
        $this->assertNotSame('', trim((string) data_get($effectiveReportPersonalization, 'tone_profile_v1.default_tone_mode')));
        $this->assertIsArray(data_get($effectiveReportPersonalization, 'tone_profile_v1.section_tone_modes'));
        $this->assertNotSame('', trim((string) data_get($effectiveReportPersonalization, 'adaptive_selection_v1.adaptive_fingerprint')));
        $this->assertNotSame('', trim((string) data_get($effectiveReportPersonalization, 'longitudinal_memory_v1.memory_fingerprint')));
        $this->assertIsArray(data_get($effectiveReportPersonalization, 'longitudinal_memory_v1.section_history_keys'));
        $this->assertNotSame('', trim((string) data_get($effectiveReportPersonalization, 'profile_seed_key')));
        $this->assertNotSame('', trim((string) data_get($effectiveReportPersonalization, 'selection_fingerprint')));
        $this->assertIsArray(data_get($effectiveReportPersonalization, 'section_selection_keys'));
        $this->assertIsArray(data_get($effectiveReportPersonalization, 'action_selection_keys'));
        $this->assertIsArray(data_get($effectiveReportPersonalization, 'recommendation_selection_keys'));
        $this->assertContains(
            'report._meta.personalization.user_state',
            (array) data_get($readContract, 'non_cacheable_fields', [])
        );
        $this->assertContains(
            'mbti_public_projection_v1._meta.personalization.continuity',
            (array) data_get($readContract, 'non_cacheable_fields', [])
        );
        $this->assertContains(
            'report._meta.personalization.selection_fingerprint',
            (array) data_get($readContract, 'non_cacheable_fields', [])
        );
        $this->assertContains(
            'report._meta.personalization.longitudinal_memory_v1',
            (array) data_get($readContract, 'non_cacheable_fields', [])
        );
        $this->assertContains(
            'report._meta.personalization.adaptive_selection_v1',
            (array) data_get($readContract, 'non_cacheable_fields', [])
        );
        $this->assertContains(
            'profile_seed_key',
            (array) data_get($readContract, 'telemetry_parity_fields', [])
        );
        $this->assertContains(
            'longitudinal_memory_v1.memory_fingerprint',
            (array) data_get($readContract, 'telemetry_parity_fields', [])
        );
        $this->assertContains(
            'adaptive_selection_v1.adaptive_fingerprint',
            (array) data_get($readContract, 'telemetry_parity_fields', [])
        );
        $this->assertContains(
            'tone_profile_v1.tone_fingerprint',
            (array) data_get($readContract, 'telemetry_parity_fields', [])
        );
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
    }

    private function createMbtiAttemptWithResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
                'SN' => 50,
                'TF' => 50,
                'JP' => 50,
                'AT' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'clear',
                'AT' => 'clear',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'raw_score' => 0,
                'final_score' => 0,
                'breakdown_json' => [],
                'type_code' => 'INTJ-A',
                'axis_scores_json' => [
                    'scores_pct' => [
                        'EI' => 50,
                        'SN' => 50,
                        'TF' => 50,
                        'JP' => 50,
                        'AT' => 50,
                    ],
                    'axis_states' => [
                        'EI' => 'clear',
                        'SN' => 'clear',
                        'TF' => 'clear',
                        'JP' => 'clear',
                        'AT' => 'clear',
                    ],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'report_phase4a_contract',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    private function invokeController(string $method, string $attemptId, string $anonId): \Illuminate\Http\JsonResponse
    {
        $path = "/api/v0.3/attempts/{$attemptId}/" . ($method === 'report' ? 'report' : 'result');
        $request = Request::create($path, 'GET');
        $request->headers->set('X-Anon-Id', $anonId);
        $request->attributes->set('anon_id', $anonId);
        $request->attributes->set('org_context_resolved', true);
        $request->attributes->set('org_context_kind', OrgContext::KIND_PUBLIC);

        $this->app->instance('request', $request);
        app(OrgContext::class)->set(0, null, 'public', $anonId, OrgContext::KIND_PUBLIC);

        /** @var AttemptReadController $controller */
        $controller = app(AttemptReadController::class);

        return $controller->{$method}($request, $attemptId);
    }

    private function seedOverlaySignals(string $attemptId, string $anonId): void
    {
        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'accuracy_feedback',
                'event_name' => 'accuracy_feedback',
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.stability_confidence',
                    'feedback' => 'unclear',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(10),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'meta_json' => json_encode([
                    'sectionKey' => 'traits.close_call_axes',
                    'interaction' => 'dwell_2500ms',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(9),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 0,
                'attempt_id' => $attemptId,
                'meta_json' => json_encode([
                    'sectionKey' => 'growth.next_actions',
                    'actionKey' => 'weekly_action.theme.name_decision_rule',
                    'interaction' => 'click',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subMinutes(8),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('shares')->insert([
            'id' => (string) Str::uuid(),
            'attempt_id' => $attemptId,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'content_package_version' => 'v0.3',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedHistoricalMemorySignals(string $anonId): void
    {
        $previousAttemptId = (string) Str::uuid();

        Attempt::create([
            'id' => $previousAttemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'scale_code_v2' => 'MBTI',
            'scale_uid' => 'mbti',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'history'],
            'started_at' => now()->subDays(9),
            'submitted_at' => now()->subDays(9),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
        ]);

        DB::table('events')->insert([
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'result_view',
                'event_name' => 'result_view',
                'org_id' => 0,
                'attempt_id' => $previousAttemptId,
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => null,
                'occurred_at' => now()->subDays(9),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'event_code' => 'ui_card_interaction',
                'event_name' => 'ui_card_interaction',
                'org_id' => 0,
                'attempt_id' => $previousAttemptId,
                'anon_id' => $anonId,
                'scale_code' => 'MBTI',
                'meta_json' => json_encode([
                    'sectionKey' => 'traits.close_call_axes',
                    'interaction' => 'dwell_2500ms',
                    'continueTarget' => 'type_clarity',
                ], JSON_UNESCAPED_UNICODE),
                'occurred_at' => now()->subDays(9)->addMinutes(6),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}
