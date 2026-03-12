<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Email\EmailPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EmailUnsubscribeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_unsubscribe_turns_off_all_preferences(): void
    {
        /** @var EmailPreferenceService $preferences */
        $preferences = app(EmailPreferenceService::class);
        $token = $preferences->issueTokenForEmail('buyer@example.com', [
            'marketing_consent' => true,
        ]);

        $response = $this->postJson('/api/v0.3/email/unsubscribe', [
            'token' => $token,
            'reason' => 'user_request',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'status' => 'unsubscribed',
            ]);

        $subscriber = DB::table('email_subscribers')->first();
        $preference = DB::table('email_preferences')->first();
        $this->assertNotNull($subscriber);
        $this->assertNotNull($preference);
        $this->assertFalse((bool) ($subscriber->marketing_consent ?? true));
        $this->assertFalse((bool) ($subscriber->transactional_recovery_enabled ?? true));
        $this->assertNotNull($subscriber->unsubscribed_at);
        $this->assertFalse((bool) ($preference->marketing_updates ?? true));
        $this->assertFalse((bool) ($preference->report_recovery ?? true));
        $this->assertFalse((bool) ($preference->product_updates ?? true));
    }
}
