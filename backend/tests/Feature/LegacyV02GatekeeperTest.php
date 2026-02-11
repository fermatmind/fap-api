<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Attempt;
use App\Models\ReportJob;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyV02GatekeeperTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_returns_404_for_v02_report_and_result(): void
    {
        config(['features.enable_v0_2_report' => false]);

        [$attemptId, $anonId] = $this->seedFixture(withGrant: true);

        $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report")
            ->assertStatus(404);

        $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/result")
            ->assertStatus(404);
    }

    public function test_flag_on_locked_returns_402_without_sensitive_fields(): void
    {
        config(['features.enable_v0_2_report' => true]);

        [$attemptId, $anonId] = $this->seedFixture(withGrant: false);

        $report = $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report");
        $report->assertStatus(402)
            ->assertJsonPath('error_code', 'PAYMENT_REQUIRED')
            ->assertJsonMissingPath('report');

        $result = $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/result");
        $result->assertStatus(402)
            ->assertJsonPath('error_code', 'PAYMENT_REQUIRED')
            ->assertJsonMissingPath('type_code')
            ->assertJsonMissingPath('scores')
            ->assertJsonMissingPath('scores_pct');
    }

    public function test_flag_on_unlocked_returns_200_with_legacy_shapes(): void
    {
        config(['features.enable_v0_2_report' => true]);

        [$attemptId, $anonId] = $this->seedFixture(withGrant: true);

        $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonStructure([
                'ok',
                'attempt_id',
                'type_code',
                'report',
            ]);

        $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/result")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonStructure([
                'ok',
                'attempt_id',
                'type_code',
                'scores',
                'scores_pct',
            ]);
    }

    private function seedFixture(bool $withGrant): array
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_legacy_gatekeeper_owner';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => '1001',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => ['seed' => true],
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.2.2',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
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
            'profile_version' => 'mbti32-v2.5',
            'content_package_version' => 'v0.2.2',
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        ReportJob::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'status' => 'success',
            'tries' => 1,
            'available_at' => now(),
            'started_at' => now(),
            'finished_at' => now(),
            'report_json' => [
                'profile' => [
                    'type_code' => 'INTJ-A',
                ],
                'highlights' => [],
                'tags' => [],
            ],
            'meta' => [
                'seed' => 'legacy-gatekeeper',
            ],
        ]);

        $this->seedScaleRegistry();

        if ($withGrant) {
            $this->seedBenefitGrant($attemptId, $anonId);
        }

        return [$attemptId, $anonId];
    }

    private function seedScaleRegistry(): void
    {
        DB::table('scales_registry')->insert([
            'code' => 'MBTI',
            'org_id' => 0,
            'primary_slug' => 'mbti-test',
            'slugs_json' => json_encode(['mbti-test'], JSON_UNESCAPED_UNICODE),
            'driver_type' => 'mbti',
            'commercial_json' => json_encode([
                'report_benefit_code' => 'MBTI_REPORT_FULL',
                'credit_benefit_code' => 'MBTI_CREDIT',
            ], JSON_UNESCAPED_UNICODE),
            'is_public' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBenefitGrant(string $attemptId, string $anonId): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'user_id' => $anonId,
            'benefit_code' => 'MBTI_REPORT_FULL',
            'scope' => 'attempt',
            'attempt_id' => $attemptId,
            'status' => 'active',
            'benefit_type' => 'report_unlock',
            'benefit_ref' => $anonId,
            'source_order_id' => (string) Str::uuid(),
            'source_event_id' => null,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
