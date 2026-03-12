<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Services\Email\EmailOutboxService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailOutboxAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_success_outbox_payload_includes_attribution_contract(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $attemptId = $this->createAttempt();
        $email = 'buyer+'.random_int(1000, 9999).'@example.com';

        /** @var EmailOutboxService $outbox */
        $outbox = app(EmailOutboxService::class);
        $queued = $outbox->queuePaymentSuccess(
            'payment_success_user',
            $email,
            $attemptId,
            'ord_attr_001',
            'MBTI Full Report',
            [
                'share_id' => 'share_123',
                'compare_invite_id' => (string) Str::uuid(),
                'share_click_id' => 'clk_456',
                'entrypoint' => 'share_page',
                'referrer' => 'https://example.com/share',
                'landing_path' => '/zh/share/123',
                'utm' => [
                    'source' => 'share',
                    'medium' => 'organic',
                    'campaign' => 'pr09',
                    'term' => 'mbti',
                    'content' => 'hero',
                ],
            ]
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_123', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('clk_456', (string) data_get($payloadJson, 'attribution.share_click_id'));
        $this->assertSame('organic', (string) data_get($payloadJson, 'attribution.utm.medium'));
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('hero', (string) data_get($payloadEnc, 'attribution.utm.content'));

        Mail::mailer('array')->getSymfonyTransport()->flush();
        $this->artisan('email:outbox-send --limit=10')->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('share_id=share_123', $html);
        $this->assertStringContainsString('utm_campaign=pr09', $html);
        $this->assertStringContainsString('compare_invite_id=', $html);
    }

    public function test_report_claim_outbox_payload_includes_attribution_contract(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $attemptId = $this->createAttempt();

        /** @var EmailOutboxService $outbox */
        $outbox = app(EmailOutboxService::class);
        $queued = $outbox->queueReportClaim(
            'report_claim_user',
            'claim@example.com',
            $attemptId,
            'ord_attr_002',
            [
                'share_id' => 'share_claim',
                'entrypoint' => 'order_lookup',
                'landing_path' => '/zh/orders/lookup',
                'utm' => [
                    'source' => 'help',
                    'medium' => 'owned',
                    'campaign' => 'report-recovery',
                ],
            ]
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'report_claim')
            ->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_claim', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('order_lookup', (string) data_get($payloadJson, 'attribution.entrypoint'));

        Mail::mailer('array')->getSymfonyTransport()->flush();
        $this->artisan('email:outbox-send --limit=10')->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('share_id=share_claim', $html);
        $this->assertStringContainsString('utm_campaign=report-recovery', $html);
    }

    private function createAttempt(): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_attr',
            'user_id' => null,
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
}
