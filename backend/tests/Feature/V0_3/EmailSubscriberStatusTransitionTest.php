<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Services\Email\EmailPreferenceService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EmailSubscriberStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscriber_status_transitions_from_capture_to_unsubscribe_to_suppression(): void
    {
        $email = 'status+'.random_int(1000, 9999).'@example.com';

        $capture = $this->postJson('/api/v0.3/email/capture', [
            'email' => $email,
            'surface' => 'help',
        ]);

        $capture->assertOk()
            ->assertJsonPath('subscriber_status', 'active');
        $this->assertSame('active', (string) DB::table('email_subscribers')->value('status'));

        /** @var EmailPreferenceService $preferences */
        $preferences = app(EmailPreferenceService::class);
        $token = $preferences->issueTokenForEmail($email);

        $unsubscribe = $this->postJson('/api/v0.3/email/unsubscribe', [
            'token' => $token,
            'reason' => 'user_request',
        ]);

        $unsubscribe->assertOk()
            ->assertJsonPath('status', 'unsubscribed');
        $this->assertSame('unsubscribed', (string) DB::table('email_subscribers')->value('status'));

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        DB::table('email_suppressions')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'email_hash' => $pii->emailHash($email),
            'reason' => 'bounce',
            'source' => 'mailgun',
            'meta_json' => json_encode(['code' => '550'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $suppressed = $this->postJson('/api/v0.3/email/capture', [
            'email' => $email,
            'surface' => 'lookup',
        ]);

        $suppressed->assertOk()
            ->assertJsonPath('status', 'suppressed')
            ->assertJsonPath('subscriber_status', 'suppressed');
        $this->assertSame('suppressed', (string) DB::table('email_subscribers')->value('status'));
    }
}
