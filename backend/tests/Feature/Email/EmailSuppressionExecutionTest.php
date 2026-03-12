<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailPreference;
use App\Models\EmailSuppression;
use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailOutboxService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailSuppressionExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_suppression_hit_prevents_payment_success_delivery(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $attemptId = $this->createAttempt('suppression_direct_anon');
        $email = 'suppressed+payment@example.com';

        $queued = app(EmailOutboxService::class)->queuePaymentSuccess(
            'suppression_user',
            $email,
            $attemptId,
            'ord_suppressed_001',
            'MBTI Full Report'
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        EmailSuppression::query()->create([
            'id' => (string) Str::uuid(),
            'email_hash' => $pii->emailHash($email),
            'reason' => 'bounce',
            'source' => 'test',
            'meta_json' => ['channel' => 'qa'],
        ]);

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 0, blocked 1, failed 0.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('attempt_id', $attemptId)->where('template', 'payment_success')->first();
        $this->assertNotNull($row);
        $this->assertSame('suppressed', (string) ($row->status ?? ''));
        $this->assertSame(0, Mail::mailer('array')->getSymfonyTransport()->messages()->count());
    }

    public function test_report_recovery_disabled_prevents_report_claim_delivery(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $attemptId = $this->createAttempt('suppression_claim_anon');
        $email = 'blocked+claim@example.com';

        $capture = app(EmailCaptureService::class)->capture($email, ['surface' => 'lookup']);
        EmailPreference::query()
            ->where('subscriber_id', (string) ($capture['subscriber_id'] ?? ''))
            ->update([
                'marketing_updates' => false,
                'report_recovery' => false,
                'product_updates' => false,
            ]);

        $queued = app(EmailOutboxService::class)->queueReportClaim(
            'claim_block_user',
            $email,
            $attemptId,
            'ord_claim_blocked_001'
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 0, blocked 1, failed 0.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('attempt_id', $attemptId)->where('template', 'report_claim')->first();
        $this->assertNotNull($row);
        $this->assertSame('skipped', (string) ($row->status ?? ''));
        $this->assertSame('report_recovery_disabled', (string) data_get(json_decode((string) ($row->payload_json ?? '{}'), true), 'delivery_execution.guard'));
    }

    public function test_resend_payment_success_delivery_is_blocked_when_report_recovery_is_disabled(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $email = 'resend+blocked@example.com';
        $userId = $this->createUser($email);
        $attemptId = $this->createAttempt('resend_blocked_anon', (string) $userId, 'BIG5_OCEAN');
        $orderNo = 'ord_resend_blocked_'.Str::lower(Str::random(8));
        $this->insertOrder($orderNo, $attemptId, 'paid', 'resend_blocked_anon', (string) $userId, 'BIG5_FULL_REPORT');

        $capture = app(EmailCaptureService::class)->capture($email, ['surface' => 'lookup']);
        EmailPreference::query()
            ->where('subscriber_id', (string) ($capture['subscriber_id'] ?? ''))
            ->update([
                'marketing_updates' => false,
                'report_recovery' => false,
                'product_updates' => false,
            ]);

        $token = $this->issueToken((string) $userId, 'resend_blocked_anon');
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

        $row = DB::table('email_outbox')->where('attempt_id', $attemptId)->where('template', 'payment_success')->first();
        $this->assertNotNull($row);
        $this->assertSame('skipped', (string) ($row->status ?? ''));
        $this->assertSame(0, Mail::mailer('array')->getSymfonyTransport()->messages()->count());
    }

    private function createUser(string $email): int
    {
        $userId = random_int(100000, 999999);

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Suppression User',
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

    private function createAttempt(string $anonId, ?string $userId = null, string $scaleCode = 'MBTI'): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => 'en',
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

    private function insertOrder(
        string $orderNo,
        string $attemptId,
        string $status,
        string $anonId,
        ?string $userId,
        string $sku
    ): void {
        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'sku' => $sku,
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 29900,
            'currency' => 'CNY',
            'status' => $status,
            'provider' => 'billing',
            'external_trade_no' => null,
            'paid_at' => $status === 'paid' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
            'amount_total' => 29900,
            'amount_refunded' => 0,
            'item_sku' => $sku,
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
        ]);
    }

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }
}
