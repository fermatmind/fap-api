<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClaimReportEmailRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_report_post_blinds_missing_order(): void
    {
        $response = $this->postJson('/api/v0.3/claim/report', [
            'order_no' => 'ord_missing_claim',
            'email' => 'missing@example.com',
            'surface' => 'help',
            'entrypoint' => 'help_center',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'queued' => true,
            ]);

        $this->assertSame(0, DB::table('email_outbox')->count());
    }

    public function test_claim_report_post_blinds_email_mismatch(): void
    {
        $attemptId = $this->createAttempt('claim_owner');
        $orderNo = 'ord_claim_'.Str::lower(Str::random(8));
        $this->insertOrder($orderNo, $attemptId, 'owner@example.com');

        $response = $this->postJson('/api/v0.3/claim/report', [
            'order_no' => $orderNo,
            'email' => 'attacker@example.com',
            'surface' => 'lookup',
            'entrypoint' => 'order_lookup',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'queued' => true,
            ]);

        $this->assertSame(
            0,
            DB::table('email_outbox')
                ->where('attempt_id', $attemptId)
                ->where('template', 'report_claim')
                ->count()
        );
    }

    public function test_claim_report_post_queues_outbox_for_eligible_request(): void
    {
        $attemptId = $this->createAttempt('claim_owner');
        $orderNo = 'ord_claim_'.Str::lower(Str::random(8));
        $this->insertOrder($orderNo, $attemptId, 'owner@example.com', [
            'share_click_id' => 'clk_001',
            'landing_path' => '/zh/share/source',
        ]);

        $response = $this->postJson('/api/v0.3/claim/report', [
            'order_no' => $orderNo,
            'email' => 'owner@example.com',
            'locale' => 'zh-CN',
            'surface' => 'help',
            'entrypoint' => 'help_center',
            'referrer' => 'https://example.com/help',
            'landing_path' => '/zh/orders/lookup',
            'share_id' => 'share_123',
            'compare_invite_id' => (string) Str::uuid(),
            'utm' => [
                'source' => 'help',
                'medium' => 'owned',
                'campaign' => 'report-recovery',
                'term' => 'claim',
                'content' => 'cta',
            ],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'queued' => true,
            ]);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'report_claim')
            ->orderByDesc('updated_at')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame($orderNo, (string) ($payloadJson['order_no'] ?? ''));
        $this->assertSame($attemptId, (string) ($payloadJson['attempt_id'] ?? ''));
        $this->assertSame('/zh/orders/lookup', (string) data_get($payloadJson, 'attribution.landing_path'));
        $this->assertSame('clk_001', (string) data_get($payloadJson, 'attribution.share_click_id'));
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);
        $this->assertArrayNotHasKey('claim_token', $payloadJson);
        $this->assertArrayNotHasKey('claim_url', $payloadJson);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame('owner@example.com', (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('share_123', (string) data_get($payloadEnc, 'attribution.share_id'));
    }

    private function createAttempt(string $anonId): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => $anonId,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'question_count' => 144,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subMinute(),
            'submitted_at' => now(),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $attemptId;
    }

    /**
     * @param  array<string,mixed>  $attribution
     */
    private function insertOrder(string $orderNo, string $attemptId, string $email, array $attribution = []): void
    {
        $meta = $attribution === [] ? null : json_encode([
            'attribution' => $attribution,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'claim_owner',
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 1990,
            'currency' => 'USD',
            'status' => 'paid',
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'meta_json' => $meta,
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
