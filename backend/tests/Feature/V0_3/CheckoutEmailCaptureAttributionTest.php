<?php

declare(strict_types=1);

namespace Tests\Feature\V0_3;

use Database\Seeders\Pr19CommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CheckoutEmailCaptureAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_persists_email_capture_and_attribution_foundation(): void
    {
        $this->seedCommerce();

        $email = 'checkout+'.random_int(1000, 9999).'@example.com';
        $compareInviteId = (string) Str::uuid();
        $attemptId = (string) Str::uuid();

        $response = $this->postJson('/api/v0.3/orders/checkout', [
            'sku' => 'MBTI_CREDIT',
            'provider' => 'billing',
            'email' => $email,
            'attempt_id' => $attemptId,
            'surface' => 'checkout',
            'marketing_consent' => true,
            'transactional_recovery_enabled' => false,
            'share_id' => 'share_checkout',
            'compare_invite_id' => $compareInviteId,
            'share_click_id' => 'clk_checkout',
            'entrypoint' => 'share_page',
            'referrer' => 'https://example.com/share/checkout',
            'landing_path' => '/zh/share/checkout',
            'utm' => [
                'source' => 'share',
                'medium' => 'organic',
                'campaign' => 'checkout-foundation',
                'term' => 'mbti',
                'content' => 'hero',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'billing')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('pay', null)
            ->assertJsonPath('checkout_url', null);

        $orderNo = (string) $response->json('order_no');
        $this->assertNotSame('', $orderNo);

        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        $this->assertNotNull($order);
        $this->assertSame(hash('sha256', $email), (string) ($order->contact_email_hash ?? ''));

        $meta = json_decode((string) ($order->meta_json ?? '{}'), true);
        $this->assertIsArray($meta);
        $this->assertSame('share_checkout', (string) data_get($meta, 'attribution.share_id'));
        $this->assertSame($compareInviteId, (string) data_get($meta, 'attribution.compare_invite_id'));
        $this->assertSame('clk_checkout', (string) data_get($meta, 'attribution.share_click_id'));
        $this->assertSame('organic', (string) data_get($meta, 'attribution.utm.medium'));
        $this->assertSame(hash('sha256', $email), (string) data_get($meta, 'email_capture.contact_email_hash'));
        $this->assertSame('active', (string) data_get($meta, 'email_capture.subscriber_status'));
        $this->assertSame(true, data_get($meta, 'email_capture.marketing_consent'));
        $this->assertSame(false, data_get($meta, 'email_capture.transactional_recovery_enabled'));
        $this->assertSame('checkout', (string) data_get($meta, 'email_capture.surface'));
        $this->assertSame($attemptId, (string) data_get($meta, 'email_capture.attempt_id'));
    }

    public function test_checkout_legacy_contract_remains_compatible_without_lifecycle_fields(): void
    {
        $this->seedCommerce();

        $response = $this->postJson('/api/v0.3/orders/checkout', [
            'sku' => 'MBTI_CREDIT',
            'provider' => 'billing',
            'email' => 'legacy-checkout@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider', 'billing')
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('pay', null)
            ->assertJsonPath('checkout_url', null);

        $this->assertNotSame('', (string) $response->json('order_no'));
    }

    private function seedCommerce(): void
    {
        (new Pr19CommerceSeeder)->run();
    }
}
