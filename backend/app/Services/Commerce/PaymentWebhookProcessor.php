<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Services\Analytics\EventRecorder;
use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\PaymentGateway\StripeGateway;
use App\Services\Commerce\Webhook\PaymentWebhookHandler;
use App\Services\Observability\BigFiveTelemetry;
use App\Services\Report\ReportSnapshotStore;
use Illuminate\Support\Facades\DB;

/**
 * Thin facade kept for backwards compatibility.
 *
 * - Keeps the historical constructor and handle signature used across tests/controllers.
 * - Delegates all business logic to PaymentWebhookHandler.
 */
class PaymentWebhookProcessor
{
    private PaymentWebhookHandler $handler;

    public function __construct(
        private OrderManager $orders,
        private SkuCatalog $skus,
        private BenefitWalletService $wallets,
        private EntitlementManager $entitlements,
        private ReportSnapshotStore $reportSnapshots,
        private EventRecorder $events,
        private ?BigFiveTelemetry $bigFiveTelemetry = null,
        ?PaymentWebhookHandler $handler = null,
    ) {
        if (! ($this->bigFiveTelemetry instanceof BigFiveTelemetry)) {
            try {
                $resolved = app(BigFiveTelemetry::class);
                $this->bigFiveTelemetry = $resolved instanceof BigFiveTelemetry ? $resolved : null;
            } catch (\Throwable) {
                $this->bigFiveTelemetry = null;
            }
        }

        $this->handler = $handler ?? new PaymentWebhookHandler(
            $this->orders,
            $this->skus,
            $this->wallets,
            $this->entitlements,
            $this->reportSnapshots,
            $this->events,
            null,
            $this->bigFiveTelemetry,
        );
    }

    public function handle(
        string $provider,
        array $payload,
        int $orgId = 0,
        ?string $userId = null,
        ?string $anonId = null,
        bool $signatureOk = true,
        array $payloadMeta = [],
        string $rawPayloadSha256 = '',
        int $rawPayloadBytes = -1
    ): array {
        return $this->handler->handle(
            $provider,
            $payload,
            $orgId,
            $userId,
            $anonId,
            $signatureOk,
            $payloadMeta,
            $rawPayloadSha256,
            $rawPayloadBytes,
        );
    }

    public function evaluateDryRun(string $provider, array $payload, bool $signatureOk = true): array
    {
        return $this->handler->evaluateDryRun($provider, $payload, $signatureOk);
    }

    public function process(string $provider, array $payload, bool $signatureOk = true): array
    {
        $providerKey = strtolower(trim($provider));

        // Billing webhooks are rejected at the transport boundary when signature is invalid.
        // Stripe keeps handler-level forensics rows for invalid signatures.
        if ($signatureOk !== true && $providerKey === 'billing') {
            return [
                'ok' => false,
                'error_code' => 'INVALID_SIGNATURE',
                'message' => 'invalid signature.',
                'details' => null,
                'status' => 400,
            ];
        }

        [$orgId, $userId, $anonId] = $this->resolveOrderContext($provider, $payload);

        return $this->handle(
            $provider,
            $payload,
            $orgId,
            $userId,
            $anonId,
            $signatureOk
        );
    }

    /**
     * @return array{0:int, 1:?string, 2:?string}
     */
    private function resolveOrderContext(string $provider, array $payload): array
    {
        $orderNo = $this->resolveOrderNo($provider, $payload);
        if ($orderNo === '') {
            return [0, null, null];
        }

        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        if (! $order) {
            return [0, null, null];
        }

        $orgId = (int) ($order->org_id ?? 0);
        $userId = isset($order->user_id) && $order->user_id !== null ? trim((string) $order->user_id) : '';
        $anonId = isset($order->anon_id) && $order->anon_id !== null ? trim((string) $order->anon_id) : '';

        return [
            $orgId,
            $userId !== '' ? $userId : null,
            $anonId !== '' ? $anonId : null,
        ];
    }

    private function resolveOrderNo(string $provider, array $payload): string
    {
        $provider = strtolower(trim($provider));

        $normalized = match ($provider) {
            'stripe' => (new StripeGateway)->normalizePayload($payload),
            'billing' => (new BillingGateway)->normalizePayload($payload),
            default => $payload,
        };

        $orderNo = trim((string) ($normalized['order_no'] ?? ''));
        if ($orderNo !== '') {
            return $orderNo;
        }

        return trim((string) ($payload['order_no'] ?? $payload['orderNo'] ?? $payload['order'] ?? ''));
    }
}
