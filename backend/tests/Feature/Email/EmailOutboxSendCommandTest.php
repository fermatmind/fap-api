<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailOutboxService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

final class EmailOutboxSendCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_uses_configured_mailer_and_advances_sent_rows(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $attemptId = $this->createAttempt('zh-CN');

        $queued = app(EmailOutboxService::class)->queuePaymentSuccess(
            'command_send_user',
            'buyer+send@example.com',
            $attemptId,
            'ord_send_001',
            'MBTI Full Report'
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('sent', (string) ($row->status ?? ''));
        $this->assertNotNull($row->sent_at ?? null);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $masked = $pii->legacyEmailPlaceholder($pii->emailHash('buyer+send@example.com'));
        $this->assertSame($masked, (string) ($row->to_email ?? ''));

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $message = $messages->first()->getOriginalMessage();
        $this->assertSame('支付成功与报告交付通知', (string) $message->getSubject());
        $this->assertStringContainsString('查看报告', (string) $message->getHtmlBody());
    }

    public function test_command_safely_skips_when_delivery_is_disabled(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => false,
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $attemptId = $this->createAttempt();

        $queued = app(EmailOutboxService::class)->queuePaymentSuccess(
            'command_skip_user',
            'buyer+skip@example.com',
            $attemptId,
            'ord_skip_001',
            'MBTI Full Report'
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('EMAIL_OUTBOX_SEND=0 (disabled)')
            ->assertExitCode(0);

        $this->assertSame('pending', (string) DB::table('email_outbox')->where('attempt_id', $attemptId)->value('status'));
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_command_marks_row_failed_when_mailer_throws(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'fail_test',
            'mail.mailers.fail_test' => ['transport' => 'fail_test'],
        ]);

        app('mail.manager')->extend('fail_test', function (): TransportInterface {
            return new class implements TransportInterface
            {
                public function send(RawMessage $message, ?Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
                {
                    throw new \RuntimeException('Simulated transport failure');
                }

                public function __toString(): string
                {
                    return 'fail_test';
                }
            };
        });

        $attemptId = $this->createAttempt();
        $queued = app(EmailOutboxService::class)->queuePaymentSuccess(
            'command_fail_user',
            'buyer+fail@example.com',
            $attemptId,
            'ord_fail_001',
            'MBTI Full Report'
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer fail_test: sent 0, blocked 0, failed 1.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', 'payment_success')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('failed', (string) ($row->status ?? ''));

        $payloadJson = json_decode((string) ($row->payload_json ?? '{}'), true);
        $this->assertIsArray($payloadJson);
        $this->assertSame('send_failed', (string) data_get($payloadJson, 'delivery_execution.error_code'));
        $this->assertStringContainsString('Simulated transport failure', (string) data_get($payloadJson, 'delivery_execution.error_message'));
    }

    public function test_command_updates_lifecycle_sent_markers_for_preferences_confirmation(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $email = 'buyer+preferences-marker@example.com';
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'preferences',
            'locale' => 'en',
            'entrypoint' => 'preferences_page',
        ]);
        $subscriber->last_preferences_changed_at = now();
        $subscriber->save();

        $queued = app(EmailOutboxService::class)->queuePreferencesUpdatedConfirmation($subscriber);
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $subscriber->refresh();
        $this->assertNotNull($subscriber->last_lifecycle_email_sent_at);
        $this->assertNotNull($subscriber->last_preferences_confirmation_sent_at);

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('Your email preferences were updated', (string) $message->getSubject());
        $this->assertStringContainsString('Manage email preferences', (string) $message->getHtmlBody());
    }

    public function test_command_sends_welcome_and_updates_lifecycle_sent_markers(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $email = 'buyer+welcome-marker@example.com';
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'welcome',
            'locale' => 'en',
            'entrypoint' => 'welcome_capture',
            'marketing_consent' => true,
        ]);
        $subscriber->forceFill([
            'first_captured_at' => now()->subMinutes(11),
            'last_captured_at' => now()->subMinutes(11),
        ])->save();

        $queued = app(EmailOutboxService::class)->queueWelcome($subscriber, 'en');
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')
            ->where('template', 'welcome')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('sent', (string) ($row->status ?? ''));
        $this->assertNotNull($row->sent_at ?? null);

        $subscriber->refresh();
        $this->assertNotNull($subscriber->last_lifecycle_email_sent_at);
        if (Schema::hasColumn('email_subscribers', 'last_lifecycle_template_key')) {
            $this->assertSame('welcome', (string) $subscriber->getAttribute('last_lifecycle_template_key'));
        }
        if (Schema::hasColumn('email_subscribers', 'next_lifecycle_eligible_at')) {
            $this->assertNotNull($subscriber->getAttribute('next_lifecycle_eligible_at'));
            $this->assertTrue(
                $subscriber->getAttribute('next_lifecycle_eligible_at')->greaterThan(
                    $subscriber->last_lifecycle_email_sent_at->copy()->addDays(6)
                )
            );
        }

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame('Welcome to FermatMind', (string) $message->getSubject());
        $this->assertStringContainsString('View help center', (string) $message->getHtmlBody());
    }

    #[DataProvider('followupTemplateProvider')]
    public function test_command_sends_followup_templates_and_updates_lifecycle_sent_markers(
        string $templateKey,
        string $expectedSubject,
        string $expectedLinkLabel
    ): void {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'fap.runtime.FAP_BASE_URL' => 'https://app.example.test',
            'app.url' => 'https://api.example.test',
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $email = $templateKey.'@example.com';
        $attemptId = $this->createAttempt();
        $orderNo = 'ord_'.$templateKey.'_'.Str::lower(Str::random(8));
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'checkout',
            'locale' => 'en',
            'entrypoint' => 'checkout_success',
        ]);
        $this->createLifecycleOrder($orderNo, $attemptId, $email);

        $queued = $templateKey === 'post_purchase_followup'
            ? app(EmailOutboxService::class)->queuePostPurchaseFollowup($subscriber, $attemptId, $orderNo, 'en')
            : app(EmailOutboxService::class)->queueReportReactivation($subscriber, $attemptId, $orderNo, 'en');

        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')
            ->where('attempt_id', $attemptId)
            ->where('template', $templateKey)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame('sent', (string) ($row->status ?? ''));
        $this->assertNotNull($row->sent_at ?? null);

        $subscriber->refresh();
        $this->assertNotNull($subscriber->last_lifecycle_email_sent_at);
        if (Schema::hasColumn('email_subscribers', 'last_lifecycle_template_key')) {
            $this->assertSame($templateKey, (string) $subscriber->getAttribute('last_lifecycle_template_key'));
        }
        if (Schema::hasColumn('email_subscribers', 'next_lifecycle_eligible_at')) {
            $this->assertNotNull($subscriber->getAttribute('next_lifecycle_eligible_at'));
        }

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame($expectedSubject, (string) $message->getSubject());
        $this->assertStringContainsString($expectedLinkLabel, (string) $message->getHtmlBody());
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function followupTemplateProvider(): array
    {
        return [
            'post_purchase_followup' => ['post_purchase_followup', 'Your report is ready to view', 'View report'],
            'report_reactivation' => ['report_reactivation', 'Come back to your report', 'Return to report'],
        ];
    }

    private function createAttempt(string $locale = 'en'): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_email_send',
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

    private function createLifecycleOrder(string $orderNo, string $attemptId, string $email): void
    {
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_followup_send',
            'sku' => 'SKU_MBTI_FULL_REPORT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 299,
            'currency' => 'USD',
            'status' => 'fulfilled',
            'provider' => 'billing',
            'external_trade_no' => null,
            'contact_email_hash' => $pii->emailHash($email),
            'meta_json' => json_encode([
                'attribution' => [
                    'share_id' => 'share_followup_send',
                    'compare_invite_id' => (string) Str::uuid(),
                    'share_click_id' => 'clk_followup_send',
                    'entrypoint' => 'checkout_success',
                    'referrer' => 'https://example.com/checkout',
                    'landing_path' => '/en/checkout',
                    'utm' => [
                        'source' => 'checkout',
                        'medium' => 'owned',
                        'campaign' => 'followup-send',
                    ],
                ],
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

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }
}
