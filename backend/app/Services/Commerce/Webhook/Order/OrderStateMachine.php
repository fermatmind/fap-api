<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Order;

use App\Services\Commerce\OrderManager;
use App\Services\Commerce\Webhook\Contracts\OrderStateMachineInterface;

final class OrderStateMachine implements OrderStateMachineInterface
{
    public function __construct(private readonly OrderManager $orders)
    {
    }

    public function advance(string $orderNo, int $orgId, array $normalized): array
    {
        $eventType = strtolower(trim((string) ($normalized['event_type'] ?? '')));

        if (str_contains($eventType, 'refund')) {
            return $this->orders->transition($orderNo, 'refunded', $orgId);
        }

        $toPaid = $this->orders->transitionToPaidAtomic(
            $orderNo,
            $orgId,
            $normalized['external_trade_no'] ?? null,
            $normalized['paid_at'] ?? null
        );

        if (!($toPaid['ok'] ?? false)) {
            return $toPaid;
        }

        return $this->orders->transition($orderNo, 'fulfilled', $orgId);
    }
}
