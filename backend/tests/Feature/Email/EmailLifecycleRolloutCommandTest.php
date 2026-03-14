<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailSubscriber;
use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailLifecycleRolloutService;
use App\Services\Email\EmailOutboxService;
use App\Services\Email\EmailPreferenceService;
use App\Support\PiiCipher;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailLifecycleRolloutCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_candidates_without_writing_outbox_rows(): void
    {
        $this->updatePreferencesFor('dry-run@example.com', [
            'marketing_updates' => true,
            'report_recovery' => true,
            'product_updates' => false,
        ]);

        $this->artisan('email:lifecycle-rollout --dry-run')
            ->expectsOutput('Candidates: 1')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 1, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->expectsOutput('Dry run: no outbox rows were enqueued.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('email_outbox')->count());
    }

    public function test_command_enqueues_preferences_updated_confirmation_for_eligible_subscriber(): void
    {
        $this->updatePreferencesFor('preferences+rollout@example.com', [
            'marketing_updates' => true,
            'report_recovery' => true,
            'product_updates' => false,
        ]);

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 1')
            ->expectsOutput('Enqueued: 1')
            ->expectsOutput('preferences_updated => candidates 1, enqueued 1')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('template', 'preferences_updated')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));
    }

    public function test_command_does_not_duplicate_existing_pending_confirmation_rows(): void
    {
        $this->unsubscribeSubscriber('unsubscribe+rollout@example.com');

        $this->artisan('email:lifecycle-rollout')->assertExitCode(0);
        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 0')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('email_outbox')->where('template', 'unsubscribe_confirmation')->count());
    }

    public function test_command_respects_lifecycle_cooldown_window(): void
    {
        $subscriber = $this->updatePreferencesFor('cooldown@example.com', [
            'marketing_updates' => true,
            'report_recovery' => true,
            'product_updates' => true,
        ]);

        $subscriber->forceFill([
            'last_lifecycle_email_sent_at' => now()->subMinutes(5),
            'last_preferences_confirmation_sent_at' => null,
        ])->save();

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 0')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('email_outbox')->count());
    }

    public function test_dry_run_counts_only_eligible_welcome_candidates(): void
    {
        $this->createWelcomeSubscriber('welcome+eligible@example.com', true, now()->subMinutes(11));
        $this->createWelcomeSubscriber('welcome+recent@example.com', true, now()->subMinutes(9));
        $this->createWelcomeSubscriber('welcome+no-consent@example.com', false, now()->subMinutes(11));

        $this->artisan('email:lifecycle-rollout --dry-run')
            ->expectsOutput('Candidates: 1')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 1, enqueued 0')
            ->expectsOutput('Dry run: no outbox rows were enqueued.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('email_outbox')->count());
    }

    public function test_command_enqueues_welcome_for_eligible_subscriber(): void
    {
        $this->createWelcomeSubscriber('welcome+enqueue@example.com', true, now()->subMinutes(11));

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 1')
            ->expectsOutput('Enqueued: 1')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 1, enqueued 1')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('template', 'welcome')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));
    }

    public function test_command_dedupes_welcome_once_ever_after_existing_sent_row(): void
    {
        $subscriber = $this->createWelcomeSubscriber('welcome+dedupe@example.com', true, now()->subMinutes(11));
        $queued = app(EmailOutboxService::class)->queueWelcome($subscriber, 'en');
        $this->assertTrue((bool) ($queued['ok'] ?? false));
        $this->assertTrue((bool) ($queued['queued'] ?? false));

        DB::table('email_outbox')
            ->where('template', 'welcome')
            ->update([
                'status' => 'sent',
                'sent_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 0')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('email_outbox')->where('template', 'welcome')->count());
    }

    public function test_command_requires_marketing_consent_for_welcome(): void
    {
        $this->createWelcomeSubscriber('welcome+marketing-disabled@example.com', false, now()->subMinutes(11));

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 0')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('email_outbox')->where('template', 'welcome')->count());
    }

    public function test_command_respects_welcome_cooldown(): void
    {
        $subscriber = $this->createWelcomeSubscriber('welcome+cooldown@example.com', true, now()->subMinutes(11));
        $this->markSubscriberInCooldown($subscriber, 'welcome', now()->subDays(2));

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 0')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('email_outbox')->where('template', 'welcome')->count());
    }

    public function test_dry_run_only_counts_eligible_post_purchase_followup_candidates(): void
    {
        $eligibleEmail = 'post-purchase+eligible@example.com';
        $eligibleSubscriber = $this->createSubscriber($eligibleEmail);
        $eligibleAttempt = $this->createAttempt('en', now()->subDays(3));
        $eligibleOrder = 'ord_post_purchase_eligible';
        $this->createOrder($eligibleOrder, $eligibleAttempt, $eligibleEmail, 'fulfilled', now()->subDays(3), now()->subDays(2));
        $this->markPaymentSuccessSent($eligibleEmail, $eligibleAttempt, $eligibleOrder);

        $recentEmail = 'post-purchase+recent@example.com';
        $this->createSubscriber($recentEmail);
        $recentAttempt = $this->createAttempt('en', now()->subHours(18));
        $recentOrder = 'ord_post_purchase_recent';
        $this->createOrder($recentOrder, $recentAttempt, $recentEmail, 'fulfilled', now()->subHours(18), now()->subHours(12));
        $this->markPaymentSuccessSent($recentEmail, $recentAttempt, $recentOrder);

        $before = DB::table('email_outbox')->count();
        $summary = app(EmailLifecycleRolloutService::class)->rollout(true);
        $this->assertSame(1, (int) data_get($summary, 'templates.post_purchase_followup.candidates', 0));
        $this->assertSame(0, (int) data_get($summary, 'templates.post_purchase_followup.enqueued', 0));

        $this->artisan('email:lifecycle-rollout --dry-run')->assertExitCode(0);

        $this->assertSame($before, DB::table('email_outbox')->count());
        $this->assertSame(0, DB::table('email_outbox')->where('template', 'post_purchase_followup')->count());
        $eligibleSubscriber->refresh();
        $this->assertNull($eligibleSubscriber->last_lifecycle_email_sent_at);
    }

    public function test_command_enqueues_post_purchase_followup_once_per_order(): void
    {
        $email = 'post-purchase+enqueue@example.com';
        $this->createSubscriber($email);
        $attemptId = $this->createAttempt('en', now()->subDays(3));
        $orderNo = 'ord_post_purchase_enqueue';
        $this->createOrder($orderNo, $attemptId, $email, 'fulfilled', now()->subDays(3), now()->subDays(2));
        $this->markPaymentSuccessSent($email, $attemptId, $orderNo);

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 1')
            ->expectsOutput('Enqueued: 1')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 1, enqueued 1')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('template', 'post_purchase_followup')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 0')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('email_outbox')->where('template', 'post_purchase_followup')->count());
    }

    public function test_command_respects_post_purchase_followup_cooldown(): void
    {
        $subscriber = $this->createSubscriber('post-purchase+cooldown@example.com');
        $attemptId = $this->createAttempt('en', now()->subDays(4));
        $orderNo = 'ord_post_purchase_cooldown';
        $this->createOrder($orderNo, $attemptId, 'post-purchase+cooldown@example.com', 'fulfilled', now()->subDays(4), now()->subDays(3));
        $this->markPaymentSuccessSent('post-purchase+cooldown@example.com', $attemptId, $orderNo);
        $this->markSubscriberInCooldown($subscriber, 'post_purchase_followup', now()->subDay());

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 0')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('email_outbox')->where('template', 'post_purchase_followup')->count());
    }

    public function test_dry_run_only_counts_eligible_report_reactivation_candidates(): void
    {
        $eligibleEmail = 'report-reactivation+eligible@example.com';
        $this->createSubscriber($eligibleEmail);
        $eligibleAttempt = $this->createAttempt('en', now()->subDays(20));
        $eligibleOrder = 'ord_report_reactivation_eligible';
        $this->createOrder($eligibleOrder, $eligibleAttempt, $eligibleEmail, 'paid', now()->subDays(20), null);
        $this->recordEvent($eligibleAttempt, 'report_view', now()->subDays(15));

        $recentEmail = 'report-reactivation+recent@example.com';
        $this->createSubscriber($recentEmail);
        $recentAttempt = $this->createAttempt('en', now()->subDays(8));
        $recentOrder = 'ord_report_reactivation_recent';
        $this->createOrder($recentOrder, $recentAttempt, $recentEmail, 'paid', now()->subDays(8), null);
        $this->recordEvent($recentAttempt, 'report_view', now()->subDays(7));

        $downloadedEmail = 'report-reactivation+downloaded@example.com';
        $this->createSubscriber($downloadedEmail);
        $downloadedAttempt = $this->createAttempt('en', now()->subDays(20));
        $downloadedOrder = 'ord_report_reactivation_downloaded';
        $this->createOrder($downloadedOrder, $downloadedAttempt, $downloadedEmail, 'fulfilled', now()->subDays(20), now()->subDays(19));
        $this->recordEvent($downloadedAttempt, 'report_view', now()->subDays(18));
        $this->recordEvent($downloadedAttempt, 'report_pdf_view', now()->subDays(17));

        $claimedEmail = 'report-reactivation+claimed@example.com';
        $this->createSubscriber($claimedEmail);
        $claimedAttempt = $this->createAttempt('en', now()->subDays(22));
        $claimedOrder = 'ord_report_reactivation_claimed';
        $this->createOrder($claimedOrder, $claimedAttempt, $claimedEmail, 'paid', now()->subDays(22), null);
        $this->recordEvent($claimedAttempt, 'report_view', now()->subDays(16));
        $this->queueReportClaim($claimedEmail, $claimedAttempt, $claimedOrder);

        $before = DB::table('email_outbox')->count();

        $this->artisan('email:lifecycle-rollout --dry-run')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 1, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->expectsOutput('Dry run: no outbox rows were enqueued.')
            ->assertExitCode(0);

        $this->assertSame($before, DB::table('email_outbox')->count());
        $this->assertSame(0, DB::table('email_outbox')->where('template', 'report_reactivation')->count());
    }

    public function test_command_enqueues_report_reactivation_once_per_order(): void
    {
        $email = 'report-reactivation+enqueue@example.com';
        $this->createSubscriber($email);
        $attemptId = $this->createAttempt('en', now()->subDays(20));
        $orderNo = 'ord_report_reactivation_enqueue';
        $this->createOrder($orderNo, $attemptId, $email, 'fulfilled', now()->subDays(20), now()->subDays(18));
        $this->recordEvent($attemptId, 'report_view', now()->subDays(15));

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 1')
            ->expectsOutput('Enqueued: 1')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 1, enqueued 1')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $row = DB::table('email_outbox')->where('template', 'report_reactivation')->first();
        $this->assertNotNull($row);
        $this->assertSame('pending', (string) ($row->status ?? ''));

        $this->artisan('email:lifecycle-rollout')
            ->expectsOutput('Candidates: 0')
            ->expectsOutput('Enqueued: 0')
            ->expectsOutput('preferences_updated => candidates 0, enqueued 0')
            ->expectsOutput('unsubscribe_confirmation => candidates 0, enqueued 0')
            ->expectsOutput('post_purchase_followup => candidates 0, enqueued 0')
            ->expectsOutput('report_reactivation => candidates 0, enqueued 0')
            ->expectsOutput('welcome => candidates 0, enqueued 0')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('email_outbox')->where('template', 'report_reactivation')->count());
    }

    private function updatePreferencesFor(string $email, array $preferences): EmailSubscriber
    {
        app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'preferences',
            'locale' => 'en',
            'share_id' => 'share_rollout',
            'entrypoint' => 'preferences_page',
        ]);

        $service = app(EmailPreferenceService::class);
        $token = $service->issueTokenForEmail($email);
        $result = $service->updateByToken($token, $preferences);
        $this->assertTrue((bool) ($result['ok'] ?? false));

        return $this->subscriberForEmail($email);
    }

    private function unsubscribeSubscriber(string $email): EmailSubscriber
    {
        app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'unsubscribe',
            'locale' => 'en',
        ]);

        $service = app(EmailPreferenceService::class);
        $token = $service->issueTokenForEmail($email);
        $result = $service->unsubscribeByToken($token, 'requested_by_user');
        $this->assertTrue((bool) ($result['ok'] ?? false));

        return $this->subscriberForEmail($email);
    }

    private function createSubscriber(string $email, string $locale = 'en'): EmailSubscriber
    {
        app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'checkout',
            'locale' => $locale,
            'entrypoint' => 'checkout_success',
        ]);

        return $this->subscriberForEmail($email);
    }

    private function createWelcomeSubscriber(string $email, bool $marketingConsent, CarbonInterface $capturedAt, string $locale = 'en'): EmailSubscriber
    {
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'welcome',
            'locale' => $locale,
            'entrypoint' => 'welcome_capture',
            'marketing_consent' => $marketingConsent,
            'share_id' => 'share_welcome_rollout',
            'share_click_id' => 'clk_welcome_rollout',
            'referrer' => 'https://example.com/help',
            'landing_path' => '/en/help',
            'utm' => [
                'source' => 'welcome',
                'medium' => 'owned',
                'campaign' => 'welcome-lifecycle',
            ],
        ]);

        $subscriber->forceFill([
            'first_captured_at' => $capturedAt,
            'last_captured_at' => $capturedAt,
        ])->save();

        return $subscriber->fresh(['preference']);
    }

    private function createAttempt(string $locale = 'en', ?CarbonInterface $submittedAt = null): string
    {
        $submittedAt = $submittedAt ?? now();
        $attemptId = (string) Str::uuid();

        DB::table('attempts')->insert([
            'id' => $attemptId,
            'ticket_code' => 'FMT-'.strtoupper(substr(str_replace('-', '', $attemptId), 0, 8)),
            'org_id' => 0,
            'anon_id' => 'anon_email_rollout',
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
            'started_at' => $submittedAt->copy()->subHour(),
            'submitted_at' => $submittedAt,
            'pack_id' => (string) config('content_packs.default_pack_id'),
            'dir_version' => (string) config('content_packs.default_dir_version'),
            'content_package_version' => 'v0.3',
            'scoring_spec_version' => '2026.01',
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ]);

        return $attemptId;
    }

    private function createOrder(
        string $orderNo,
        string $attemptId,
        string $email,
        string $status,
        ?CarbonInterface $paidAt,
        ?CarbonInterface $fulfilledAt
    ): void {
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'order_no' => $orderNo,
            'org_id' => 0,
            'user_id' => null,
            'anon_id' => 'anon_email_rollout',
            'sku' => 'SKU_MBTI_FULL_REPORT',
            'quantity' => 1,
            'target_attempt_id' => $attemptId,
            'amount_cents' => 299,
            'currency' => 'USD',
            'status' => $status,
            'provider' => 'billing',
            'external_trade_no' => null,
            'contact_email_hash' => $pii->emailHash($email),
            'meta_json' => json_encode([
                'attribution' => [
                    'share_id' => 'share_rollout_order',
                    'compare_invite_id' => (string) Str::uuid(),
                    'share_click_id' => 'clk_rollout_order',
                    'entrypoint' => 'checkout_success',
                    'referrer' => 'https://example.com/checkout',
                    'landing_path' => '/en/checkout',
                    'utm' => [
                        'source' => 'checkout',
                        'medium' => 'owned',
                        'campaign' => 'lifecycle-rollout',
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'paid_at' => $paidAt,
            'fulfilled_at' => $fulfilledAt,
            'amount_total' => 299,
            'amount_refunded' => 0,
            'item_sku' => 'SKU_MBTI_FULL_REPORT',
            'provider_order_id' => null,
            'device_id' => null,
            'request_id' => null,
            'created_ip' => null,
            'refunded_at' => null,
            'created_at' => $paidAt ?? $fulfilledAt ?? now(),
            'updated_at' => $paidAt ?? $fulfilledAt ?? now(),
        ]);
    }

    private function markPaymentSuccessSent(string $email, string $attemptId, string $orderNo, int $times = 1): void
    {
        /** @var EmailOutboxService $outbox */
        $outbox = app(EmailOutboxService::class);

        for ($i = 0; $i < $times; $i++) {
            $queued = $outbox->queuePaymentSuccess(
                'payment_success_'.$i.'_'.$orderNo,
                $email,
                $attemptId,
                $orderNo,
                'MBTI Full Report'
            );
            $this->assertTrue((bool) ($queued['ok'] ?? false));

            $rowId = DB::table('email_outbox')
                ->where('template', 'payment_success')
                ->where('attempt_id', $attemptId)
                ->orderByDesc('created_at')
                ->value('id');

            DB::table('email_outbox')
                ->where('id', $rowId)
                ->update([
                    'status' => 'sent',
                    'sent_at' => now()->subDays(2),
                    'updated_at' => now()->subDays(2),
                ]);
        }
    }

    private function queueReportClaim(string $email, string $attemptId, string $orderNo): void
    {
        $queued = app(EmailOutboxService::class)->queueReportClaim(
            'report_claim_'.$orderNo,
            $email,
            $attemptId,
            $orderNo
        );
        $this->assertTrue((bool) ($queued['ok'] ?? false));
    }

    private function recordEvent(string $attemptId, string $eventCode, CarbonInterface $occurredAt): void
    {
        DB::table('events')->insert([
            'id' => (string) Str::uuid(),
            'event_code' => $eventCode,
            'event_name' => $eventCode,
            'user_id' => null,
            'anon_id' => 'anon_email_rollout',
            'scale_code' => 'MBTI',
            'scale_version' => 'v0.3',
            'attempt_id' => $attemptId,
            'channel' => 'test',
            'region' => 'CN_MAINLAND',
            'locale' => 'en',
            'client_platform' => 'test',
            'client_version' => '1.0.0',
            'meta_json' => json_encode(['seed' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function markSubscriberInCooldown(EmailSubscriber $subscriber, string $templateKey, CarbonInterface $sentAt): void
    {
        $subscriber->forceFill([
            'last_lifecycle_email_sent_at' => $sentAt,
        ]);

        if (Schema::hasColumn('email_subscribers', 'last_lifecycle_template_key')) {
            $subscriber->setAttribute('last_lifecycle_template_key', $templateKey);
        }

        if (Schema::hasColumn('email_subscribers', 'next_lifecycle_eligible_at')) {
            $subscriber->setAttribute(
                'next_lifecycle_eligible_at',
                EmailLifecycleRolloutService::nextEligibleAtForTemplate($templateKey, $sentAt)
            );
        }

        $subscriber->save();
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
