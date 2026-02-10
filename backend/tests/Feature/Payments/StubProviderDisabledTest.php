<?php

namespace Tests\Feature\Payments;

use Tests\TestCase;

class StubProviderDisabledTest extends TestCase
{
    public function test_stub_routes_return_404_by_default(): void
    {
        $this->postJson('/api/v0.3/orders/stub', [
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
        ])->assertStatus(404);

        $this->postJson('/api/v0.3/webhooks/payment/stub', [])->assertStatus(404);
    }

    public function test_stub_routes_still_return_404_when_allow_stub_enabled_in_testing(): void
    {
        $this->assertTrue(app()->environment(['testing', 'ci']));
        config(['payments.allow_stub' => true]);

        $this->postJson('/api/v0.3/orders/stub', [
            'sku' => 'MBTI_CREDIT',
            'quantity' => 1,
        ])->assertStatus(404);

        $this->postJson('/api/v0.3/webhooks/payment/stub', [])->assertStatus(404);
    }
}
