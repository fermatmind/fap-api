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
        $postPurchasePolicy = $service->deliveryPolicyForEmail($email, 'post_purchase_followup');
        $reportReactivationPolicy = $service->deliveryPolicyForEmail($email, 'report_reactivation');

        $this->assertFalse((bool) ($preferencesPolicy['allowed'] ?? true));
        $this->assertSame('suppressed', (string) ($preferencesPolicy['status'] ?? ''));
        $this->assertFalse((bool) ($unsubscribePolicy['allowed'] ?? true));
        $this->assertSame('suppressed', (string) ($unsubscribePolicy['status'] ?? ''));
        $this->assertFalse((bool) ($postPurchasePolicy['allowed'] ?? true));
        $this->assertSame('suppressed', (string) ($postPurchasePolicy['status'] ?? ''));
        $this->assertFalse((bool) ($reportReactivationPolicy['allowed'] ?? true));
        $this->assertSame('suppressed', (string) ($reportReactivationPolicy['status'] ?? ''));
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

    public function test_recovery_lifecycle_followups_require_transactional_recovery_to_stay_enabled(): void
    {
        $email = 'recovery+disabled@example.com';
        app(EmailCaptureService::class)->ensureSubscriber($email, ['surface' => 'checkout']);

        $service = app(EmailPreferenceService::class);
        $token = $service->issueTokenForEmail($email);
        $result = $service->updateByToken($token, [
            'marketing_updates' => true,
            'report_recovery' => false,
            'product_updates' => true,
        ]);
        $this->assertTrue((bool) ($result['ok'] ?? false));

        $postPurchasePolicy = $service->deliveryPolicyForEmail($email, 'post_purchase_followup');
        $reportReactivationPolicy = $service->deliveryPolicyForEmail($email, 'report_reactivation');

        $this->assertFalse((bool) ($postPurchasePolicy['allowed'] ?? true));
        $this->assertSame('report_recovery_disabled', (string) ($postPurchasePolicy['reason'] ?? ''));
        $this->assertFalse((bool) ($reportReactivationPolicy['allowed'] ?? true));
        $this->assertSame('report_recovery_disabled', (string) ($reportReactivationPolicy['reason'] ?? ''));
    }

    public function test_recovery_lifecycle_followups_require_active_subscriber_status(): void
    {
        $email = 'recovery+inactive@example.com';
        $subscriber = app(EmailCaptureService::class)->ensureSubscriber($email, ['surface' => 'checkout']);
        $subscriber->forceFill([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
            'marketing_consent' => true,
            'transactional_recovery_enabled' => true,
        ])->save();

        $service = app(EmailPreferenceService::class);
        $postPurchasePolicy = $service->deliveryPolicyForEmail($email, 'post_purchase_followup');
        $reportReactivationPolicy = $service->deliveryPolicyForEmail($email, 'report_reactivation');

        $this->assertFalse((bool) ($postPurchasePolicy['allowed'] ?? true));
        $this->assertSame('subscriber_inactive', (string) ($postPurchasePolicy['reason'] ?? ''));
        $this->assertFalse((bool) ($reportReactivationPolicy['allowed'] ?? true));
        $this->assertSame('subscriber_inactive', (string) ($reportReactivationPolicy['reason'] ?? ''));
    }
}
