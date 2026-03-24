<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptPublicResultReadHotfixTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    private function createAttempt(string $attemptId, string $scaleCode, string $anonId): void
    {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => "{$scaleCode}.pack",
            'dir_version' => "{$scaleCode}.dir",
            'content_package_version' => 'attempt-v1',
            'scoring_spec_version' => 'attempt-score-v1',
        ]);
    }

    private function createResult(string $attemptId, string $scaleCode, array $overrides = []): void
    {
        Result::create(array_merge([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => [
                'EI' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
            ],
            'scores_pct' => [
                'EI' => 50,
            ],
            'axis_states' => [
                'EI' => 'clear',
            ],
            'content_package_version' => 'result-v1',
            'result_json' => [
                'type_code' => 'INTJ-A',
                'scores' => [
                    'EI' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                ],
                'scores_pct' => [
                    'EI' => 50,
                ],
            ],
            'pack_id' => "{$scaleCode}.result-pack",
            'dir_version' => "{$scaleCode}.result-dir",
            'scoring_spec_version' => 'result-score-v1',
            'report_engine_version' => 'v1.9',
            'is_valid' => true,
            'computed_at' => now(),
        ], $overrides));
    }

    public function test_mbti_anonymous_result_can_be_read_by_attempt_id(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'MBTI', 'anon_mbti_owner');
        $this->createResult($attemptId, 'MBTI');

        $response = $this->withHeader('X-Anon-Id', 'anon_mbti_owner')
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('type_code', 'INTJ-A');
        $response->assertJsonPath('meta.scale_code_legacy', 'MBTI');
        $response->assertJsonPath('meta.pack_id', 'MBTI.result-pack');
        $this->assertStringNotContainsString('No query results for model', (string) $response->getContent());
    }

    public function test_big5_anonymous_result_can_be_read_via_attempt_alias(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'BIG5_OCEAN', 'anon_big5_owner');
        $this->createResult($attemptId, 'BIG5_OCEAN', [
            'type_code' => 'BIG5-READY',
            'result_json' => [
                'type_code' => 'BIG5-READY',
                'traits' => [
                    'O' => 72,
                    'C' => 66,
                ],
            ],
        ]);

        $response = $this->withHeader('X-Anon-Id', 'anon_big5_owner')
            ->getJson("/api/v0.3/attempts/{$attemptId}");

        $response->assertStatus(200);
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('attempt_id', $attemptId);
        $response->assertJsonPath('type_code', 'BIG5-READY');
        $response->assertJsonPath('meta.scale_code_legacy', 'BIG5_OCEAN');
    }

    public function test_public_orphan_result_read_requires_owned_attempt(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createResult($attemptId, 'IQ_RAVEN', [
            'type_code' => 'IQ-RESULT',
            'scores_json' => [
                'raw_score' => 28,
            ],
            'scores_pct' => [],
            'result_json' => [
                'type_code' => 'IQ-RESULT',
                'raw_score' => 28,
                'final_score' => 28,
            ],
            'pack_id' => 'IQ_RAVEN.result-pack',
            'dir_version' => 'IQ_RAVEN.result-dir',
            'content_package_version' => 'result-only-v1',
            'scoring_spec_version' => 'result-only-score-v1',
            'report_engine_version' => 'v2.1',
        ]);

        $response = $this->withHeader('X-Anon-Id', 'anon_orphan_probe')
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
    }

    public function test_sds20_anonymous_result_read_is_still_rejected(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'SDS_20', 'anon_sds_owner');
        $this->createResult($attemptId, 'SDS_20', [
            'type_code' => 'SDS-READY',
        ]);

        $response = $this->withHeader('X-Anon-Id', 'anon_sds_owner')
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $this->assertStringNotContainsString('No query results for model [App\\Models\\Attempt].', (string) $response->getContent());
    }

    public function test_clinical_combo_68_anonymous_result_read_is_still_rejected(): void
    {
        $this->seedScales();

        $attemptId = (string) Str::uuid();
        $this->createAttempt($attemptId, 'CLINICAL_COMBO_68', 'anon_clinical_owner');
        $this->createResult($attemptId, 'CLINICAL_COMBO_68', [
            'type_code' => 'CLINICAL-READY',
        ]);

        $response = $this->withHeader('X-Anon-Id', 'anon_clinical_owner')
            ->getJson("/api/v0.3/attempts/{$attemptId}/result");

        $response->assertStatus(404);
        $response->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
        $this->assertStringNotContainsString('No query results for model [App\\Models\\Attempt].', (string) $response->getContent());
    }
}
