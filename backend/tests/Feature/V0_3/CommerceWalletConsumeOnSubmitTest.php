<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr17SimpleScoreDemoSeeder;
use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceWalletConsumeOnSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function seedScales(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr17SimpleScoreDemoSeeder())->run();
        (new Pr19CommerceSeeder())->run();

        $row = DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('code', 'SIMPLE_SCORE_DEMO')
            ->first();

        if ($row) {
            $commercial = $row->commercial_json ?? null;
            if (is_string($commercial)) {
                $decoded = json_decode($commercial, true);
                $commercial = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($commercial)) {
                $commercial = [];
            }

            $commercial['credit_benefit_code'] = 'MBTI_CREDIT';

            $payload = $commercial;
            if (is_array($payload)) {
                $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
            }

            DB::table('scales_registry')
                ->where('org_id', 0)
                ->where('code', 'SIMPLE_SCORE_DEMO')
                ->update([
                    'commercial_json' => $payload,
                    'updated_at' => now(),
                ]);
        }

        Cache::flush();
    }

    private function seedOrgWithToken(): array
    {
        $userId = 9001;
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Test User',
            'email' => 'test_user@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orgId = 101;
        DB::table('organizations')->insert([
            'id' => $orgId,
            'name' => 'Test Org',
            'owner_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organization_members')->insert([
            'org_id' => $orgId,
            'user_id' => $userId,
            'role' => 'owner',
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'anon_id' => 'anon_' . $userId,
            'user_id' => $userId,
            'expires_at' => now()->addDays(1),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$orgId, $userId, $token];
    }

    public function test_consume_once_on_submit(): void
    {
        $this->seedScales();
        [$orgId, $userId, $token] = $this->seedOrgWithToken();

        $orderNo = 'ord_credit_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => $orgId,
            'user_id' => (string) $userId,
            'anon_id' => 'anon_' . $userId,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 4990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'stub',
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
        ]);

        $payload = [
            'provider_event_id' => 'evt_wallet_1',
            'order_no' => $orderNo,
            'external_trade_no' => 'trade_wallet_1',
            'amount_cents' => 4990,
            'currency' => 'USD',
        ];

        $webhook = $this->postJson('/api/v0.3/webhooks/payment/stub', $payload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $webhook->assertStatus(200);
        $webhook->assertJson(['ok' => true]);

        $walletBefore = DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', 'MBTI_CREDIT')
            ->first();
        $this->assertSame(100, (int) ($walletBefore->balance ?? 0));

        $start = $this->postJson('/api/v0.3/attempts/start', [
            'scale_code' => 'SIMPLE_SCORE_DEMO',
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $start->assertStatus(200);
        $attemptId = (string) $start->json('attempt_id');

        $answers = [
            ['question_id' => 'SS-001', 'code' => '5'],
            ['question_id' => 'SS-002', 'code' => '4'],
            ['question_id' => 'SS-003', 'code' => '3'],
            ['question_id' => 'SS-004', 'code' => '2'],
            ['question_id' => 'SS-005', 'code' => '1'],
        ];

        $submit = $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 120000,
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $submit->assertStatus(200);
        $submit->assertJson(['ok' => true]);

        $walletAfter = DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', 'MBTI_CREDIT')
            ->first();
        $this->assertSame(99, (int) ($walletAfter->balance ?? 0));
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'consume')->count());
        $this->assertSame(1, DB::table('benefit_consumptions')->count());

        $dup = $this->postJson('/api/v0.3/attempts/submit', [
            'attempt_id' => $attemptId,
            'answers' => $answers,
            'duration_ms' => 120000,
        ], [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
        ]);
        $dup->assertStatus(200);
        $dup->assertJson([
            'ok' => true,
            'idempotent' => true,
        ]);

        $walletFinal = DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->where('benefit_code', 'MBTI_CREDIT')
            ->first();
        $this->assertSame(99, (int) ($walletFinal->balance ?? 0));
        $this->assertSame(1, DB::table('benefit_wallet_ledgers')->where('reason', 'consume')->count());
        $this->assertSame(1, DB::table('benefit_consumptions')->count());
    }
}
