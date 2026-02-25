<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Legacy\LegacyShareService;
use App\Services\V0_3\ShareService;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShareScaleIdentityDualWriteTest extends TestCase
{
    use RefreshDatabase;

    private function seedAttemptWithResult(
        string $attemptId,
        string $anonId,
        string $scaleCode,
        ?string $scaleCodeV2 = null,
        ?string $scaleUid = null
    ): void {
        Attempt::create([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_code_v2' => $scaleCodeV2,
            'scale_uid' => $scaleUid,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 1,
            'client_platform' => 'test',
            'answers_summary_json' => ['stage' => 'seed'],
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'type_code' => 'INTJ-A',
            'scores_json' => ['total' => 100],
            'profile_version' => null,
            'is_valid' => true,
            'computed_at' => now(),
            'result_json' => ['type_code' => 'INTJ-A'],
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => 'MBTI-CN-v0.3',
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'scale_code_v2' => $scaleCodeV2,
            'scale_uid' => $scaleUid,
        ]);
    }

    public function test_v03_share_service_dual_mode_writes_share_identity_columns(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        Config::set('scale_identity.write_mode', 'dual');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_share_v03_dual';
        $this->seedAttemptWithResult($attemptId, $anonId, 'MBTI', null, null);

        $ctx = app(OrgContext::class);
        $ctx->set(0, null, 'public', $anonId);
        $data = app(ShareService::class)->getOrCreateShare($attemptId, $ctx);

        $this->assertSame($attemptId, (string) ($data['attempt_id'] ?? ''));
        $this->assertNotSame('', (string) ($data['share_id'] ?? ''));

        $row = DB::table('shares')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($row);
        $this->assertSame('MBTI', (string) ($row->scale_code ?? ''));
        $this->assertSame('MBTI_PERSONALITY_TEST_16_TYPES', (string) ($row->scale_code_v2 ?? ''));
        $this->assertSame('11111111-1111-4111-8111-111111111111', (string) ($row->scale_uid ?? ''));
    }

    public function test_legacy_share_service_legacy_mode_keeps_share_identity_columns_nullable(): void
    {
        $this->artisan('migrate', ['--force' => true]);
        Config::set('scale_identity.write_mode', 'legacy');

        $attemptId = (string) Str::uuid();
        $anonId = 'anon_share_legacy_mode';
        $this->seedAttemptWithResult($attemptId, $anonId, 'IQ_RAVEN', null, null);

        $ctx = app(OrgContext::class);
        $ctx->set(0, null, 'public', $anonId);
        $data = app(LegacyShareService::class)->getOrCreateShare($attemptId, $ctx);

        $this->assertSame($attemptId, (string) ($data['attempt_id'] ?? ''));
        $this->assertNotSame('', (string) ($data['share_id'] ?? ''));

        $row = DB::table('shares')->where('attempt_id', $attemptId)->first();
        $this->assertNotNull($row);
        $this->assertSame('IQ_RAVEN', (string) ($row->scale_code ?? ''));
        $this->assertNull($row->scale_code_v2);
        $this->assertNull($row->scale_uid);
    }
}
