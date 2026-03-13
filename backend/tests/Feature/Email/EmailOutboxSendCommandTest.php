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

    private function flushArrayTransport(): void
    {
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }
}
