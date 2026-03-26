<?php

declare(strict_types=1);

namespace App\Services\Commerce\Compensation\Contracts;

interface PaymentLifecycleGatewayInterface
{
    public function provider(): string;

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *     ok:bool,
     *     supported:bool,
     *     status:string,
     *     provider_trade_no:?string,
     *     paid_at:?string,
     *     queried_at:string,
     *     raw_state:?string,
     *     is_terminal:bool,
     *     supports_close:bool,
     *     reason:?string
     * }
     */
    public function queryPaymentStatus(array $context): array;

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *     ok:bool,
     *     supported:bool,
     *     status:string,
     *     provider_trade_no:?string,
     *     closed_at:?string,
     *     raw_state:?string,
     *     is_terminal:bool,
     *     supports_close:bool,
     *     reason:?string
     * }
     */
    public function closePayment(array $context): array;
}
