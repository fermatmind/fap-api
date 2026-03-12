<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Email\EmailPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EmailPreferencesContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_preferences_get_returns_masked_email_and_defaults(): void
    {
        /** @var EmailPreferenceService $preferences */
        $preferences = app(EmailPreferenceService::class);
        $token = $preferences->issueTokenForEmail('buyer@example.com', [
            'surface' => 'help',
            'entrypoint' => 'help_center',
        ]);

        $response = $this->getJson('/api/v0.3/email/preferences?token='.urlencode($token));

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('email_masked', 'bu***@example.com')
            ->assertJsonPath('preferences.marketing_updates', false)
            ->assertJsonPath('preferences.report_recovery', true)
            ->assertJsonPath('preferences.product_updates', false);
    }

    public function test_email_preferences_post_updates_contract_and_subscriber_state(): void
    {
        /** @var EmailPreferenceService $preferences */
        $preferences = app(EmailPreferenceService::class);
        $token = $preferences->issueTokenForEmail('buyer@example.com');

        $response = $this->postJson('/api/v0.3/email/preferences', [
            'token' => $token,
            'marketing_updates' => true,
            'report_recovery' => false,
            'product_updates' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('preferences.marketing_updates', true)
            ->assertJsonPath('preferences.report_recovery', false)
            ->assertJsonPath('preferences.product_updates', true);

        $subscriber = DB::table('email_subscribers')->first();
        $preference = DB::table('email_preferences')->first();
        $this->assertNotNull($subscriber);
        $this->assertNotNull($preference);
        $this->assertTrue((bool) ($subscriber->marketing_consent ?? false));
        $this->assertFalse((bool) ($subscriber->transactional_recovery_enabled ?? true));
        $this->assertTrue((bool) ($preference->marketing_updates ?? false));
        $this->assertFalse((bool) ($preference->report_recovery ?? true));
        $this->assertTrue((bool) ($preference->product_updates ?? false));
    }
}
