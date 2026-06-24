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
            ->assertJsonPath('guardrails.can_enable_sjt', false);

        $this->assertContains('asset:core_formulation', (array) $response->json('intent_context.retrieval_tags'));
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

    private function createEqAttemptWithResult(string $anonId, string $locale = 'zh-CN'): string
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
}
