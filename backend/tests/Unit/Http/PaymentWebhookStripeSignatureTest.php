<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\API\V0_3\Webhooks\PaymentWebhookController;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

final class PaymentWebhookStripeSignatureTest extends TestCase
{
    public function test_valid_signature_passes(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        config()->set('services.stripe.webhook_tolerance_seconds', 300);

        $payload = '{"type":"ping"}';
        $ts = time();
        $sig = hash_hmac('sha256', $ts . '.' . $payload, 'whsec_test');
        $header = "t={$ts},v1={$sig}";

        $req = Request::create('/api/v0.3/webhooks/payment/stripe', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $ctl = app(PaymentWebhookController::class);

        $m = new ReflectionMethod($ctl, 'verifyStripeSignature');
        $m->setAccessible(true);

        $ok = (bool) $m->invoke($ctl, $req);
        $this->assertTrue($ok);
    }

    public function test_old_timestamp_fails(): void
    {
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        config()->set('services.stripe.webhook_tolerance_seconds', 300);

        $payload = '{"type":"ping"}';
        $ts = time() - 9999;
        $sig = hash_hmac('sha256', $ts . '.' . $payload, 'whsec_test');
        $header = "t={$ts},v1={$sig}";

        $req = Request::create('/api/v0.3/webhooks/payment/stripe', 'POST', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $ctl = app(PaymentWebhookController::class);

        $m = new ReflectionMethod($ctl, 'verifyStripeSignature');
        $m->setAccessible(true);

        $ok = (bool) $m->invoke($ctl, $req);
        $this->assertFalse($ok);
    }
}
