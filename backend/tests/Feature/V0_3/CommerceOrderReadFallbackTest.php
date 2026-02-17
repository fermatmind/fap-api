<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceOrderReadFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const ANON_OWNER = 'order_read_owner';
    private const ANON_ATTACKER = 'order_read_attacker';

    public function test_without_identity_returns_degraded_payload(): void
    {
        $orderNo = 'ord_fallback_' . Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $response = $this->getJson('/api/v0.3/orders/' . $orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ownership_verified', false)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'pending');

        $response->assertJsonMissingPath('order');
        $response->assertJsonMissingPath('amount_cents');
        $response->assertJsonMissingPath('currency');
    }

    public function test_mismatched_token_identity_still_returns_404(): void
    {
        $orderNo = 'ord_fallback_' . Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $token = $this->issueAnonToken(self::ANON_ATTACKER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v0.3/orders/' . $orderNo);

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_matching_token_identity_returns_full_payload(): void
    {
        $orderNo = 'ord_fallback_' . Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $token = $this->issueAnonToken(self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v0.3/orders/' . $orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ownership_verified', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('amount_cents', 1990)
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('order.anon_id', self::ANON_OWNER);
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_' . (string) Str::uuid();
        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function insertOrderForOwner(string $orderNo): void
    {
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => self::ANON_OWNER,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => null,
            'amount_cents' => 1990,
            'currency' => 'USD',
            'status' => 'created',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = 1990;
        }
        if (Schema::hasColumn('orders', 'amount_refunded')) {
            $row['amount_refunded'] = 0;
        }
        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = 'MBTI_CREDIT';
        }
        if (Schema::hasColumn('orders', 'provider_order_id')) {
            $row['provider_order_id'] = null;
        }
        if (Schema::hasColumn('orders', 'device_id')) {
            $row['device_id'] = null;
        }
        if (Schema::hasColumn('orders', 'request_id')) {
            $row['request_id'] = null;
        }
        if (Schema::hasColumn('orders', 'created_ip')) {
            $row['created_ip'] = null;
        }
        if (Schema::hasColumn('orders', 'fulfilled_at')) {
            $row['fulfilled_at'] = null;
        }
        if (Schema::hasColumn('orders', 'refunded_at')) {
            $row['refunded_at'] = null;
        }
        if (Schema::hasColumn('orders', 'refund_amount_cents')) {
            $row['refund_amount_cents'] = null;
        }
        if (Schema::hasColumn('orders', 'refund_reason')) {
            $row['refund_reason'] = null;
        }

        DB::table('orders')->insert($row);
    }
}
