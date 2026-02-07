<?php

declare(strict_types=1);

namespace Tests\Unit\API\V0_3\Webhooks;

use App\Http\Controllers\API\V0_3\Webhooks\PaymentWebhookController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

final class PaymentWebhookStripeSignatureTest extends TestCase
{
    public function test_verify_stripe_signature_accepts_valid_signature(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        config()->set('services.stripe.webhook_tolerance_seconds', 300);

        $payload = '{"id":"evt_test","type":"payment_intent.succeeded"}';
        $timestamp = time();
        $header = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_test');

        $request = Request::create('/api/v0.3/webhooks/payment/stripe', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $controller = app(PaymentWebhookController::class);
        $method = new ReflectionMethod($controller, 'verifyStripeSignature');
        $method->setAccessible(true);

        $ok = (bool) $method->invoke($controller, $request, '');

        $this->assertTrue($ok);
    }

    public function test_verify_stripe_signature_rejects_old_timestamp(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        config()->set('services.stripe.webhook_tolerance_seconds', 300);

        $payload = '{"id":"evt_test","type":"payment_intent.succeeded"}';
        $timestamp = time() - 1000;
        $header = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, 'whsec_test');

        $request = Request::create('/api/v0.3/webhooks/payment/stripe', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $controller = app(PaymentWebhookController::class);
        $method = new ReflectionMethod($controller, 'verifyStripeSignature');
        $method->setAccessible(true);

        $ok = (bool) $method->invoke($controller, $request, null);

        $this->assertFalse($ok);
    }
}
