<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceOrderLookupSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ANON_OWNER = 'lookup_owner';

    public function test_lookup_requires_email_when_identity_missing(): void
    {
        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => 'ord_missing_identity',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    }

    public function test_lookup_with_wrong_email_is_blinded(): void
    {
        $orderNo = 'ord_lookup_' . Str::lower(Str::random(8));
        $this->insertOrderForLookup($orderNo, 'owner@example.com');

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'attacker@example.com',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND');
    }

    public function test_lookup_with_matching_email_hash_returns_status(): void
    {
        $orderNo = 'ord_lookup_' . Str::lower(Str::random(8));
        $this->insertOrderForLookup($orderNo, 'owner@example.com');

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'owner@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'pending');
    }

    public function test_lookup_with_token_owner_works_without_email(): void
    {
        $orderNo = 'ord_lookup_' . Str::lower(Str::random(8));
        $this->insertOrderForLookup($orderNo, 'owner@example.com');

        $token = $this->issueAnonToken(self::ANON_OWNER);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'pending');
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

    private function insertOrderForLookup(string $orderNo, string $email): void
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

        if (Schema::hasColumn('orders', 'contact_email_hash')) {
            $row['contact_email_hash'] = hash('sha256', mb_strtolower(trim($email), 'UTF-8'));
        }
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
