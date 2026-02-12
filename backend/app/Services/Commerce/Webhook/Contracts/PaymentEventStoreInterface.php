<?php

declare(strict_types=1);

namespace App\Services\Commerce\Webhook\Contracts;

interface PaymentEventStoreInterface
{
    /**
     * @return array{ok:bool,duplicate:bool,event:array<string,mixed>}
     */
    public function begin(string $provider, string $providerEventId, array $seed): array;
}
