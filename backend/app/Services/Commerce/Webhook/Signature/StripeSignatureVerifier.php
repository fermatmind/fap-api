<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Signature;

use App\Services\Commerce\PaymentGateway\StripeGateway;
use App\Services\Commerce\Webhook\Contracts\WebhookSignatureVerifierInterface;
use Illuminate\Http\Request;

final class StripeSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function provider(): string
    {
        return 'stripe';
    }

    public function verify(Request $request): bool
    {
        return (new StripeGateway())->verifySignature($request);
    }
}
