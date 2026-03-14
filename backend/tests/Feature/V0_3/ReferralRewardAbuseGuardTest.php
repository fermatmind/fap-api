<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class ReferralRewardAbuseGuardTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_self_referral_same_user_is_blocked(): void
    {
        $this->seedCommerce();

        $inviterAttemptId = $this->createAttempt('anon_same_user_inviter', '1001');
        $inviteeAttemptId = $this->createAttempt('anon_same_user_invitee', '1001');
        $inviteId = $this->createCompareInvite($inviterAttemptId, $inviteeAttemptId, 'anon_same_user_invitee', 1001);
        $orderNo = 'ord_referral_block_same_user';

        $this->createCreditOrder($orderNo, $inviteeAttemptId, $inviteId, 'anon_same_user_invitee', '1001');

        $response = $this->postSignedBillingWebhook($this->billingPayload('evt_referral_block_same_user', $orderNo), [
            'X-Org-Id' => '0',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertBlockedReward($inviteId, $orderNo, 'self_referral_same_user');
    }

    public function test_self_referral_same_anon_is_blocked(): void
    {
        $this->seedCommerce();

        $inviterAttemptId = $this->createAttempt('anon_same_anon');
        $inviteeAttemptId = $this->createAttempt('anon_same_anon');
        $inviteId = $this->createCompareInvite($inviterAttemptId, $inviteeAttemptId, 'anon_same_anon');
        $orderNo = 'ord_referral_block_same_anon';

        $this->createCreditOrder($orderNo, $inviteeAttemptId, $inviteId, 'anon_same_anon');

        $response = $this->postSignedBillingWebhook($this->billingPayload('evt_referral_block_same_anon', $orderNo), [
            'X-Org-Id' => '0',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertBlockedReward($inviteId, $orderNo, 'self_referral_same_anon');
    }

    public function test_self_referral_same_attempt_is_blocked(): void
    {
        $this->seedCommerce();

        $sharedAttemptId = $this->createAttempt('anon_same_attempt_inviter');
        $inviteId = $this->createCompareInvite($sharedAttemptId, $sharedAttemptId, 'anon_same_attempt_invitee');
        $orderNo = 'ord_referral_block_same_attempt';

        DB::table('mbti_compare_invites')
            ->where('id', $inviteId)
            ->update([
                'invitee_anon_id' => 'anon_same_attempt_invitee',
                'invitee_user_id' => null,
            ]);

        $this->createCreditOrder($orderNo, $sharedAttemptId, $inviteId, 'anon_same_attempt_invitee');

        $response = $this->postSignedBillingWebhook($this->billingPayload('evt_referral_block_same_attempt', $orderNo), [
            'X-Org-Id' => '0',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertBlockedReward($inviteId, $orderNo, 'self_referral_same_attempt');
    }

    public function test_compare_invite_mismatch_with_order_target_is_blocked(): void
    {
        $this->seedCommerce();

        $inviterAttemptId = $this->createAttempt('anon_mismatch_inviter');
        $inviteeAttemptId = $this->createAttempt('anon_mismatch_invitee');
        $orderAttemptId = $this->createAttempt('anon_mismatch_order_target');
        $inviteId = $this->createCompareInvite($inviterAttemptId, $inviteeAttemptId, 'anon_mismatch_invitee');
        $orderNo = 'ord_referral_block_mismatch';

        $this->createCreditOrder($orderNo, $orderAttemptId, $inviteId, 'anon_mismatch_order_target');

        $response = $this->postSignedBillingWebhook($this->billingPayload('evt_referral_block_mismatch', $orderNo), [
            'X-Org-Id' => '0',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertBlockedReward($inviteId, $orderNo, 'invite_mismatch');
    }

    private function assertBlockedReward(string $inviteId, string $orderNo, string $reasonCode): void
    {
        $issuance = DB::table('referral_reward_issuances')->where('compare_invite_id', $inviteId)->first();
        $this->assertNotNull($issuance);
        $this->assertSame('blocked', (string) ($issuance->status ?? ''));
        $this->assertSame($reasonCode, (string) ($issuance->reason_code ?? ''));
        $this->assertSame($orderNo, (string) ($issuance->trigger_order_no ?? ''));
        $this->assertNull($issuance->granted_at);
        $this->assertSame(0, DB::table('benefit_grants')
            ->where('benefit_code', 'MBTI_GIFT_CREDITS')
            ->where('order_no', $orderNo)
            ->count());
        $this->assertSame(0, DB::table('benefit_wallet_ledgers')
            ->where('benefit_code', 'MBTI_GIFT_CREDITS')
            ->where('order_no', $orderNo)
            ->count());
    }

    private function seedCommerce(): void
    {
        (new Pr19CommerceSeeder)->run();
    }

    private function createAttempt(string $anonId, ?string $userId = null): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'org_id' => 0,
            'anon_id' => $anonId,
            'user_id' => $userId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'started_at' => now()->subMinutes(5),
            'submitted_at' => now()->subMinute(),
            'pack_id' => 'MBTI.cn-mainland.zh-CN.v0.3',
            'dir_version' => 'MBTI-CN-v0.3',
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $attemptId;
    }

    private function createCompareInvite(string $inviterAttemptId, string $inviteeAttemptId, string $inviteeAnonId, ?int $inviteeUserId = null): string
    {
        $inviteId = (string) Str::uuid();

        DB::table('mbti_compare_invites')->insert([
            'id' => $inviteId,
            'share_id' => 'share_'.Str::lower(Str::random(8)),
            'inviter_attempt_id' => $inviterAttemptId,
            'inviter_scale_code' => 'MBTI',
            'locale' => 'zh-CN',
            'inviter_type_code' => 'INTJ-A',
            'invitee_attempt_id' => $inviteeAttemptId,
            'invitee_anon_id' => $inviteeAnonId,
            'invitee_user_id' => $inviteeUserId,
            'invitee_order_no' => null,
            'status' => 'ready',
            'meta_json' => null,
            'accepted_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
            'purchased_at' => null,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinute(),
        ]);

        return $inviteId;
    }

    private function createCreditOrder(string $orderNo, string $targetAttemptId, string $compareInviteId, string $anonId, ?string $userId = null): void
    {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => $targetAttemptId,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 4990,
            'amount_refunded' => 0,
            'item_sku' => 'MBTI_CREDIT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'meta_json' => json_encode([
                'attribution' => [
                    'share_id' => 'share_abuse_'.Str::lower(Str::random(6)),
                    'compare_invite_id' => $compareInviteId,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function billingPayload(string $eventId, string $orderNo): array
    {
        return [
            'provider_event_id' => $eventId,
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_'.$eventId,
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];
    }
}
