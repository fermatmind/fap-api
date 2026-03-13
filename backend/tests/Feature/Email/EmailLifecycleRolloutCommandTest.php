<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailSubscriber;
use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailPreferenceService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('email_outbox')->count());
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
