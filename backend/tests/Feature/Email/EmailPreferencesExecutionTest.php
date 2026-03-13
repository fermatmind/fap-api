<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailPreference;
use App\Models\EmailSubscriber;
use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailOutboxService;
use App\Services\Email\EmailPreferenceService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailPreferencesExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preference_update_writes_foundation_state_and_rollout_command_enqueues_confirmation(): void
    {
        $email = 'preferences+foundation@example.com';
        app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'preferences',
            'locale' => 'en',
            'share_id' => 'share_preferences_exec',
        ]);

        /** @var EmailPreferenceService $service */
        $service = app(EmailPreferenceService::class);
        $token = $service->issueTokenForEmail($email);
        $response = $service->updateByToken($token, [
            'marketing_updates' => true,
            'report_recovery' => true,
            'product_updates' => false,
        ]);

        $this->assertTrue((bool) ($response['ok'] ?? false));
        $subscriber = $this->subscriberForEmail($email);
        $this->assertNotNull($subscriber->last_preferences_changed_at);
        $this->assertSame(0, DB::table('email_outbox')->count());

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 1')
            ->expectsOutput('Enqueued: 1')
            ->expectsOutput('preferences_updated => candidates 1, enqueued 1')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('template', 'preferences_updated')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));
    }

    public function test_report_recovery_true_allows_transactional_delivery_even_when_marketing_flags_are_false(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $attemptId = $this->createAttempt('en');
        $email = 'preferences+allow@example.com';

        $capture = app(EmailCaptureService::class)->capture($email, ['surface' => 'lookup']);
        EmailPreference::query()
            ->where('subscriber_id', (string) ($capture['subscriber_id'] ?? ''))
            ->update([
                'marketing_updates' => false,
                'report_recovery' => true,
                'product_updates' => false,
            ]);

        $queued = app(EmailOutboxService::class)->queuePaymentSuccess(
            'preferences_allow_user',
            $email,
            $attemptId,
            'ord_preferences_allow_001',
            'MBTI Full Report'
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 1, blocked 0, failed 0.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('attempt_id', $attemptId)->where('template', 'payment_success')->first();
        $this->assertNotNull($row);
        $this->assertSame('sent', (string) ($row->status ?? ''));
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_report_recovery_false_blocks_report_delivery_templates_but_marketing_flags_do_not_control_them(): void
    {
        config([
            'fap.runtime.EMAIL_OUTBOX_SEND' => true,
            'mail.default' => 'array',
        ]);

        $this->flushArrayTransport();
        $attemptId = $this->createAttempt('en');
        $email = 'preferences+block@example.com';

        $capture = app(EmailCaptureService::class)->capture($email, ['surface' => 'lookup']);
        EmailPreference::query()
            ->where('subscriber_id', (string) ($capture['subscriber_id'] ?? ''))
            ->update([
                'marketing_updates' => true,
                'report_recovery' => false,
                'product_updates' => true,
            ]);

        $queued = app(EmailOutboxService::class)->queuePaymentSuccess(
            'preferences_block_user',
            $email,
            $attemptId,
            'ord_preferences_block_001',
            'MBTI Full Report'
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));

        $this->artisan('email:outbox-send --limit=10')
            ->expectsOutput('Mailer array: sent 0, blocked 1, failed 0.')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('attempt_id', $attemptId)->where('template', 'payment_success')->first();
        $this->assertNotNull($row);
        $this->assertSame('skipped', (string) ($row->status ?? ''));
        $this->assertSame(0, Mail::mailer('array')->getSymfonyTransport()->messages()->count());
    }

    private function createAttempt(string $locale): string
    {
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_pref_exec',
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

    private function subscriberForEmail(string $email): EmailSubscriber
    {
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        /** @var EmailSubscriber $subscriber */
        $subscriber = EmailSubscriber::query()
            ->where('email_hash', $pii->emailHash($email))
            ->firstOrFail();

        return $subscriber;
    }
}
