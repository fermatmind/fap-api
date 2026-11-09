<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailOutboxService;
use App\Services\Email\EmailPreferenceService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PreferencesUpdatedTemplateDeliveryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('localeProvider')]
    public function test_preferences_updated_template_renders_supported_locales(string $locale, string $expectedTitle, string $expectedLinkLabel): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $email = 'preferences+template+'.md5($locale).'@example.com';
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'preferences',
            'locale' => $locale,
            'share_id' => 'share_preferences_template',
            'entrypoint' => 'preferences_page',
            'utm' => [
                'source' => 'email',
                'medium' => 'owned',
                'campaign' => 'preferences-updated',
            ],
        ]);
        $subscriber->last_preferences_changed_at = now();
        $subscriber->save();

        $queued = app(EmailOutboxService::class)->queuePreferencesUpdatedConfirmation($subscriber, $locale);
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')->where('template', 'preferences_updated')->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);
        $this->assertArrayNotHasKey('claim_token', $payloadJson);
        $this->assertArrayNotHasKey('claim_url', $payloadJson);
        $this->assertSame('share_preferences_template', (string) data_get($payloadJson, 'attribution.share_id'));

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertArrayNotHasKey('claim_token', $payloadEnc);
        $this->assertArrayNotHasKey('claim_url', $payloadEnc);

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString($expectedTitle, $html);
        $this->assertStringContainsString($expectedLinkLabel, $html);
        $this->assertStringContainsString('https://app.example.test/email/preferences?token=', $html);
        $this->assertStringContainsString('https://app.example.test/email/unsubscribe?token=', $html);
        $this->assertStringContainsString('https://app.example.test/orders/lookup?', $html);
    }

    #[DataProvider('confirmationEvents')]
    public function test_taiwan_confirmation_locale_survives_the_scheduled_outbox(string $path, array $input, string $template, string $subject): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'mail.default' => 'array',
        ]);
        $this->flushArrayTransport();
        $token = app(EmailPreferenceService::class)->issueTokenForEmail('taiwan-confirmation@example.com', ['locale' => 'en']);
        $payload = ['token' => $token, 'confirmation_locale' => 'zh-TW'] + $input;

        $this->postJson('/api/v0.3/email/'.$path, array_replace($payload, ['confirmation_locale' => 'fr']))
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation_locale');
        $this->postJson('/api/v0.3/email/'.$path, $payload)->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(0, DB::table('email_outbox')->count());

        $this->artisan('email:lifecycle-rollout')->assertExitCode(0);
        $this->assertDatabaseHas('email_outbox', ['template' => $template, 'locale' => 'zh-TW', 'subject' => $subject]);
        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);
        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('lang="zh-TW"', $html);
        $this->assertStringContainsString($subject, $html);
        $this->assertStringContainsString('https://app.example.test/zh-TW/email/preferences?token=', $html);
        $this->assertStringContainsString('https://app.example.test/zh-TW/email/unsubscribe?token=', $html);
    }

    public static function confirmationEvents(): array
    {
        return [
            'update' => ['preferences', ['report_recovery' => true, 'product_updates' => true], 'preferences_updated', '電子郵件偏好已更新'],
            'unsubscribe' => ['unsubscribe', [], 'unsubscribe_confirmation', '你已取消訂閱電子郵件'],
            'all preferences disabled' => ['preferences', [], 'unsubscribe_confirmation', '你已取消訂閱電子郵件'],
        ];
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function localeProvider(): array
    {
        return [
            'en' => ['en', 'Email preferences updated', 'Manage email preferences'],
            'zh-CN' => ['zh-CN', '邮件偏好已更新', '管理邮件偏好'],
        ];
    }

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }
}
