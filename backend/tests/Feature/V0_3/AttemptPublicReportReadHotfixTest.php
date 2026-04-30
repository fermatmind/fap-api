<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptPublicReportReadHotfixTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
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

    private function createAttempt(string $attemptId, string $scaleCode, string $anonId, string $formCode = 'mbti_144'): void
    {
        $isMbti = $scaleCode === 'MBTI';
        $isMbti93 = $isMbti && $formCode === 'mbti_93';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => $isMbti ? ($isMbti93 ? 93 : 144) : 20,
            'client_platform' => 'test',
            'answers_summary_json' => $isMbti ? ['stage' => 'seed', 'meta' => ['form_code' => $formCode]] : ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id') : "{$scaleCode}.pack",
            'dir_version' => $isMbti ? ($isMbti93 ? 'MBTI-CN-v0.3-form-93' : 'MBTI-CN-v0.3') : "{$scaleCode}.dir",
            'content_package_version' => $isMbti ? ($isMbti93 ? 'v0.3-form-93' : 'v0.3') : 'attempt-v1',
            'scoring_spec_version' => $isMbti ? ($isMbti93 ? '2026.01.mbti_93' : '2026.01.mbti_144') : 'attempt-score-v1',
        ]);
    }

    private function createResult(string $attemptId, string $scaleCode, string $formCode = 'mbti_144'): void
    {
        $isMbti = $scaleCode === 'MBTI';
        $isMbti93 = $isMbti && $formCode === 'mbti_93';

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => $isMbti ? 'INTJ-A' : 'SDS-READY',
            'scores_json' => $isMbti
                ? [
                    'EI' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'SN' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'TF' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'JP' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                    'AT' => ['a' => 10, 'b' => 10, 'neutral' => 0, 'sum' => 0, 'total' => 20],
                ]
                : ['total' => 42],
            'scores_pct' => $isMbti
                ? [
                    'EI' => 50,
                    'SN' => 50,
                    'TF' => 50,
                    'JP' => 50,
                    'AT' => 50,
                ]
                : [],
            'axis_states' => $isMbti
                ? [
                    'EI' => 'clear',
                    'SN' => 'clear',
                    'TF' => 'clear',
                    'JP' => 'clear',
                    'AT' => 'clear',
                ]
                : [],
            'content_package_version' => $isMbti ? ($isMbti93 ? 'v0.3-form-93' : 'v0.3') : 'result-v1',
            'result_json' => $isMbti
                ? [
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
                    'meta' => ['form_code' => $formCode],
                ]
                : [
                    'scale_code' => 'SDS_20',
                    'scores' => ['total' => 42],
                    'quality' => ['level' => 'ok'],
                    'report_tags' => [],
                    'sections' => [],
                ],
            'pack_id' => $isMbti ? (string) config('content_packs.default_pack_id') : "{$scaleCode}.pack",
            'dir_version' => $isMbti ? ($isMbti93 ? 'MBTI-CN-v0.3-form-93' : 'MBTI-CN-v0.3') : "{$scaleCode}.dir",
            'scoring_spec_version' => $isMbti ? ($isMbti93 ? '2026.01.mbti_93' : '2026.01.mbti_144') : 'result-score-v1',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);
    }

    public function test_public_mbti_report_requires_matching_attempt_subject(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);

        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_mbti_owner';
        $viewerAnonId = 'anon_mbti_viewer';
        $token = $this->issueAnonToken($viewerAnonId);
        $this->createAttempt($attemptId, 'MBTI', $ownerAnonId);
        $this->createResult($attemptId, 'MBTI');

        $response = $this->withHeaders([
            'X-Anon-Id' => $viewerAnonId,
            'Authorization' => 'Bearer '.$token,
        ])
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ATTEMPT_NOT_FOUND');
        $response->assertJsonPath('message', 'attempt not found.');
        $this->assertStringNotContainsString('No query results for model', (string) $response->getContent());
    }

    public function test_public_mbti_report_access_requires_matching_attempt_subject(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_access_owner';
        $viewerAnonId = 'anon_access_viewer';
        $token = $this->issueAnonToken($viewerAnonId);
        $this->createAttempt($attemptId, 'MBTI', $ownerAnonId);
        $this->createResult($attemptId, 'MBTI');

        $response = $this->withHeaders([
            'X-Anon-Id' => $viewerAnonId,
            'Authorization' => 'Bearer '.$token,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/report-access");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ATTEMPT_NOT_FOUND');
        $response->assertJsonPath('message', 'attempt not found.');
    }

    public function test_public_mbti_report_rejects_orphan_result_with_explicit_attempt_not_found_contract(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createResult($attemptId, 'MBTI');

        $response = $this->withHeader('X-Anon-Id', 'anon_orphan_probe')
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'ATTEMPT_NOT_FOUND');
        $response->assertJsonPath('message', 'attempt not found.');
        $this->assertStringNotContainsString('No query results for model', (string) $response->getContent());
    }

    public function test_public_mbti_report_reads_public_result_without_actor_when_attempt_and_result_exist(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'anon_mbti_artifact_owner');
        $this->createResult($attemptId, 'MBTI');

        $response = $this->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('locked', true);
        $response->assertJsonPath('access_level', 'free');
        $response->assertJsonPath('variant', 'free');
        $response->assertJsonPath('scale_code', 'MBTI');
        $response->assertJsonPath('meta.scale_code', 'MBTI');
        $response->assertJsonPath('mbti_form_v1.form_code', 'mbti_144');
        $response->assertJsonPath('mbti_form_v1.question_count', 144);
        $response->assertJsonPath('mbti_form_v1.scale_code', 'MBTI');
        $this->assertStringNotContainsString('ATTEMPT_NOT_FOUND', (string) $response->getContent());
    }

    public function test_public_mbti_report_fallback_does_not_inherit_paid_attempt_grants_without_actor(): void
    {
        $this->seedScales();
        config()->set('fap.features.report_snapshot_strict_v2', false);

        $attemptId = (string) Str::uuid();
        $ownerAnonId = 'anon_mbti_paid_artifact_owner';
        $this->createAttempt($attemptId, 'MBTI', $ownerAnonId);
        $this->createResult($attemptId, 'MBTI');

        /** @var EntitlementManager $entitlements */
        $entitlements = app(EntitlementManager::class);
        $grant = $entitlements->grantAttemptUnlock(
            0,
            null,
            $ownerAnonId,
            'MBTI_REPORT_FULL',
            $attemptId,
            null,
            'attempt',
            null,
            ['core_full', 'career', 'relationships']
        );
        $this->assertTrue((bool) ($grant['ok'] ?? false));

        $response = $this->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('locked', true);
        $response->assertJsonPath('access_level', 'free');
        $response->assertJsonPath('variant', 'free');
        $response->assertJsonPath('unlock_stage', 'locked');
        $this->assertSame(['core_free'], $response->json('modules_allowed'));
        $this->assertNotContains('core_full', (array) $response->json('modules_allowed'));
        $this->assertNotContains('career', (array) $response->json('modules_allowed'));
        $this->assertNotContains('relationships', (array) $response->json('modules_allowed'));
    }

    public function test_sds20_report_still_requires_existing_ownership_chain(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'SDS_20', 'anon_sds_owner');
        $this->createResult($attemptId, 'SDS_20');

        $response = $this->withHeader('X-Anon-Id', 'anon_sds_probe')
            ->getJson("/api/v0.3/attempts/{$attemptId}/report");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $response->assertJsonPath('message', 'attempt not found.');
        $this->assertStringNotContainsString('No query results for model', (string) $response->getContent());
    }
}
