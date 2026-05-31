<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class Eq60JourneyStateContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_eq_journey_feedback_requires_consent_to_persist_and_does_not_mutate_scores_or_memory(): void
    {
        $anonId = 'anon_eq_journey_state';
        [$attemptId, $token] = $this->createSubmittedEqAttempt($anonId);
        $this->patchResultJson($attemptId, [
            'quality' => ['level' => 'A', 'confidence_label' => 'high'],
            'interpretation' => [
                'core_formulation_id' => 'high_empathy_low_recovery',
                'route_id' => 'route.high_empathy_low_recovery.v1',
            ],
            'scores' => ['global' => ['standard_score' => 104]],
        ]);
        $before = DB::table('results')->where('attempt_id', $attemptId)->value('result_json');

        $initial = $this->auth($anonId, $token)->getJson("/api/v0.3/attempts/{$attemptId}/eq/journey");
        $initial->assertOk()
            ->assertJsonPath('eq_journey_state_v1.version', 'eq_journey_state.v1')
            ->assertJsonPath('eq_journey_state_v1.status', 'initial_result')
            ->assertJsonPath('eq_journey_state_v1.persisted', false)
            ->assertJsonPath('eq_journey_state_v1.interpretation_guard.affects_scores', false)
            ->assertJsonPath('eq_journey_state_v1.interpretation_guard.profile_memory_write', false);

        $transient = $this->auth($anonId, $token)->postJson("/api/v0.3/attempts/{$attemptId}/eq/journey", [
            'consent_to_store' => false,
            'read_depth' => 'action',
            'result_resonance' => 'partial',
            'action_completion' => 'started',
            'retest_intent' => 'after_practice',
            'source_surface' => 'result_page',
            'primary_action_id' => 'empathy_boundary',
            'selected_scene_ids' => ['feedback', 'conflict'],
        ]);

        $transient->assertOk()
            ->assertJsonPath('eq_journey_state_v1.persisted', false)
            ->assertJsonPath('eq_journey_state_v1.consent.required_for_persistence', true)
            ->assertJsonPath('eq_journey_state_v1.consent.consent_to_store', false)
            ->assertJsonPath('eq_journey_state_v1.signals.action_completion', 'started')
            ->assertJsonPath('eq_journey_state_v1.interpretation_guard.formal_report_mutation_allowed', false)
            ->assertJsonPath('eq_journey_state_v1.interpretation_guard.raw_feedback_public_exposure_allowed', false);

        $this->assertDatabaseCount('eq_journey_states', 0);
        $this->assertDatabaseCount('memories', 0);
        $this->assertSame($before, DB::table('results')->where('attempt_id', $attemptId)->value('result_json'));

        $stored = $this->auth($anonId, $token)->postJson("/api/v0.3/attempts/{$attemptId}/eq/journey", [
            'consent_to_store' => true,
            'read_depth' => 'complete',
            'result_resonance' => 'strong',
            'action_completion' => 'completed',
            'retest_intent' => 'after_practice',
            'source_surface' => 'result_page',
            'primary_action_id' => 'empathy_boundary',
            'selected_scene_ids' => ['feedback', 'conflict'],
        ]);

        $stored->assertOk()
            ->assertJsonPath('eq_journey_state_v1.persisted', true)
            ->assertJsonPath('eq_journey_state_v1.status', 'action_completed')
            ->assertJsonPath('eq_journey_state_v1.signals.read_depth', 'complete')
            ->assertJsonPath('eq_journey_state_v1.signals.action_completion', 'completed')
            ->assertJsonPath('eq_journey_state_v1.suggested_next_action', 'schedule_retest_after_practice');

        $this->assertDatabaseHas('eq_journey_states', [
            'attempt_id' => $attemptId,
            'scale_code' => 'EQ_60',
            'status' => 'action_completed',
            'consent_to_store' => true,
            'result_resonance' => 'strong',
            'action_completion' => 'completed',
        ]);
        $this->assertDatabaseHas('events', [
            'event_code' => 'eq_journey_feedback_submitted',
            'attempt_id' => $attemptId,
        ]);
        $this->assertDatabaseCount('memories', 0);
        $this->assertSame($before, DB::table('results')->where('attempt_id', $attemptId)->value('result_json'));
    }

    public function test_low_confidence_eq_journey_keeps_caution_even_when_user_reports_strong_resonance(): void
    {
        $anonId = 'anon_eq_journey_low_confidence';
        [$attemptId, $token] = $this->createSubmittedEqAttempt($anonId);
        $this->patchResultJson($attemptId, [
            'quality' => ['level' => 'D', 'confidence_label' => 'low'],
            'interpretation' => [
                'core_formulation_id' => 'low_confidence_result',
                'route_id' => 'route.low_confidence_result.v1',
            ],
            'scores' => ['global' => ['standard_score' => 101]],
        ]);

        $response = $this->auth($anonId, $token)->postJson("/api/v0.3/attempts/{$attemptId}/eq/journey", [
            'consent_to_store' => true,
            'read_depth' => 'complete',
            'result_resonance' => 'strong',
            'action_completion' => 'completed',
            'retest_intent' => 'soon',
        ]);

        $response->assertOk()
            ->assertJsonPath('eq_journey_state_v1.status', 'low_confidence_reflection')
            ->assertJsonPath('eq_journey_state_v1.basis.core_formulation_id', 'low_confidence_result')
            ->assertJsonPath('eq_journey_state_v1.interpretation_guard.low_confidence_caution', true)
            ->assertJsonPath('eq_journey_state_v1.interpretation_guard.affects_scores', false)
            ->assertJsonPath('eq_journey_state_v1.suggested_next_action', 'retest_reflection');

        $this->assertDatabaseHas('eq_journey_states', [
            'attempt_id' => $attemptId,
            'status' => 'low_confidence_reflection',
            'quality_level' => 'D',
            'confidence_label' => 'low',
        ]);
    }

    public function test_eq_journey_rejects_non_eq_attempts(): void
    {
        $anonId = 'anon_eq_journey_wrong_scale';
        [$attemptId, $token] = $this->createSubmittedEqAttempt($anonId);

        DB::table('attempts')->where('id', $attemptId)->update([
            'scale_code' => 'MBTI',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'updated_at' => now(),
        ]);
        DB::table('results')->where('attempt_id', $attemptId)->update([
            'scale_code' => 'MBTI',
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'result_json' => json_encode([
                'scale_code' => 'MBTI',
                'scores' => ['type_code' => 'INTJ'],
            ]),
            'updated_at' => now(),
        ]);

        $this->auth($anonId, $token)
            ->postJson("/api/v0.3/attempts/{$attemptId}/eq/journey", [
                'consent_to_store' => true,
                'read_depth' => 'complete',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'SCALE_NOT_SUPPORTED');
    }

    private function auth(string $anonId, string $token): self
    {
        return $this->withHeaders([
            'X-Anon-Id' => $anonId,
            'Authorization' => 'Bearer '.$token,
        ]);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function createSubmittedEqAttempt(string $anonId): array
    {
        $this->artisan('content:compile --pack=EQ_60 --pack-version=v1')->assertExitCode(0);
        (new ScaleRegistrySeeder)->run();

        $token = $this->issueFmToken($anonId);

        $start = $this->withHeaders([
            'X-Anon-Id' => $anonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'EQ_60',
            'anon_id' => $anonId,
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
        ]);

        $start->assertOk();

        $attemptId = (string) $start->json('attempt_id');
        $answers = [];
        for ($i = 1; $i <= 60; $i++) {
            $answers[] = [
                'question_id' => (string) $i,
                'code' => 'C',
            ];
        }

        $submit = $this->auth($anonId, $token)->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 160000,
        ]);

        $submit->assertOk()->assertJsonPath('ok', true);

        return [$attemptId, $token];
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function patchResultJson(string $attemptId, array $overrides): void
    {
        $raw = DB::table('results')->where('attempt_id', $attemptId)->value('result_json');
        $current = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $merged = array_replace_recursive($current, ['scale_code' => 'EQ_60'], $overrides);

        DB::table('results')->where('attempt_id', $attemptId)->update([
            'scores_json' => json_encode((array) ($merged['scores'] ?? [])),
            'result_json' => json_encode($merged),
            'updated_at' => now(),
        ]);
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
}
