<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Signature;

use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\Webhook\Contracts\WebhookSignatureVerifierInterface;
use Illuminate\Http\Request;

final class BillingSignatureVerifier implements WebhookSignatureVerifierInterface
{
    public function provider(): string
    {
        return 'billing';
    }

    public function verify(Request $request): bool
    {
        return (new BillingGateway())->verifySignature($request);
    }
}
