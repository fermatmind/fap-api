<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Contracts;

interface OrderStateMachineInterface
{
    /**
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>
     */
    public function advance(string $orderNo, int $orgId, array $normalized): array;
}
