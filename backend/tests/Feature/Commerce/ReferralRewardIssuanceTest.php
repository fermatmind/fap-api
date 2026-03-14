<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SignedBillingWebhook;
use Tests\TestCase;

final class ReferralRewardIssuanceTest extends TestCase
{
    use RefreshDatabase;
    use SignedBillingWebhook;

    public function test_paid_order_with_compare_invite_grants_one_referral_reward(): void
    {
        $this->seedCommerce();

        $inviterAttemptId = $this->createAttempt('anon_reward_inviter', '1001');
        $inviteeAttemptId = $this->createAttempt('anon_reward_invitee');
        $inviteId = $this->createCompareInvite($inviterAttemptId, $inviteeAttemptId, 'anon_reward_invitee');
        $orderNo = 'ord_referral_reward_1';

        $this->createCreditOrder($orderNo, $inviteeAttemptId, [
            'share_id' => 'share_reward_1',
            'compare_invite_id' => $inviteId,
        ], 'anon_reward_invitee');

        $response = $this->postSignedBillingWebhook($this->billingPayload('evt_referral_reward_1', $orderNo), [
            'X-Org-Id' => '0',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order_no', $orderNo);

        $issuance = DB::table('referral_reward_issuances')->where('compare_invite_id', $inviteId)->first();
        $this->assertNotNull($issuance);
        $this->assertSame('granted', (string) ($issuance->status ?? ''));
        $this->assertSame($orderNo, (string) ($issuance->trigger_order_no ?? ''));
        $this->assertSame($inviterAttemptId, (string) ($issuance->inviter_attempt_id ?? ''));
        $this->assertSame($inviteeAttemptId, (string) ($issuance->invitee_attempt_id ?? ''));
        $this->assertSame('MBTI_GIFT_CREDITS', (string) ($issuance->reward_sku ?? ''));
        $this->assertSame(1, (int) ($issuance->reward_quantity ?? 0));
        $this->assertNotNull($issuance->granted_at);

        $this->assertSame(1, DB::table('benefit_grants')
            ->where('benefit_code', 'MBTI_GIFT_CREDITS')
            ->where('order_no', $orderNo)
            ->count());
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')
            ->where('benefit_code', 'MBTI_GIFT_CREDITS')
            ->where('reason', 'topup')
            ->count());
        $this->assertSame(1, (int) DB::table('benefit_wallets')
            ->where('org_id', 0)
            ->where('benefit_code', 'MBTI_GIFT_CREDITS')
            ->value('balance'));

        $inviteRow = DB::table('mbti_compare_invites')->where('id', $inviteId)->first();
        $this->assertSame('purchased', (string) ($inviteRow->status ?? ''));
        $this->assertSame($orderNo, (string) ($inviteRow->invitee_order_no ?? ''));
    }

    public function test_same_webhook_replay_does_not_issue_a_second_referral_reward(): void
    {
        $this->seedCommerce();

        $inviterAttemptId = $this->createAttempt('anon_reward_replay_inviter');
        $inviteeAttemptId = $this->createAttempt('anon_reward_replay_invitee');
        $inviteId = $this->createCompareInvite($inviterAttemptId, $inviteeAttemptId, 'anon_reward_replay_invitee');
        $orderNo = 'ord_referral_reward_replay_1';
        $payload = $this->billingPayload('evt_referral_reward_replay_1', $orderNo);

        $this->createCreditOrder($orderNo, $inviteeAttemptId, [
            'share_id' => 'share_reward_replay_1',
            'compare_invite_id' => $inviteId,
        ], 'anon_reward_replay_invitee');

        $first = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $first->assertOk()->assertJsonPath('ok', true);

        $second = $this->postSignedBillingWebhook($payload, [
            'X-Org-Id' => '0',
        ]);
        $second->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('duplicate', true);

        $this->assertSame(1, DB::table('referral_reward_issuances')->where('compare_invite_id', $inviteId)->count());
        $this->assertSame(1, DB::table('benefit_grants')
            ->where('benefit_code', 'MBTI_GIFT_CREDITS')
            ->where('order_no', $orderNo)
            ->count());
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')
            ->where('benefit_code', 'MBTI_GIFT_CREDITS')
            ->where('reason', 'topup')
            ->count());
    }

    public function test_share_id_only_attribution_does_not_issue_reward(): void
    {
        $this->seedCommerce();

        $inviteeAttemptId = $this->createAttempt('anon_share_only_invitee');
        $orderNo = 'ord_referral_share_only_1';

        $this->createCreditOrder($orderNo, $inviteeAttemptId, [
            'share_id' => 'share_only_1',
        ], 'anon_share_only_invitee');

        $response = $this->postSignedBillingWebhook($this->billingPayload('evt_referral_share_only_1', $orderNo), [
            'X-Org-Id' => '0',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(0, DB::table('referral_reward_issuances')->count());
        $this->assertSame(0, DB::table('benefit_grants')->where('benefit_code', 'MBTI_GIFT_CREDITS')->count());
        $this->assertSame(0, DB::table('benefit_wallet_ledgers')->where('benefit_code', 'MBTI_GIFT_CREDITS')->count());
    }

    public function test_missing_compare_invite_id_does_not_issue_reward(): void
    {
        $this->seedCommerce();

        $inviteeAttemptId = $this->createAttempt('anon_no_compare_invitee');
        $orderNo = 'ord_referral_missing_compare_1';

        $this->createCreditOrder($orderNo, $inviteeAttemptId, [], 'anon_no_compare_invitee');

        $response = $this->postSignedBillingWebhook($this->billingPayload('evt_referral_missing_compare_1', $orderNo), [
            'X-Org-Id' => '0',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(0, DB::table('referral_reward_issuances')->count());
        $this->assertSame(0, DB::table('benefit_grants')->where('benefit_code', 'MBTI_GIFT_CREDITS')->count());
        $this->assertSame(0, DB::table('benefit_wallet_ledgers')->where('benefit_code', 'MBTI_GIFT_CREDITS')->count());
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

    /**
     * @param  array<string, mixed>  $attribution
     */
    private function createCreditOrder(string $orderNo, string $targetAttemptId, array $attribution, string $anonId, ?string $userId = null): void
    {
        $meta = $attribution !== []
            ? json_encode(['attribution' => $attribution], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

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
            'meta_json' => $meta,
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
