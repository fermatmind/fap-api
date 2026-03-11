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

    public function test_without_identity_returns_404(): void
    {
        $orderNo = 'ord_fallback_'.Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $response = $this->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_mismatched_token_identity_still_returns_404(): void
    {
        $orderNo = 'ord_fallback_'.Str::lower(Str::random(10));
        $this->insertOrderForOwner($orderNo);

        $token = $this->issueAnonToken(self::ANON_ATTACKER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_matching_token_identity_returns_full_payload(): void
    {
        $orderNo = 'ord_fallback_'.Str::lower(Str::random(10));
        $attemptId = (string) Str::uuid();
        $this->insertAttempt($attemptId, self::ANON_OWNER);
        $this->insertOrderForOwner($orderNo, $attemptId, 'paid');

        $token = $this->issueAnonToken(self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/v0.3/orders/'.$orderNo);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('ownership_verified', true)
            ->assertJsonPath('order_no', $orderNo)
            ->assertJsonPath('status', 'paid')
            ->assertJsonPath('attempt_id', $attemptId)
            ->assertJsonPath('amount_cents', 1990)
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('order.anon_id', self::ANON_OWNER)
            ->assertJsonPath('delivery.can_view_report', true)
            ->assertJsonPath('delivery.report_url', "/api/v0.3/attempts/{$attemptId}/report")
            ->assertJsonPath('delivery.can_download_pdf', true)
            ->assertJsonPath('delivery.report_pdf_url', "/api/v0.3/attempts/{$attemptId}/report.pdf")
            ->assertJsonPath('delivery.can_resend', false);
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

    private function insertAttempt(string $attemptId, string $anonId): void
    {
        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'scale_code' => 'BIG5_OCEAN',
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

    private function insertOrderForOwner(string $orderNo, ?string $attemptId = null, string $status = 'created'): void
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
