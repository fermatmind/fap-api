<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailSuppression;
use App\Services\Email\EmailCaptureService;
use App\Services\Email\EmailPreferenceService;
use App\Support\PiiCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EmailLifecycleExecutionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_suppression_blocks_both_lifecycle_confirmation_templates(): void
    {
        $email = 'suppressed+lifecycle@example.com';
        app(EmailCaptureService::class)->ensureSubscriber($email, ['surface' => 'preferences']);

        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        EmailSuppression::query()->create([
            'id' => (string) Str::uuid(),
            'email_hash' => $pii->emailHash($email),
            'reason' => 'bounce',
            'source' => 'test',
            'meta_json' => ['channel' => 'qa'],
        ]);

        $service = app(EmailPreferenceService::class);

        $preferencesPolicy = $service->deliveryPolicyForEmail($email, 'preferences_updated');
        $unsubscribePolicy = $service->deliveryPolicyForEmail($email, 'unsubscribe_confirmation');

        $this->assertFalse((bool) ($preferencesPolicy['allowed'] ?? true));
        $this->assertSame('suppressed', (string) ($preferencesPolicy['status'] ?? ''));
        $this->assertFalse((bool) ($unsubscribePolicy['allowed'] ?? true));
        $this->assertSame('suppressed', (string) ($unsubscribePolicy['status'] ?? ''));
    }

    public function test_preferences_updated_is_not_blocked_by_preference_toggles(): void
    {
        $email = 'preferences+policy@example.com';
        app(EmailCaptureService::class)->ensureSubscriber($email, ['surface' => 'preferences']);

        $service = app(EmailPreferenceService::class);
        $token = $service->issueTokenForEmail($email);
        $result = $service->updateByToken($token, [
            'marketing_updates' => false,
            'report_recovery' => false,
            'product_updates' => false,
        ]);
        $this->assertTrue((bool) ($result['ok'] ?? false));

        $policy = $service->deliveryPolicyForEmail($email, 'preferences_updated');

        $this->assertTrue((bool) ($policy['allowed'] ?? false));
        $this->assertSame('allowed', (string) ($policy['status'] ?? ''));
    }

    public function test_unsubscribe_confirmation_is_allowed_once_subscriber_is_unsubscribed(): void
    {
        $email = 'unsubscribe+policy@example.com';
        app(EmailCaptureService::class)->ensureSubscriber($email, ['surface' => 'unsubscribe']);

        $service = app(EmailPreferenceService::class);
        $token = $service->issueTokenForEmail($email);
        $result = $service->unsubscribeByToken($token, 'requested_by_user');
        $this->assertTrue((bool) ($result['ok'] ?? false));

        $policy = $service->deliveryPolicyForEmail($email, 'unsubscribe_confirmation');

        $this->assertTrue((bool) ($policy['allowed'] ?? false));
        $this->assertSame('allowed', (string) ($policy['status'] ?? ''));
    }
}
