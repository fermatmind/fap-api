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

final class OnboardingTemplateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('localeProvider')]
    public function test_onboarding_template_renders_supported_locales(
        string $locale,
        string $expectedSubject,
        string $expectedReportLabel,
        string $expectedHelpLabel,
        string $expectedHelpPath
    ): void {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();

        $email = 'onboarding+'.md5($locale).'@example.com';
        $attemptId = $this->createAttempt($locale);
        $orderNo = 'ord_onboarding_'.Str::lower(Str::random(8));
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'report',
            'locale' => $locale,
            'entrypoint' => 'report_view',
            'marketing_consent' => true,
        ]);
        $this->createOrder($orderNo, $attemptId, $email);

        $queued = app(EmailOutboxService::class)->queueOnboarding($subscriber, $attemptId, $orderNo, $locale);
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')->where('template', 'onboarding')->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_onboarding', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('owned', (string) data_get($payloadJson, 'attribution.utm.medium'));
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);
        $this->assertArrayNotHasKey('claim_token', $payloadJson);
        $this->assertArrayNotHasKey('claim_url', $payloadJson);
        $this->assertArrayNotHasKey('report_pdf_url', $payloadJson);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('share_onboarding', (string) data_get($payloadEnc, 'attribution.share_id'));
        $this->assertArrayNotHasKey('claim_token', $payloadEnc);
        $this->assertArrayNotHasKey('claim_url', $payloadEnc);
        $this->assertArrayNotHasKey('report_pdf_url', $payloadEnc);

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();
        $this->assertSame($expectedSubject, (string) $message->getSubject());
        $this->assertStringContainsString($expectedSubject, $html);
        $this->assertStringContainsString($expectedReportLabel, $html);
        $this->assertStringContainsString($expectedHelpLabel, $html);
        $this->assertStringContainsString("https://api.example.test/api/v0.3/attempts/{$attemptId}/report", $html);
        $this->assertStringContainsString('https://app.example.test/orders/lookup?', $html);
        $this->assertStringContainsString('https://app.example.test/email/preferences?token=', $html);
        $this->assertStringContainsString('https://app.example.test/email/unsubscribe?token=', $html);
        $this->assertStringContainsString('https://app.example.test/'.$expectedHelpPath.'?', $html);
        $this->assertStringContainsString('share_id=share_onboarding', $html);
        $this->assertStringContainsString('utm_campaign=first-report-view-onboarding', $html);
        $this->assertStringContainsString('compare_invite_id=', $html);
        $this->assertStringNotContainsString('report.pdf', $html);
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function localeProvider(): array
    {
        return [
            'en' => ['en', 'How to get more from your FermatMind report', 'Return to your report', 'Get help', 'en/help'],
            'zh-CN' => ['zh-CN', '如何更好地使用你的 FermatMind 报告', '返回报告', '获取帮助', 'zh/help'],
        ];
    }

    private function createAttempt(string $locale): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_onboarding_template',
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
            'started_at' => now()->subDays(3),
            'submitted_at' => now()->subDays(3),
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
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
            'anon_id' => 'anon_onboarding_template',
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
                    'share_id' => 'share_onboarding',
                    'compare_invite_id' => (string) Str::uuid(),
                    'share_click_id' => 'clk_onboarding',
                    'entrypoint' => 'report_view',
                    'referrer' => 'https://example.com/report',
                    'landing_path' => '/en/report',
                    'utm' => [
                        'source' => 'report',
                        'medium' => 'owned',
                        'campaign' => 'first-report-view-onboarding',
                        'content' => 'template',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'paid_at' => now()->subDays(3),
            'fulfilled_at' => now()->subDays(2),
            'amount_total' => 299,
            'amount_refunded' => 0,
            'item_sku' => 'SKU_MBTI_FULL_REPORT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'refunded_at' => null,
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(2),
        ]);
    }

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }
}
