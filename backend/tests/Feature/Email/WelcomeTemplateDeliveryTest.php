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

final class WelcomeTemplateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('localeProvider')]
    public function test_welcome_template_renders_supported_locales(
        string $locale,
        string $expectedTitle,
        string $expectedPreferencesLabel,
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

        $email = 'welcome+template+'.md5($locale).'@example.com';
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'welcome',
            'locale' => $locale,
            'share_id' => 'share_welcome_template',
            'compare_invite_id' => (string) Str::uuid(),
            'share_click_id' => 'clk_welcome_template',
            'entrypoint' => 'welcome_capture',
            'referrer' => 'https://example.com/help',
            'landing_path' => '/en/help',
            'utm' => [
                'source' => 'welcome',
                'medium' => 'owned',
                'campaign' => 'welcome-lifecycle',
                'content' => 'template',
            ],
            'marketing_consent' => true,
        ]);
        $subscriber->forceFill([
            'first_captured_at' => now()->subMinutes(11),
            'last_captured_at' => now()->subMinutes(11),
        ])->save();

        $queued = app(EmailOutboxService::class)->queueWelcome($subscriber, $locale);
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')->where('template', 'welcome')->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);
        $this->assertArrayNotHasKey('claim_token', $payloadJson);
        $this->assertArrayNotHasKey('claim_url', $payloadJson);
        $this->assertSame('share_welcome_template', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('owned', (string) data_get($payloadJson, 'attribution.utm.medium'));

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertArrayNotHasKey('claim_token', $payloadEnc);
        $this->assertArrayNotHasKey('claim_url', $payloadEnc);
        $this->assertSame('template', (string) data_get($payloadEnc, 'attribution.utm.content'));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString($expectedTitle, $html);
        $this->assertStringContainsString($expectedPreferencesLabel, $html);
        $this->assertStringContainsString($expectedHelpLabel, $html);
        $this->assertStringContainsString('https://app.example.test/email/preferences?token=', $html);
        $this->assertStringContainsString('https://app.example.test/email/unsubscribe?token=', $html);
        $this->assertStringContainsString('https://app.example.test/orders/lookup?', $html);
        $this->assertStringContainsString('https://app.example.test/'.$expectedHelpPath.'?', $html);
        $this->assertStringContainsString('share_id=share_welcome_template', $html);
        $this->assertStringContainsString('utm_campaign=welcome-lifecycle', $html);
        $this->assertStringNotContainsString('/api/v0.3/attempts/', $html);
        $this->assertStringNotContainsString('report.pdf', $html);
    }

    /**
     * @return array<string,array{0:string,1:string,2:string,3:string,4:string}>
     */
    public static function localeProvider(): array
    {
        return [
            'en' => ['en', 'Welcome to FermatMind', 'Manage email preferences', 'View help center', 'en/help'],
            'zh-CN' => ['zh-CN', '欢迎来到 FermatMind', '管理邮件偏好', '查看帮助中心', 'zh/help'],
        ];
    }

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }
}
