<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Services\Commerce\PaymentGateway\AppleIapGateway;
use Illuminate\Http\Request;

final class AppleIapPaymentService
{
    public function __construct(private readonly WechatMiniVirtualPaymentService $wechatVirtual) {}

    public function validateOrderEligibility(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        ?string $targetAttemptId,
        string $sku,
        int $quantity
    ): array {
        return $this->wechatVirtual->validateOrderEligibility(
            $orgId,
            $userId,
            $anonId,
            $targetAttemptId,
            $sku,
            $quantity,
            AppleIapGateway::PROVIDER
        );
    }

    public function createPaymentAction(object $order, string $loginCode): array
    {
        return $this->wechatVirtual->createPaymentAction($order, $loginCode, AppleIapGateway::PROVIDER);
    }

    public function reconcile(object $order): array
    {
        return $this->wechatVirtual->reconcile($order, AppleIapGateway::PROVIDER);
    }

    public function handleCallback(Request $request): array
    {
        return $this->wechatVirtual->handleCallback($request, AppleIapGateway::PROVIDER);
    }
}
