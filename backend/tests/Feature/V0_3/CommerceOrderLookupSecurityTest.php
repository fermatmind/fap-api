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
        $orderNo = 'ord_lookup_'.Str::lower(Str::random(8));
        $this->insertOrderForLookup($orderNo, 'owner@example.com');

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'attacker@example.com',
        ]);

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'ORDER_NOT_FOUND');
    }

    public function test_lookup_with_matching_email_hash_returns_delivery_contract(): void
    {
        $orderNo = 'ord_lookup_'.Str::lower(Str::random(8));
        $userId = $this->createUser('owner@example.com');
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, (string) $userId);
        $this->insertOrderForLookup($orderNo, 'owner@example.com', $attemptId, 'paid', (string) $userId);

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'owner@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('delivery.can_view_report', true)
            ->assertJsonPath('delivery.report_url', "/api/v0.3/attempts/{$attemptId}/report")
            ->assertJsonPath('delivery.can_download_pdf', true)
            ->assertJsonPath('delivery.report_pdf_url', "/api/v0.3/attempts/{$attemptId}/report.pdf")
            ->assertJsonPath('delivery.can_resend', true)
            ->assertJsonPath('delivery.contact_email_present', true)
            ->assertJsonPath('delivery.last_delivery_email_sent_at', null)
            ->assertJsonPath('delivery.can_request_claim_email', true);
    }

    public function test_lookup_with_matching_email_hash_returns_mbti_access_hub_for_mbti_attempt(): void
    {
        $orderNo = 'ord_lookup_mbti_'.Str::lower(Str::random(8));
        $userId = $this->createUser('owner@example.com');
        $attemptId = (string) Str::uuid();
        $compareInviteId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER, (string) $userId, 'MBTI');
        $this->insertOrderForLookup($orderNo, 'owner@example.com', $attemptId, 'paid', (string) $userId, [
            'attribution' => [
                'share_id' => 'share_001',
                'compare_invite_id' => $compareInviteId,
            ],
        ]);

        $response = $this->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
            'email' => 'owner@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('mbti_access_hub_v1.access_state', 'ready')
            ->assertJsonPath('mbti_access_hub_v1.report_access.can_view_report', true)
            ->assertJsonPath('mbti_access_hub_v1.report_access.attempt_id', $attemptId)
            ->assertJsonPath('mbti_access_hub_v1.report_access.order_no', $orderNo)
            ->assertJsonPath('mbti_access_hub_v1.report_access.source', 'order_delivery')
            ->assertJsonPath('mbti_access_hub_v1.pdf_access.can_download_pdf', true)
            ->assertJsonPath('mbti_access_hub_v1.pdf_access.source', 'order_delivery')
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_lookup_order', true)
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_request_claim_email', true)
            ->assertJsonPath('mbti_access_hub_v1.recovery.can_resend', true)
            ->assertJsonPath('mbti_access_hub_v1.recovery.attempt_id', $attemptId)
            ->assertJsonPath('mbti_access_hub_v1.recovery.share_id', 'share_001')
            ->assertJsonPath('mbti_access_hub_v1.recovery.compare_invite_id', $compareInviteId)
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.has_entry', true)
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.entry_kind', 'mbti_history')
            ->assertJsonPath('mbti_access_hub_v1.workspace_lite.attempt_id', $attemptId);
    }

    public function test_lookup_with_token_owner_works_without_email(): void
    {
        $orderNo = 'ord_lookup_'.Str::lower(Str::random(8));
        $this->insertOrderForLookup($orderNo, 'owner@example.com');

        $token = $this->issueAnonToken(self::ANON_OWNER);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/orders/lookup', [
            'order_no' => $orderNo,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('attempt_id', null)
            ->assertJsonPath('delivery.can_view_report', false)
            ->assertJsonPath('delivery.report_url', null)
            ->assertJsonPath('delivery.can_download_pdf', false)
            ->assertJsonPath('delivery.report_pdf_url', null)
            ->assertJsonPath('delivery.can_resend', false)
            ->assertJsonPath('delivery.contact_email_present', true)
            ->assertJsonPath('delivery.last_delivery_email_sent_at', null)
            ->assertJsonPath('delivery.can_request_claim_email', false);
    }

    private function createUser(string $email): int
    {
        $userId = random_int(100000, 999999);

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Lookup Owner',
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function issueAnonToken(string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => $tokenHash,
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('auth_tokens')->insert([
            'token_hash' => $tokenHash,
            'user_id' => null,
            'anon_id' => $anonId,
            'org_id' => 0,
            'role' => 'public',
            'meta_json' => null,
            'expires_at' => now()->addDay(),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }

    private function insertAttempt(
        string $attemptId,
        string $anonId,
        ?string $userId = null,
        string $scaleCode = 'BIG5_OCEAN'
    ): void {
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 120,
            'answers_summary_json' => json_encode(['stage' => 'seed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'content_package_version' => 'v1',
            'scoring_spec_version' => 'big5_spec_2026Q1_v1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOrderForLookup(
        string $orderNo,
        string $email,
        ?string $attemptId = null,
        string $status = 'created',
        ?string $userId = null,
        ?array $meta = null
    ): void {
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => self::ANON_OWNER,
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 1990,
            'currency' => 'USD',
            'status' => $status,
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => $status === 'paid' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('orders', 'contact_email_hash')) {
            $row['contact_email_hash'] = hash('sha256', mb_strtolower(trim($email), 'UTF-8'));
        }
        if (Schema::hasColumn('orders', 'meta_json')) {
            $row['meta_json'] = $meta !== null
                ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;
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
