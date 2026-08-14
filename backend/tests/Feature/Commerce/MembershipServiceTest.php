<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\MembershipService;
use App\Services\Commerce\WechatMiniVirtualPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MembershipServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_five_valid_199_orders_automatically_grant_annual_membership(): void
    {
        $anonId = 'anon_membership_five';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index);
        }

        $status = app(MembershipService::class)->status(0, null, $anonId);

        $this->assertTrue((bool) data_get($status, 'membership.active'));
        $this->assertSame('annual', data_get($status, 'membership.plan_id'));
        $this->assertSame(5, data_get($status, 'credit.paid_report_count'));
        $this->assertNotNull(data_get($status, 'membership.expires_at'));
    }

    public function test_refunded_report_does_not_count_and_revokes_automatic_annual_membership(): void
    {
        $anonId = 'anon_membership_refund';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index);
        }
        $service = app(MembershipService::class);
        $this->assertTrue((bool) data_get($service->status(0, null, $anonId), 'membership.active'));

        DB::table('orders')->where('anon_id', $anonId)->orderBy('created_at')->limit(1)->update([
            'payment_state' => Order::PAYMENT_STATE_REFUNDED,
            'grant_state' => Order::GRANT_STATE_REVOKED,
            'amount_refunded' => 199,
            'refunded_at' => now(),
        ]);

        $status = $service->status(0, null, $anonId);
        $this->assertFalse((bool) data_get($status, 'membership.active'));
        $this->assertSame(4, data_get($status, 'credit.paid_report_count'));
    }

    public function test_annual_member_gets_fixed_999_lifetime_upgrade_offer(): void
    {
        $anonId = 'anon_membership_upgrade';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index);
        }
        $service = app(MembershipService::class);
        $service->status(0, null, $anonId);

        $offer = $service->offer(0, null, $anonId, 'lifetime');

        $this->assertSame('WEAPP_MEMBERSHIP_LIFETIME_UPGRADE_999', $offer['sku']);
        $this->assertSame(999, $offer['amount_due_cents']);
        $this->assertSame('¥9.99', $offer['display_price']);
        $this->assertTrue($offer['upgrade']);
    }

    public function test_earned_annual_membership_does_not_renew_from_the_same_five_orders(): void
    {
        $anonId = 'anon_membership_no_renew';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index);
        }
        $service = app(MembershipService::class);
        $service->status(0, null, $anonId);
        DB::table('benefit_grants')->where('benefit_ref', $anonId)->update(['expires_at' => now()->subSecond()]);

        $status = $service->status(0, null, $anonId);

        $this->assertFalse((bool) data_get($status, 'membership.active'));
        $this->assertSame(1, DB::table('benefit_grants')->where('benefit_ref', $anonId)->count());
    }

    public function test_membership_session_is_server_priced_and_annual_member_receives_999_upgrade_sku(): void
    {
        $anonId = 'anon_membership_session';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index);
        }
        $headers = [
            'Authorization' => 'Bearer '.$this->issueAnonToken($anonId),
            'X-Anon-Id' => $anonId,
        ];

        $this->withHeaders($headers)->postJson('/api/v0.3/membership-sessions', [
            'plan_id' => 'lifetime',
            'sku' => 'WEAPP_MEMBERSHIP_LIFETIME_1999',
        ])->assertUnprocessable();

        $response = $this->withHeaders($headers)->postJson('/api/v0.3/membership-sessions', [
            'plan_id' => 'lifetime',
        ])->assertOk()
            ->assertJsonPath('product.sku', 'WEAPP_MEMBERSHIP_LIFETIME_UPGRADE_999')
            ->assertJsonPath('product.amount_due_cents', 999)
            ->assertJsonPath('product.display_price', '¥9.99')
            ->json();

        $this->assertSame('MEMBERSHIP_UPGRADE', DB::table('attempts')->where('id', $response['attempt_id'])->value('scale_code'));
        $eligibility = app(WechatMiniVirtualPaymentService::class)->validateOrderEligibility(
            0,
            null,
            $anonId,
            $response['attempt_id'],
            'WEAPP_MEMBERSHIP_LIFETIME_UPGRADE_999',
            1,
        );
        $this->assertTrue((bool) ($eligibility['ok'] ?? false));
        $this->assertSame('MemberUpgrade999', config('payments.wechat_mini_virtual.products.MEMBERSHIP_UPGRADE.product_id'));
    }

    public function test_non_miniprogram_payment_does_not_count_toward_automatic_annual_membership(): void
    {
        $anonId = 'anon_web_orders_do_not_count';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index, 'wechatpay');
        }

        $status = app(MembershipService::class)->status(0, null, $anonId);

        $this->assertFalse((bool) data_get($status, 'membership.active'));
        $this->assertSame(0, data_get($status, 'credit.paid_report_count'));
    }

    public function test_wechat_ios_virtual_payment_counts_toward_automatic_annual_membership(): void
    {
        $anonId = 'anon_wechat_ios_orders_count';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index, 'apple_iap');
        }

        $status = app(MembershipService::class)->status(0, null, $anonId);

        $this->assertTrue((bool) data_get($status, 'membership.active'));
        $this->assertSame(5, data_get($status, 'credit.paid_report_count'));
    }

    public function test_membership_unlocks_only_wechat_mini_attempts(): void
    {
        $anonId = 'anon_membership_source_isolation';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index);
        }
        app(MembershipService::class)->status(0, null, $anonId);
        $miniAttemptId = $this->insertAttempt($anonId, 'wechat-miniprogram', 'wechat_miniapp');
        $webAttemptId = $this->insertAttempt($anonId, 'web', 'web');
        $entitlements = app(EntitlementManager::class);

        $this->assertTrue($entitlements->hasFullAccess(0, null, $anonId, $miniAttemptId, 'WEAPP_LOCAL_REPORT_FULL'));
        $this->assertFalse($entitlements->hasFullAccess(0, null, $anonId, $webAttemptId, 'WEAPP_LOCAL_REPORT_FULL'));
    }

    public function test_membership_prevents_repeat_local_report_payment(): void
    {
        $anonId = 'anon_membership_no_repeat_payment';
        foreach (range(1, 5) as $index) {
            $this->insertPaidReportOrder($anonId, $index);
        }
        app(MembershipService::class)->status(0, null, $anonId);
        $attemptId = $this->insertAttempt($anonId, 'wechat-miniprogram', 'wechat_miniapp', 'LOCAL_REPORT');

        $eligibility = app(WechatMiniVirtualPaymentService::class)->validateOrderEligibility(
            0,
            null,
            $anonId,
            $attemptId,
            'WEAPP_LOCAL_REPORT_FULL_199',
            1,
        );

        $this->assertFalse((bool) ($eligibility['ok'] ?? true));
        $this->assertSame('REPORT_ALREADY_FULL', $eligibility['error_code'] ?? null);
    }

    private function insertPaidReportOrder(string $anonId, int $index, string $provider = 'wechat_mini_virtual'): void
    {
        $id = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $id,
            'order_no' => 'ord_'.Str::uuid(),
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'sku' => 'WEAPP_LOCAL_REPORT_FULL_199',
            'quantity' => 1,
            'amount_cents' => 199,
            'amount_total' => 199,
            'amount_refunded' => 0,
            'currency' => 'CNY',
            'status' => Order::STATUS_FULFILLED,
            'payment_state' => Order::PAYMENT_STATE_PAID,
            'grant_state' => Order::GRANT_STATE_GRANTED,
            'provider' => $provider,
            'item_sku' => 'WEAPP_LOCAL_REPORT_FULL_199',
            'paid_at' => now(),
            'created_at' => now()->addSeconds($index),
            'updated_at' => now(),
        ]);
    }

    private function insertAttempt(
        string $anonId,
        string $clientPlatform,
        string $channel,
        string $scaleCode = 'MBTI',
    ): string {
        $id = (string) Str::uuid();
        DB::table('attempts')->insert([
            'id' => $id,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'membership-test.v1',
            'question_count' => 0,
            'answers_summary_json' => '[]',
            'client_platform' => $clientPlatform,
            'channel' => $channel,
            'answers_digest' => hash('sha256', $id),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.Str::uuid();
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
}
