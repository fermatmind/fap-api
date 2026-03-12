<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\Attempt;
use App\Models\Order;
use App\Models\Result;
use App\Services\Attempts\AttemptSubmitSideEffects;
use App\Services\Commerce\OrderManager;
use App\Support\OrgContext;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MbtiCompareInviteAttributionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_submit_checkout_and_paid_flow_persist_compare_invite_attribution(): void
    {
        $this->seedScales();
        $this->seedSku();

        [$inviterAttemptId, $inviterAnonId, $inviterToken] = $this->createOwnerContext('anon_inviter_owner', 'INTJ-A');
        $shareId = $this->createShareViaApi($inviterAttemptId, $inviterAnonId, $inviterToken);

        $invite = $this->postJson("/api/v0.3/shares/{$shareId}/compare-invites", [
            'anon_id' => 'scan_probe',
            'entrypoint' => 'share_page',
            'compare_intent' => true,
            'landing_path' => '/zh/share/'.$shareId,
            'utm_source' => 'share',
            'utm_medium' => 'organic',
            'utm_campaign' => 'mbti_compare',
            'meta' => [
                'share_click_id' => 'clk_flow_001',
            ],
        ]);
        $invite->assertOk();
        $inviteId = (string) $invite->json('invite_id');

        $inviteeAnonId = 'anon_invitee_owner';
        $start = $this->withHeaders([
            'X-Anon-Id' => $inviteeAnonId,
        ])->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'MBTI',
            'anon_id' => $inviteeAnonId,
            'share_id' => $shareId,
            'compare_invite_id' => $inviteId,
            'share_click_id' => 'clk_flow_001',
            'entrypoint' => 'share_page',
            'referrer' => 'https://ref.example/share',
            'landing_path' => '/zh/share/'.$shareId,
            'utm_source' => 'share',
            'utm_medium' => 'organic',
            'utm_campaign' => 'pr07a',
        ]);

        $start->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('scale_code_legacy', 'MBTI');

        $inviteeAttemptId = (string) $start->json('attempt_id');
        $this->assertNotSame('', $inviteeAttemptId);

        $inviteeAttempt = Attempt::query()->findOrFail($inviteeAttemptId);
        $this->assertSame($shareId, data_get($inviteeAttempt->answers_summary_json, 'meta.share_id'));
        $this->assertSame($inviteId, data_get($inviteeAttempt->answers_summary_json, 'meta.compare_invite_id'));
        $this->assertSame('clk_flow_001', data_get($inviteeAttempt->answers_summary_json, 'meta.share_click_id'));
        $this->assertSame('share_page', data_get($inviteeAttempt->answers_summary_json, 'meta.entrypoint'));
        $this->assertSame('/zh/share/'.$shareId, data_get($inviteeAttempt->answers_summary_json, 'meta.landing_path'));
        $this->assertSame('share', data_get($inviteeAttempt->answers_summary_json, 'meta.utm.source'));

        $inviteeToken = $this->issueAnonToken($inviteeAnonId);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $inviteeAttemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => 'ENFP-T',
            'scores_json' => ['total' => 100],
            'scores_pct' => [
                'EI' => 72,
                'SN' => 74,
                'TF' => 41,
                'JP' => 38,
                'AT' => 43,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'moderate',
                'JP' => 'moderate',
                'AT' => 'moderate',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'type_code' => 'ENFP-T',
                'type_name' => '竞选者型',
                'summary' => 'Invitee public-safe share summary.',
                'tagline' => '热情的连接者',
                'rarity' => '约 8%',
                'keywords' => ['热情', '灵感', '共情'],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $inviteeAttempt->submitted_at = now();
        $inviteeAttempt->answers_summary_json = [
            'stage' => 'submit',
            'meta' => [
                'share_id' => $shareId,
                'compare_invite_id' => $inviteId,
                'share_click_id' => 'clk_flow_001',
                'entrypoint' => 'share_page',
                'referrer' => 'https://ref.example/share',
                'landing_path' => '/zh/share/'.$shareId,
                'utm' => [
                    'source' => 'share',
                    'medium' => 'organic',
                    'campaign' => 'pr07a',
                ],
            ],
        ];
        $inviteeAttempt->save();

        $ctx = new OrgContext;
        $ctx->set(0, null, 'public', $inviteeAnonId);

        app(AttemptSubmitSideEffects::class)->runAfterSubmit($ctx, [
            'org_id' => 0,
            'attempt_id' => $inviteeAttemptId,
            'scale_code' => 'MBTI',
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'invite_token' => '',
            'credit_benefit_code' => '',
            'entitlement_benefit_code' => '',
            'share_id' => $shareId,
            'compare_invite_id' => $inviteId,
            'share_click_id' => 'clk_flow_001',
            'entrypoint' => 'share_page',
            'referrer' => 'https://ref.example/share',
            'landing_path' => '/zh/share/'.$shareId,
            'utm' => [
                'source' => 'share',
                'medium' => 'organic',
                'campaign' => 'pr07a',
            ],
        ], null, $inviteeAnonId);

        $compareReady = $this->getJson("/api/v0.3/compare/mbti/{$inviteId}");
        $compareReady->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('invitee.type_code', 'ENFP-T')
            ->assertJsonPath('compare.same_type', false)
            ->assertJsonPath('compare.axes.0.code', 'EI');

        config()->set('payments.allow_stub', true);
        config()->set('payments.providers.stub.enabled', true);

        $checkout = $this->withHeaders([
            'Authorization' => 'Bearer '.$inviteeToken,
            'X-Anon-Id' => $inviteeAnonId,
        ])->postJson('/api/v0.3/orders/checkout', [
            'attempt_id' => $inviteeAttemptId,
            'sku' => 'MBTI_REPORT_FULL',
            'provider' => 'stub',
            'share_id' => $shareId,
            'compare_invite_id' => $inviteId,
            'share_click_id' => 'clk_flow_001',
            'entrypoint' => 'share_page',
            'referrer' => 'https://ref.example/share',
            'landing_path' => '/zh/share/'.$shareId,
            'utm_source' => 'share',
            'utm_medium' => 'organic',
            'utm_campaign' => 'pr07a',
        ]);

        $checkout->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('attempt_id', $inviteeAttemptId)
            ->assertJsonPath('provider', 'stub');

        $orderNo = (string) $checkout->json('order_no');
        $this->assertNotSame('', $orderNo);

        $order = Order::query()->where('order_no', $orderNo)->firstOrFail();
        $this->assertSame($shareId, data_get($order->meta_json, 'attribution.share_id'));
        $this->assertSame($inviteId, data_get($order->meta_json, 'attribution.compare_invite_id'));
        $this->assertSame('clk_flow_001', data_get($order->meta_json, 'attribution.share_click_id'));
        $this->assertSame('share_page', data_get($order->meta_json, 'attribution.entrypoint'));
        $this->assertSame('/zh/share/'.$shareId, data_get($order->meta_json, 'attribution.landing_path'));
        $this->assertSame('share', data_get($order->meta_json, 'attribution.utm.source'));

        $paid = app(OrderManager::class)->transitionToPaidAtomic(
            $orderNo,
            0,
            'trade_001',
            now()->toISOString()
        );

        $this->assertTrue((bool) ($paid['ok'] ?? false));

        $inviteRow = DB::table('mbti_compare_invites')->where('id', $inviteId)->first();
        $this->assertNotNull($inviteRow);
        $this->assertSame('purchased', (string) ($inviteRow->status ?? ''));
        $this->assertSame($inviteeAttemptId, (string) ($inviteRow->invitee_attempt_id ?? ''));
        $this->assertSame($inviteeAnonId, (string) ($inviteRow->invitee_anon_id ?? ''));
        $this->assertSame($orderNo, (string) ($inviteRow->invitee_order_no ?? ''));
        $this->assertNotNull($inviteRow->accepted_at);
        $this->assertNotNull($inviteRow->completed_at);
        $this->assertNotNull($inviteRow->purchased_at);
    }

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder)->run();
    }

    private function seedSku(): void
    {
        DB::table('skus')->insert([
            'sku' => 'MBTI_REPORT_FULL',
            'scale_code' => 'MBTI',
            'scope' => 'attempt',
            'price_cents' => 299,
            'currency' => 'USD',
            'kind' => 'report',
            'benefit_code' => 'MBTI_REPORT_FULL',
            'unit_qty' => 1,
            'org_id' => 0,
            'meta_json' => json_encode([
                'requested_sku' => 'MBTI_REPORT_FULL',
                'effective_sku' => 'MBTI_REPORT_FULL',
                'entitlement_id' => 'mbti_report_full',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function createOwnerContext(string $anonId, string $typeCode): array
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
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
        ]);

        Result::create([
            'id' => (string) Str::uuid(),
            'org_id' => 0,
            'attempt_id' => $attemptId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'type_code' => $typeCode,
            'scores_json' => ['total' => 100],
            'scores_pct' => [
                'EI' => 35,
                'SN' => 72,
                'TF' => 68,
                'JP' => 63,
                'AT' => 58,
            ],
            'axis_states' => [
                'EI' => 'clear',
                'SN' => 'clear',
                'TF' => 'clear',
                'JP' => 'moderate',
                'AT' => 'moderate',
            ],
            'content_package_version' => 'v0.3',
            'result_json' => [
                'type_code' => $typeCode,
                'type_name' => $typeCode === 'INTJ-A' ? '建筑师型' : '竞选者型',
                'summary' => 'Public-safe share summary.',
                'tagline' => $typeCode === 'INTJ-A' ? '冷静的长期规划者' : '热情的连接者',
                'rarity' => $typeCode === 'INTJ-A' ? '约 2%' : '约 8%',
                'keywords' => $typeCode === 'INTJ-A' ? ['战略', '独立', '前瞻'] : ['热情', '灵感', '共情'],
            ],
            'pack_id' => (string) config('content_packs.default_pack_id', 'MBTI.cn-mainland.zh-CN.v0.3'),
            'dir_version' => (string) config('content_packs.default_dir_version', 'MBTI-CN-v0.3'),
            'scoring_spec_version' => '2026.01',
            'report_engine_version' => 'v1.2',
            'is_valid' => true,
            'computed_at' => now(),
        ]);

        $token = $this->issueAnonToken($anonId);

        return [$attemptId, $anonId, $token];
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

    private function createShareViaApi(string $attemptId, string $anonId, string $token): string
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Anon-Id' => $anonId,
        ])->getJson("/api/v0.3/attempts/{$attemptId}/share");

        $response->assertOk();

        return (string) $response->json('share_id');
    }
}
