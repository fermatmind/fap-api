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

        app(EmailCaptureService::class)->capture($email, [
            'surface' => 'share',
            'share_id' => 'share_123',
            'share_click_id' => 'clk_456',
            'entrypoint' => 'share_page',
            'marketing_consent' => true,
            'transactional_recovery_enabled' => true,
        ]);

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
        $this->assertSame('active', (string) ($payloadJson['subscriber_status'] ?? ''));
        $this->assertSame(true, $payloadJson['marketing_consent'] ?? null);
        $this->assertSame(true, $payloadJson['transactional_recovery_enabled'] ?? null);
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
        $this->assertSame('active', (string) ($payloadEnc['subscriber_status'] ?? ''));
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
        app(EmailCaptureService::class)->capture('claim@example.com', [
            'surface' => 'lookup',
            'entrypoint' => 'order_lookup',
            'marketing_consent' => false,
            'transactional_recovery_enabled' => true,
        ]);

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
        $this->assertSame('active', (string) ($payloadJson['subscriber_status'] ?? ''));
        $this->assertSame(false, $payloadJson['marketing_consent'] ?? null);
        $this->assertSame(true, $payloadJson['transactional_recovery_enabled'] ?? null);
        $this->assertSame('share_claim', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('order_lookup', (string) data_get($payloadJson, 'attribution.entrypoint'));

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertArrayNotHasKey('claim_token', $payloadEnc);
        $this->assertArrayNotHasKey('claim_url', $payloadEnc);

        Mail::mailer('array')->getSymfonyTransport()->flush();
        $this->artisan('email:outbox-send --limit=10')->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('share_id=share_claim', $html);
        $this->assertStringContainsString('utm_campaign=report-recovery', $html);
    }

    public function test_preferences_updated_outbox_payload_includes_attribution_contract(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $email = 'preferences+attr@example.com';

        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'preferences',
            'locale' => 'en',
            'share_id' => 'share_preferences_attr',
            'compare_invite_id' => (string) Str::uuid(),
            'share_click_id' => 'clk_preferences_attr',
            'entrypoint' => 'preferences_page',
            'referrer' => 'https://example.com/preferences',
            'landing_path' => '/en/email/preferences',
            'utm' => [
                'source' => 'settings',
                'medium' => 'owned',
                'campaign' => 'preferences-updated',
                'term' => 'email',
                'content' => 'footer',
            ],
        ]);
        $subscriber->last_preferences_changed_at = now();
        $subscriber->save();

        $queued = app(EmailOutboxService::class)->queuePreferencesUpdatedConfirmation($subscriber, 'en');
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')
            ->where('template', 'preferences_updated')
            ->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_preferences_attr', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('clk_preferences_attr', (string) data_get($payloadJson, 'attribution.share_click_id'));
        $this->assertSame('owned', (string) data_get($payloadJson, 'attribution.utm.medium'));
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('footer', (string) data_get($payloadEnc, 'attribution.utm.content'));

        Mail::mailer('array')->getSymfonyTransport()->flush();
        $this->artisan('email:outbox-send --limit=10')->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('share_id=share_preferences_attr', $html);
        $this->assertStringContainsString('utm_campaign=preferences-updated', $html);
        $this->assertStringContainsString('compare_invite_id=', $html);
    }

    public function test_post_purchase_followup_outbox_payload_includes_attribution_contract(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $attemptId = $this->createAttempt();
        $email = 'postpurchase@example.com';
        $orderNo = 'ord_attr_post_purchase';

        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'checkout',
            'locale' => 'en',
            'entrypoint' => 'checkout_success',
        ]);
        $this->createOrder($orderNo, $attemptId, $email, [
            'share_id' => 'share_post_purchase',
            'compare_invite_id' => (string) Str::uuid(),
            'share_click_id' => 'clk_post_purchase',
            'entrypoint' => 'checkout_success',
            'referrer' => 'https://example.com/checkout',
            'landing_path' => '/en/checkout',
            'utm' => [
                'source' => 'checkout',
                'medium' => 'owned',
                'campaign' => 'post-purchase-followup',
                'content' => 'receipt',
            ],
        ]);

        $queued = app(EmailOutboxService::class)->queuePostPurchaseFollowup($subscriber, $attemptId, $orderNo, 'en');
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'post_purchase_followup')
            ->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_post_purchase', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('checkout_success', (string) data_get($payloadJson, 'attribution.entrypoint'));
        $this->assertSame('owned', (string) data_get($payloadJson, 'attribution.utm.medium'));
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('receipt', (string) data_get($payloadEnc, 'attribution.utm.content'));

        Mail::mailer('array')->getSymfonyTransport()->flush();
        $this->artisan('email:outbox-send --limit=10')->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('share_id=share_post_purchase', $html);
        $this->assertStringContainsString('utm_campaign=post-purchase-followup', $html);
        $this->assertStringContainsString('compare_invite_id=', $html);
    }

    public function test_report_reactivation_outbox_payload_includes_attribution_contract(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $attemptId = $this->createAttempt();
        $email = 'reactivation@example.com';
        $orderNo = 'ord_attr_report_reactivation';

        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'report',
            'locale' => 'en',
            'entrypoint' => 'report_view',
        ]);
        $this->createOrder($orderNo, $attemptId, $email, [
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
                'content' => 'followup',
            ],
        ]);

        $queued = app(EmailOutboxService::class)->queueReportReactivation($subscriber, $attemptId, $orderNo, 'en');
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'report_reactivation')
            ->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_report_reactivation', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('report_view', (string) data_get($payloadJson, 'attribution.entrypoint'));
        $this->assertSame('owned', (string) data_get($payloadJson, 'attribution.utm.medium'));

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('followup', (string) data_get($payloadEnc, 'attribution.utm.content'));

        Mail::mailer('array')->getSymfonyTransport()->flush();
        $this->artisan('email:outbox-send --limit=10')->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('share_id=share_report_reactivation', $html);
        $this->assertStringContainsString('utm_campaign=report-reactivation', $html);
        $this->assertStringContainsString('compare_invite_id=', $html);
    }

    public function test_welcome_outbox_payload_includes_attribution_contract(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $email = 'welcome@example.com';

        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'welcome',
            'locale' => 'en',
            'share_id' => 'share_welcome_attr',
            'compare_invite_id' => (string) Str::uuid(),
            'share_click_id' => 'clk_welcome_attr',
            'entrypoint' => 'welcome_capture',
            'referrer' => 'https://example.com/help',
            'landing_path' => '/en/help',
            'utm' => [
                'source' => 'welcome',
                'medium' => 'owned',
                'campaign' => 'welcome-lifecycle',
                'term' => 'consent',
                'content' => 'hero',
            ],
            'marketing_consent' => true,
        ]);
        $subscriber->forceFill([
            'first_captured_at' => now()->subMinutes(11),
            'last_captured_at' => now()->subMinutes(11),
        ])->save();

        $queued = app(EmailOutboxService::class)->queueWelcome($subscriber, 'en');
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')
            ->where('template', 'welcome')
            ->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_welcome_attr', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('clk_welcome_attr', (string) data_get($payloadJson, 'attribution.share_click_id'));
        $this->assertSame('owned', (string) data_get($payloadJson, 'attribution.utm.medium'));
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
        $this->assertStringContainsString('share_id=share_welcome_attr', $html);
        $this->assertStringContainsString('utm_campaign=welcome-lifecycle', $html);
        $this->assertStringContainsString('compare_invite_id=', $html);
    }

    public function test_onboarding_outbox_payload_includes_attribution_contract(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $attemptId = $this->createAttempt();
        $email = 'onboarding@example.com';
        $orderNo = 'ord_attr_onboarding';

        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'report',
            'locale' => 'en',
            'entrypoint' => 'report_view',
            'marketing_consent' => true,
        ]);
        $this->createOrder($orderNo, $attemptId, $email, [
            'share_id' => 'share_onboarding_attr',
            'compare_invite_id' => (string) Str::uuid(),
            'share_click_id' => 'clk_onboarding_attr',
            'entrypoint' => 'report_view',
            'referrer' => 'https://example.com/report',
            'landing_path' => '/en/report',
            'utm' => [
                'source' => 'report',
                'medium' => 'owned',
                'campaign' => 'first-report-view-onboarding',
                'content' => 'followup',
            ],
        ]);

        $queued = app(EmailOutboxService::class)->queueOnboarding($subscriber, $attemptId, $orderNo, 'en');
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'onboarding')
            ->first();
        $this->assertNotNull($row);

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('share_onboarding_attr', (string) data_get($payloadJson, 'attribution.share_id'));
        $this->assertSame('report_view', (string) data_get($payloadJson, 'attribution.entrypoint'));
        $this->assertSame('owned', (string) data_get($payloadJson, 'attribution.utm.medium'));
        $this->assertArrayNotHasKey('email', $payloadJson);
        $this->assertArrayNotHasKey('to_email', $payloadJson);
        $this->assertArrayNotHasKey('report_pdf_url', $payloadJson);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $payloadEnc = json_decode((string) $pii->decrypt((string) ($row->payload_enc ?? '')), true);
        $this->assertIsArray($payloadEnc);
        $this->assertSame($email, (string) ($payloadEnc['to_email'] ?? ''));
        $this->assertSame('followup', (string) data_get($payloadEnc, 'attribution.utm.content'));
        $this->assertArrayNotHasKey('report_pdf_url', $payloadEnc);

        Mail::mailer('array')->getSymfonyTransport()->flush();
        $this->artisan('email:outbox-send --limit=10')->assertExitCode(0);

        $html = (string) Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('share_id=share_onboarding_attr', $html);
        $this->assertStringContainsString('utm_campaign=first-report-view-onboarding', $html);
        $this->assertStringContainsString('compare_invite_id=', $html);
        $this->assertStringNotContainsString('report.pdf', $html);
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

    /**
     * @param  array<string,mixed>  $attribution
     */
    private function createOrder(string $orderNo, string $attemptId, string $email, array $attribution): void
    {
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_attr_order',
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
                'attribution' => $attribution,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'paid_at' => now()->subDays(20),
            'fulfilled_at' => now()->subDays(19),
            'amount_total' => 299,
            'amount_refunded' => 0,
            'item_sku' => 'SKU_MBTI_FULL_REPORT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'refunded_at' => null,
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(19),
        ]);
    }
}
