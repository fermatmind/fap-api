<?php

declare(strict_types=1);

namespace Tests\Feature\V0_2;

use App\Models\Attempt;
use App\Models\ReportJob;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AttemptReportPaywallGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_returns_402_when_owner_has_no_benefit_grant(): void
    {
        [$attemptId, $anonId] = $this->seedReportFixture();

        $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report")
            ->assertStatus(402)
            ->assertJson([
                'ok' => false,
                'error_code' => 'PAYMENT_REQUIRED',
                'message' => 'report locked',
            ]);
    }

    public function test_report_returns_200_when_owner_has_benefit_grant(): void
    {
        [$attemptId, $anonId] = $this->seedReportFixture();
        $this->seedBenefitGrant(0, $attemptId, $anonId);

        $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report")
            ->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'attempt_id' => $attemptId,
            ]);
    }

    public function test_report_with_share_id_without_owner_identity_returns_404(): void
    {
        [$attemptId, $anonId] = $this->seedReportFixture();
        $shareId = (string) Str::uuid();

        DB::table('shares')->insert([
            'id' => $shareId,
            'attempt_id' => $attemptId,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.2',
            'content_package_version' => 'v0.2.2',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson("/api/v0.2/attempts/{$attemptId}/report?share_id={$shareId}")
            ->assertStatus(404);
    }

    public function test_report_returns_404_when_feature_flag_disabled(): void
    {
        config(['features.enable_v0_2_report' => false]);

        [$attemptId, $anonId] = $this->seedReportFixture();
        $this->seedBenefitGrant(0, $attemptId, $anonId);

        $this->withHeader('X-Anon-Id', $anonId)
            ->getJson("/api/v0.2/attempts/{$attemptId}/report")
            ->assertStatus(404);
    }

    public function test_share_returns_404_when_feature_flag_disabled(): void
    {
        config(['features.enable_v0_2_report' => false]);

        [$attemptId, $anonId] = $this->seedReportFixture();
        $token = $this->seedFmToken($anonId, '1001', 0);
        $this->seedBenefitGrant(0, $attemptId, $anonId);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'X-Org-Id' => '0',
        ])->getJson("/api/v0.2/attempts/{$attemptId}/share")
            ->assertStatus(404);
    }

    private function seedReportFixture(): array
    {
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_paywall_owner';
        $userId = '1001';

        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => $userId,
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
                'seed' => 'paywall',
            ],
        ]);

        $this->seedScaleRegistry(0, 'MBTI');

        return [$attemptId, $anonId, $userId];
    }

    private function seedScaleRegistry(int $orgId, string $scaleCode): void
    {
        DB::table('scales_registry')->insert([
            'code' => $scaleCode,
            'org_id' => $orgId,
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

    private function seedBenefitGrant(int $orgId, string $attemptId, string $anonId): void
    {
        DB::table('benefit_grants')->insert([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
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

    private function seedFmToken(string $anonId, string $userId, int $orgId): string
    {
        $token = 'fm_'.(string) Str::uuid();

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
