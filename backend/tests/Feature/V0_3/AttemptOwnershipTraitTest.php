<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptOwnershipTraitTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
    }

    private function seedAttemptAndResult(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

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
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'content_package_version' => 'v0.2.1-TEST',
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
                'EI' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                'SN' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                'TF' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                'JP' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                'AT' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
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
            'content_package_version' => 'v0.2.1-TEST',
            'result_json' => [
                'type_code' => 'INTJ-A',
                'scores_json' => [
                    'EI' => ['a' => 10, 'b' => 10, 'sum' => 0, 'total' => 20],
                ],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => 'MBTI-CN-v0.2.1-TEST',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        return $attemptId;
    }

    public function test_report_returns_404_without_auth_and_anon_header(): void
    {
        $this->seedScales();
        $attemptId = $this->seedAttemptAndResult('sec003-owner-anon');

        $this->getJson(route('api.v0_3.attempts.report', ['id' => $attemptId]))
            ->assertStatus(404);
    }

    public function test_report_returns_404_when_anon_header_mismatch(): void
    {
        $this->seedScales();
        $attemptId = $this->seedAttemptAndResult('sec003-owner-anon');

        $this->withHeader('X-Anon-Id', 'sec003-other-anon')
            ->getJson(route('api.v0_3.attempts.report', ['id' => $attemptId]))
            ->assertStatus(404);
    }

    public function test_report_with_matching_anon_header_never_500(): void
    {
        $this->seedScales();
        $attemptId = $this->seedAttemptAndResult('sec003-owner-anon');

        $response = $this->withHeader('X-Anon-Id', 'sec003-owner-anon')
            ->getJson(route('api.v0_3.attempts.report', ['id' => $attemptId]));

        $this->assertContains($response->status(), [200, 402]);

        if ($response->status() === 200) {
            $this->assertIsBool($response->json('locked'));
        }
    }
}
