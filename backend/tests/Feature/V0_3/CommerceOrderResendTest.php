<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Models\EmailPreference;
use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailOutboxService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CommerceOrderResendTest extends TestCase
{
    use RefreshDatabase;

    private const USER_OWNER_ANON = 'order_resend_user_owner';

    private const ANON_OWNER = 'order_resend_anon_owner';

    public function test_resend_queues_payment_success_delivery_for_paid_user_order(): void
    {
        $userId = $this->createUser('resend-user@example.com');
        $attemptId = $this->createAttempt(self::USER_OWNER_ANON, (string) $userId);
        $orderNo = 'ord_resend_'.Str::lower(Str::random(8));
        $this->insertOrder($orderNo, $attemptId, 'paid', self::USER_OWNER_ANON, (string) $userId, 'BIG5_FULL_REPORT');

        $token = $this->issueToken((string) $userId, self::USER_OWNER_ANON);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/orders/'.$orderNo.'/resend');

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'message' => 'Delivery notice has been queued.',
            ]);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->orderByDesc('updated_at')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame($orderNo, (string) ($payloadJson['order_no'] ?? ''));
        $this->assertSame("/api/v0.3/attempts/{$attemptId}/report", (string) ($payloadJson['report_url'] ?? ''));
        $this->assertSame("/api/v0.3/attempts/{$attemptId}/report.pdf", (string) ($payloadJson['report_pdf_url'] ?? ''));
        $this->assertSame('active', (string) ($payloadJson['subscriber_status'] ?? ''));
        $this->assertSame([], $payloadJson['attribution'] ?? null);
    }

    public function test_resend_reuses_existing_outbox_recipient_for_paid_anon_order(): void
    {
        $attemptId = $this->createAttempt(self::ANON_OWNER);
        $orderNo = 'ord_resend_'.Str::lower(Str::random(8));
        $this->insertOrder($orderNo, $attemptId, 'paid', self::ANON_OWNER, null, 'BIG5_FULL_REPORT');

        $outboxUserId = 'anon_'.substr(hash('sha256', self::ANON_OWNER), 0, 24);
        $queued = app(EmailOutboxService::class)->queueReportClaim($outboxUserId, 'anon-resend@example.com', $attemptId);
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $token = $this->issueToken(null, self::ANON_OWNER);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/orders/'.$orderNo.'/resend');

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'message' => 'Delivery notice has been queued.',
            ]);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->orderByDesc('updated_at')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($outboxUserId, (string) ($row->user_id ?? ''));

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $encryptedEmail = (string) (($row->to_email_enc ?? '') !== '' ? ($row->to_email_enc ?? '') : ($row->email_enc ?? ''));
        $this->assertSame('anon-resend@example.com', $pii->decrypt($encryptedEmail));
        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('active', (string) ($payloadJson['subscriber_status'] ?? ''));
        $this->assertSame([], $payloadJson['attribution'] ?? null);
    }

    public function test_resend_keeps_blind_success_for_ineligible_order_without_outbox_side_effect(): void
    {
        $userId = $this->createUser('resend-ineligible@example.com');
        $attemptId = $this->createAttempt(self::USER_OWNER_ANON, (string) $userId);
        $orderNo = 'ord_resend_'.Str::lower(Str::random(8));
        $this->insertOrder($orderNo, $attemptId, 'created', self::USER_OWNER_ANON, (string) $userId, 'BIG5_FULL_REPORT');

        $token = $this->issueToken((string) $userId, self::USER_OWNER_ANON);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/orders/'.$orderNo.'/resend');

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'message' => 'Delivery notice has been queued.',
            ]);

        $this->assertSame(
            0,
            DB::table('email_outbox')
                ->where('attempt_id', $attemptId)
                ->where('template', 'payment_success')
                ->count()
        );
    }

    public function test_resend_delivery_send_execution_respects_report_recovery_preference(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'array',
        ]);

        Mail::mailer('array')->getSymfonyTransport()->flush();

        $email = 'resend-pref@example.com';
        $userId = $this->createUser($email);
        $attemptId = $this->createAttempt(self::USER_OWNER_ANON, (string) $userId);
        $orderNo = 'ord_resend_'.Str::lower(Str::random(8));
        $this->insertOrder($orderNo, $attemptId, 'paid', self::USER_OWNER_ANON, (string) $userId, 'BIG5_FULL_REPORT');

        $capture = app(EmailCaptureService::class)->capture($email, ['surface' => 'lookup']);
        EmailPreference::query()
            ->where('subscriber_id', (string) ($capture['subscriber_id'] ?? ''))
            ->update([
                'marketing_updates' => true,
                'report_recovery' => false,
                'product_updates' => true,
            ]);

        $token = $this->issueToken((string) $userId, self::USER_OWNER_ANON);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->postJson('/api/v0.3/orders/'.$orderNo.'/resend');

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'message' => 'Delivery notice has been queued.',
            ]);

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 0, blocked 1, failed 0.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->orderByDesc('updated_at')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('skipped', (string) ($row->status ?? ''));
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    private function createUser(string $email): int
    {
        $userId = random_int(100000, 999999);

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Order Resend User',
            'email' => $email,
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    private function issueToken(?string $userId, string $anonId): string
    {
        $token = 'fm_'.(string) Str::uuid();
        $tokenHash = hash('sha256', $token);

        DB::table('fm_tokens')->insert([
            'token' => $token,
            'token_hash' => $tokenHash,
            'user_id' => $userId,
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
            'user_id' => $userId,
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

    private function createAttempt(string $anonId, ?string $userId = null): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'user_id' => $userId,
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

        return $attemptId;
    }

    private function insertOrder(
        string $orderNo,
        string $attemptId,
        string $status,
        string $anonId,
        ?string $userId,
        string $sku
    ): void {
        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'sku' => $sku,
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 2990,
            'currency' => 'USD',
            'status' => $status,
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => $status === 'paid' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = 2990;
        }
        if (Schema::hasColumn('orders', 'amount_refunded')) {
            $row['amount_refunded'] = 0;
        }
        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = $sku;
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
