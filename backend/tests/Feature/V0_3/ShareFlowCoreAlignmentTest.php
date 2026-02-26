<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Commerce\EntitlementManager;
use App\Services\Legacy\LegacyShareFlowService;
use App\Services\V0_3\ShareFlowService;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ShareFlowCoreAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_v03_and_legacy_share_flow_return_aligned_payloads_for_same_input(): void
    {
        $orgId = 123;
        $attemptId = (string) Str::uuid();
        $anonId = 'anon_share_flow_alignment';
        $benefitCode = 'MBTI_REPORT_FULL';

        $this->seedScaleRegistry($orgId, $benefitCode);
        $this->seedAttemptAndResult($orgId, $attemptId, $anonId);

        $ctx = new OrgContext;
        $ctx->set($orgId, null, 'public', $anonId);
        app()->instance(OrgContext::class, $ctx);

        app(EntitlementManager::class)->grantAttemptUnlock(
            $orgId,
            null,
            $anonId,
            $benefitCode,
            $attemptId,
            null
        );

        $input = [
            'experiment' => 'exp_alignment',
            'version' => '1.0.0',
            'channel' => 'miniapp',
            'client_platform' => 'wechat',
            'entry_page' => 'result_page',
        ];

        $v03 = app(ShareFlowService::class)->getShareLinkForAttempt($attemptId, $input);
        $legacy = app(LegacyShareFlowService::class)->getShareLinkForAttempt($attemptId, $input);

        $this->assertSame((string) ($v03['share_id'] ?? ''), (string) ($legacy['share_id'] ?? ''));
        $this->assertSame((string) ($v03['share_url'] ?? ''), (string) ($legacy['share_url'] ?? ''));
        $this->assertSame((string) ($v03['attempt_id'] ?? ''), (string) ($legacy['attempt_id'] ?? ''));
        $this->assertSame((int) ($v03['org_id'] ?? -1), (int) ($legacy['org_id'] ?? -2));
        $this->assertSame((string) ($v03['type_code'] ?? ''), (string) ($legacy['type_code'] ?? ''));
        $this->assertSame((string) ($v03['type_name'] ?? ''), (string) ($legacy['type_name'] ?? ''));

        $shareId = (string) ($v03['share_id'] ?? '');
        $this->assertNotSame('', $shareId);

        $v03View = app(ShareFlowService::class)->getShareView($shareId);
        $legacyView = app(LegacyShareFlowService::class)->getShareView($shareId);

        $this->assertSame((string) ($v03View['share_id'] ?? ''), (string) ($legacyView['share_id'] ?? ''));
        $this->assertSame((string) ($v03View['attempt_id'] ?? ''), (string) ($legacyView['attempt_id'] ?? ''));
        $this->assertSame((int) ($v03View['org_id'] ?? -1), (int) ($legacyView['org_id'] ?? -2));
        $this->assertSame((string) ($v03View['type_code'] ?? ''), (string) ($legacyView['type_code'] ?? ''));
        $this->assertSame((string) ($v03View['type_name'] ?? ''), (string) ($legacyView['type_name'] ?? ''));
    }

    private function seedScaleRegistry(int $orgId, string $benefitCode): void
    {
        $now = now();
        DB::table('scales_registry')->updateOrInsert(
            ['code' => 'MBTI'],
            [
                'org_id' => $orgId,
                'primary_slug' => 'mbti',
                'slugs_json' => json_encode(['mbti'], JSON_UNESCAPED_UNICODE),
                'driver_type' => 'questionnaire',
                'default_pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
                'default_region' => 'CN_MAINLAND',
                'default_locale' => 'zh-CN',
                'default_dir_version' => 'MBTI-CN-v0.3',
                'capabilities_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'view_policy_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'commercial_json' => json_encode(['report_benefit_code' => $benefitCode], JSON_UNESCAPED_UNICODE),
                'seo_schema_json' => json_encode([], JSON_UNESCAPED_UNICODE),
                'is_public' => 1,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    private function seedAttemptAndResult(int $orgId, string $attemptId, string $anonId): void
    {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 1,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => ['total' => 100],
            'is_valid' => true,
            'computed_at' => now(),
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
        ]);
    }
}
