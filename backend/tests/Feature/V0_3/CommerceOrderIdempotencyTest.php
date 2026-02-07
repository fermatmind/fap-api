<?php

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr19CommerceSeeder;
use Database\Seeders\ScaleRegistrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceOrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrgWithToken(int $orgId, int $userId): array
    {
        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Commerce User ' . $userId,
            'email' => 'commerce_' . $userId . '@example.com',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('organizations')->insert([
            'id' => $orgId,
            'name' => 'Commerce Org ' . $orgId,
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

    public function test_order_create_idempotent(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        [$orgId, $userId, $token] = $this->seedOrgWithToken(9101, 9101);

        $payload = [
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'provider' => 'stub',
        ];

        $idempotencyKey = 'idem_order_1';

        $first = $this->postJson('/api/v0.3/orders', $payload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $first->assertStatus(200);
        $first->assertJson(['ok' => true]);
        $orderNo = (string) $first->json('order_no');
        $this->assertNotSame('', $orderNo);

        $second = $this->postJson('/api/v0.3/orders', $payload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $second->assertStatus(200);
        $second->assertJson(['ok' => true]);
        $this->assertSame($orderNo, (string) $second->json('order_no'));

        $this->assertSame(
            1,
            DB::table('orders')
                ->where('org_id', $orgId)
                ->where('provider', 'stub')
                ->where('idempotency_key', $idempotencyKey)
                ->count()
        );
    }

    public function test_order_create_idempotency_key_scoped_by_provider(): void
    {
        (new ScaleRegistrySeeder())->run();
        (new Pr19CommerceSeeder())->run();

        [$orgId, $userId, $token] = $this->seedOrgWithToken(9102, 9102);
        $this->assertIsInt($userId);

        $payload = [
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
        ];
        $idempotencyKey = 'idem_scope_provider_1';

        $stubResp = $this->postJson('/api/v0.3/orders/stub', $payload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $stubResp->assertStatus(200);
        $stubOrderNo = (string) $stubResp->json('order_no');
        $this->assertNotSame('', $stubOrderNo);

        $billingResp = $this->postJson('/api/v0.3/orders/billing', $payload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $billingResp->assertStatus(200);
        $billingOrderNo = (string) $billingResp->json('order_no');
        $this->assertNotSame('', $billingOrderNo);
        $this->assertNotSame($stubOrderNo, $billingOrderNo);

        $billingSecondResp = $this->postJson('/api/v0.3/orders/billing', $payload, [
            'X-Org-Id' => (string) $orgId,
            'Authorization' => 'Bearer ' . $token,
            'Idempotency-Key' => $idempotencyKey,
        ]);
        $billingSecondResp->assertStatus(200);
        $this->assertSame($billingOrderNo, (string) $billingSecondResp->json('order_no'));

        $this->assertSame(
            1,
            DB::table('orders')
                ->where('org_id', $orgId)
                ->where('provider', 'stub')
                ->where('idempotency_key', $idempotencyKey)
                ->count()
        );
        $this->assertSame(
            1,
            DB::table('orders')
                ->where('org_id', $orgId)
                ->where('provider', 'billing')
                ->where('idempotency_key', $idempotencyKey)
                ->count()
        );
    }

    public function test_cross_org_order_lookup_returns_404(): void
    {
        (new Pr19CommerceSeeder())->run();

        [$orgA, $userA, $tokenA] = $this->seedOrgWithToken(9201, 9201);
        [$orgB, $userB, $tokenB] = $this->seedOrgWithToken(9202, 9202);

        $orderNo = 'ord_cross_org_1';
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => $orgA,
            'user_id' => (string) $userA,
            'anon_id' => 'anon_' . $userA,
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

        $res = $this->getJson('/api/v0.3/orders/' . $orderNo, [
            'X-Org-Id' => (string) $orgB,
            'Authorization' => 'Bearer ' . $tokenB,
        ]);

        $res->assertStatus(404);
        $res->assertJson([
            'ok' => false,
            'error' => 'ORDER_NOT_FOUND',
        ]);
    }
}
