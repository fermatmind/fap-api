<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailOutboxService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ReportReactivationTemplateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('localeProvider')]
    public function test_report_reactivation_renders_supported_locales(string $locale, string $expectedTitle, string $expectedReportLabel): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();

        $email = 'report-reactivation+'.md5($locale).'@example.com';
        $attemptId = $this->createAttempt($locale);
        $orderNo = 'ord_report_reactivation_'.Str::lower(Str::random(8));
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'report',
            'locale' => $locale,
            'entrypoint' => 'report_view',
        ]);
        $this->createOrder($orderNo, $attemptId, $email);

        $queued = app(EmailOutboxService::class)->queueReportReactivation($subscriber, $attemptId, $orderNo, $locale);
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')->where('template', 'report_reactivation')->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_report_reactivation', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('owned', (string) data_get($payloadJson, 'attribution.utm.medium'));
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);
        $this->assertArrayNotHasKey('claim_token', $payloadJson);
        $this->assertArrayNotHasKey('claim_url', $payloadJson);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('share_report_reactivation', (string) data_get($payloadEnc, 'attribution.share_id'));
        $this->assertArrayNotHasKey('claim_token', $payloadEnc);
        $this->assertArrayNotHasKey('claim_url', $payloadEnc);

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString($expectedTitle, $html);
        $this->assertStringContainsString($expectedReportLabel, $html);
        $this->assertStringContainsString("https://api.example.test/api/v0.3/attempts/{$attemptId}/report", $html);
        $this->assertStringContainsString("https://api.example.test/api/v0.3/attempts/{$attemptId}/report.pdf", $html);
        $this->assertStringContainsString('https://app.example.test/orders/lookup?', $html);
        $this->assertStringContainsString('https://app.example.test/email/preferences?token=', $html);
        $this->assertStringContainsString('https://app.example.test/email/unsubscribe?token=', $html);
        $this->assertStringContainsString('ord_report_reactivation_', $html);
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function localeProvider(): array
    {
        return [
            'en' => ['en', 'Come back to your report', 'Return to report'],
            'zh-CN' => ['zh-CN', '回来继续查看你的报告', '继续查看报告'],
        ];
    }

    private function createAttempt(string $locale): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_report_reactivation_template',
            'user_id' => null,
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'region' => 'CN_MAINLAND',
            'locale' => $locale,
            'question_count' => 144,
            'answers_summary_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'channel' => 'test',
            'started_at' => now()->subDays(20),
            'submitted_at' => now()->subDays(20),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);

        return $attemptId;
    }

    private function createOrder(string $orderNo, string $attemptId, string $email): void
    {
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_report_reactivation_template',
            'sku' => 'SKU_MBTI_FULL_REPORT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 299,
            'currency' => 'USD',
            'status' => 'paid',
            'provider' => 'billing',
            'external_trade_no' => null,
            'contact_email_hash' => $pii->emailHash($email),
            'meta_json' => json_encode([
                'attribution' => [
                    'share_id' => 'share_report_reactivation',
                    'compare_invite_id' => (string) Str::uuid(),
                    'share_click_id' => 'clk_report_reactivation',
                    'entrypoint' => 'report_view',
                    'referrer' => 'https://example.com/report',
                    'landing_path' => '/en/report',
                    'utm' => [
                        'source' => 'email',
                        'medium' => 'owned',
                        'campaign' => 'report-reactivation',
                        'content' => 'reactivation',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'paid_at' => now()->subDays(20),
            'fulfilled_at' => null,
            'amount_total' => 299,
            'amount_refunded' => 0,
            'item_sku' => 'SKU_MBTI_FULL_REPORT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'refunded_at' => null,
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ]);
    }

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }
}
