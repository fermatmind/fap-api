<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailCaptureSubscriberFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_capture_persists_subscriber_lifecycle_foundation(): void
    {
        $email = 'foundation+'.random_int(1000, 9999).'@example.com';
        $compareInviteId = (string) Str::uuid();

        $response = $this->postJson('/api/v0.3/email/capture', [
            'email' => $email,
            'surface' => 'checkout',
            'attempt_id' => (string) Str::uuid(),
            'share_id' => 'share_foundation',
            'compare_invite_id' => $compareInviteId,
            'share_click_id' => 'clk_foundation',
            'entrypoint' => 'share_page',
            'referrer' => 'https://example.com/share/foundation',
            'landing_path' => '/zh/share/foundation',
            'utm' => [
                'source' => 'share',
                'medium' => 'organic',
                'campaign' => 'subscriber-foundation',
                'term' => 'mbti',
                'content' => 'hero',
            ],
            'marketing_consent' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'captured')
            ->assertJsonPath('subscriber_status', 'active')
            ->assertJsonPath('marketing_consent', true)
            ->assertJsonPath('transactional_recovery_enabled', true)
            ->assertJsonPath('preferences.marketing_updates', true)
            ->assertJsonPath('preferences.report_recovery', true)
            ->assertJsonPath('preferences.product_updates', true);

        $capturedAt = (string) $response->json('captured_at');
        $this->assertNotSame('', $capturedAt);
        $this->assertFalse(array_key_exists('email', $response->json()));
        $this->assertFalse(array_key_exists('email_hash', $response->json()));

        $subscriber = DB::table('email_subscribers')->first();
        $this->assertNotNull($subscriber);
        $this->assertSame('active', (string) ($subscriber->status ?? ''));
        $this->assertTrue((bool) ($subscriber->marketing_consent ?? false));
        $this->assertTrue((bool) ($subscriber->transactional_recovery_enabled ?? false));
        $this->assertSame('checkout', (string) ($subscriber->first_source ?? ''));
        $this->assertSame('checkout', (string) ($subscriber->last_source ?? ''));
        $this->assertNotNull($subscriber->first_captured_at);
        $this->assertNotNull($subscriber->last_captured_at);
        $this->assertNotNull($subscriber->last_marketing_consent_at);
        $this->assertNull($subscriber->last_transactional_recovery_change_at);

        $firstContext = json_decode((string) ($subscriber->first_context_json ?? '{}'), true);
        $lastContext = json_decode((string) ($subscriber->last_context_json ?? '{}'), true);
        $this->assertIsArray($firstContext);
        $this->assertIsArray($lastContext);
        $this->assertSame('clk_foundation', (string) ($firstContext['share_click_id'] ?? ''));
        $this->assertSame($compareInviteId, (string) ($lastContext['compare_invite_id'] ?? ''));
        $this->assertSame(true, $lastContext['marketing_consent'] ?? null);
        $this->assertArrayNotHasKey('transactional_recovery_enabled', $lastContext);

        $preferences = DB::table('email_preferences')
            ->where('subscriber_id', $subscriber->id)
            ->first();
        $this->assertNotNull($preferences);
        $this->assertTrue((bool) ($preferences->marketing_updates ?? false));
        $this->assertTrue((bool) ($preferences->report_recovery ?? false));
        $this->assertTrue((bool) ($preferences->product_updates ?? false));
    }

    public function test_public_email_capture_cannot_disable_transactional_recovery(): void
    {
        $response = $this->postJson('/api/v0.3/email/capture', [
            'email' => 'public-capture-disable@example.com',
            'surface' => 'checkout',
            'marketing_consent' => true,
            'transactional_recovery_enabled' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transactional_recovery_enabled', true)
            ->assertJsonPath('preferences.report_recovery', true);

        $subscriber = DB::table('email_subscribers')->first();
        $this->assertNotNull($subscriber);
        $this->assertTrue((bool) ($subscriber->transactional_recovery_enabled ?? false));
        $this->assertNull($subscriber->last_transactional_recovery_change_at);

        $preferences = DB::table('email_preferences')
            ->where('subscriber_id', $subscriber->id)
            ->first();
        $this->assertNotNull($preferences);
        $this->assertTrue((bool) ($preferences->report_recovery ?? false));

        $lastContext = json_decode((string) ($subscriber->last_context_json ?? '{}'), true);
        $this->assertIsArray($lastContext);
        $this->assertArrayNotHasKey('transactional_recovery_enabled', $lastContext);
    }

    public function test_public_email_capture_cannot_override_token_backed_recovery_preference(): void
    {
        $email = 'public-capture-existing-disabled@example.com';
        app(EmailCaptureService::class)->ensureSubscriber($email, [
            'surface' => 'preferences',
            'marketing_consent' => true,
        ]);

        /** @var EmailPreferenceService $preferences */
        $preferences = app(EmailPreferenceService::class);
        $token = $preferences->issueTokenForEmail($email);
        $updated = $preferences->updateByToken($token, [
            'marketing_updates' => true,
            'report_recovery' => false,
            'product_updates' => true,
        ]);
        $this->assertTrue((bool) ($updated['ok'] ?? false));

        $response = $this->postJson('/api/v0.3/email/capture', [
            'email' => $email,
            'surface' => 'checkout',
            'marketing_consent' => true,
            'transactional_recovery_enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('transactional_recovery_enabled', false)
            ->assertJsonPath('preferences.report_recovery', false);

        $policy = $preferences->deliveryPolicyForEmail($email, 'payment_success');
        $this->assertFalse((bool) ($policy['allowed'] ?? true));
        $this->assertSame('report_recovery_disabled', (string) ($policy['reason'] ?? ''));
    }
}
