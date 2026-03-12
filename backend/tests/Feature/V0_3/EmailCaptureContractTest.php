<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailCaptureContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_capture_creates_transactional_subscriber_contract(): void
    {
        $email = 'buyer+'.random_int(1000, 9999).'@example.com';

        $response = $this->postJson('/api/v0.3/email/capture', [
            'email' => $email,
            'locale' => 'zh-CN',
            'surface' => 'lookup',
            'order_no' => 'ord_capture_'.Str::lower(Str::random(6)),
            'attempt_id' => (string) Str::uuid(),
            'entrypoint' => 'order_lookup',
            'referrer' => 'https://example.com/help',
            'landing_path' => '/zh/orders/lookup',
            'utm' => [
                'source' => 'help',
                'medium' => 'owned',
                'campaign' => 'pr09',
            ],
            'marketing_consent' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'captured')
            ->assertJsonPath('marketing_consent', true)
            ->assertJsonPath('preferences.marketing_updates', true)
            ->assertJsonPath('preferences.report_recovery', true)
            ->assertJsonPath('preferences.product_updates', true);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $subscriber = DB::table('email_subscribers')->first();
        $this->assertNotNull($subscriber);
        $this->assertSame($pii->emailHash($email), (string) ($subscriber->email_hash ?? ''));
        $this->assertSame($email, $pii->decrypt((string) ($subscriber->email_enc ?? '')));
        $this->assertSame('lookup', (string) ($subscriber->first_source ?? ''));
        $this->assertSame('lookup', (string) ($subscriber->last_source ?? ''));
        $this->assertSame('zh-CN', (string) ($subscriber->locale ?? ''));
        $this->assertSame((string) $pii->currentKeyVersion(), (string) ($subscriber->pii_email_key_version ?? ''));

        $firstContext = json_decode((string) ($subscriber->first_context_json ?? '{}'), true);
        $this->assertIsArray($firstContext);
        $this->assertSame('order_lookup', (string) ($firstContext['entrypoint'] ?? ''));
        $this->assertArrayNotHasKey('email', $firstContext);

        $preferences = DB::table('email_preferences')
            ->where('subscriber_id', $subscriber->id)
            ->first();
        $this->assertNotNull($preferences);
        $this->assertTrue((bool) ($preferences->marketing_updates ?? false));
        $this->assertTrue((bool) ($preferences->report_recovery ?? false));
        $this->assertTrue((bool) ($preferences->product_updates ?? false));
    }

    public function test_email_capture_updates_existing_subscriber_without_plaintext_leak(): void
    {
        $email = 'update+'.random_int(1000, 9999).'@example.com';

        $this->postJson('/api/v0.3/email/capture', [
            'email' => $email,
            'surface' => 'lookup',
            'marketing_consent' => false,
        ])->assertOk();

        $response = $this->postJson('/api/v0.3/email/capture', [
            'email' => $email,
            'surface' => 'help',
            'entrypoint' => 'help_center',
            'marketing_consent' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'updated')
            ->assertJsonPath('marketing_consent', false)
            ->assertJsonPath('preferences.marketing_updates', false)
            ->assertJsonPath('preferences.report_recovery', true)
            ->assertJsonPath('preferences.product_updates', false);

        $subscriber = DB::table('email_subscribers')->first();
        $this->assertNotNull($subscriber);
        $this->assertSame(1, DB::table('email_subscribers')->count());
        $this->assertSame('lookup', (string) ($subscriber->first_source ?? ''));
        $this->assertSame('help', (string) ($subscriber->last_source ?? ''));
        $this->assertStringNotContainsString($email, (string) ($subscriber->last_context_json ?? ''));
    }

    public function test_email_capture_returns_suppressed_when_email_hash_is_suppressed(): void
    {
        $email = 'suppressed+'.random_int(1000, 9999).'@example.com';

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        DB::table('email_suppressions')->insert([
            'id' => (string) Str::uuid(),
            'email_hash' => $pii->emailHash($email),
            'reason' => 'bounce',
            'source' => 'mailgun',
            'meta_json' => json_encode(['code' => '550'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson('/api/v0.3/email/capture', [
            'email' => $email,
            'surface' => 'help',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'suppressed')
            ->assertJsonPath('preferences.report_recovery', true);
    }
}
