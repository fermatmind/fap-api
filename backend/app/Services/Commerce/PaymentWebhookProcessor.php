<?php

namespace App\Services\Commerce;

use App\Services\Analytics\EventRecorder;
use App\Services\Commerce\PaymentGateway\PaymentGatewayInterface;
use App\Services\Commerce\PaymentGateway\StubGateway;
use App\Services\Report\ReportSnapshotStore;
use App\Support\Commerce\SkuContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PaymentWebhookProcessor
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function __construct(
        private OrderManager $orders,
        private BenefitWalletService $wallets,
        private EntitlementManager $entitlements,
        private ReportSnapshotStore $reportSnapshots,
        private EventRecorder $events,
    ) {
        $stub = new StubGateway();
        $this->gateways[$stub->provider()] = $stub;
    }

    public function handle(string $provider, array $payload, int $orgId = 0, ?string $userId = null, ?string $anonId = null): array
    {
        if (!Schema::hasTable('payment_events')) {
            return $this->tableMissing('payment_events');
        }
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }
        if (!Schema::hasTable('skus')) {
            return $this->tableMissing('skus');
        }

        $provider = strtolower(trim($provider));
        $gateway = $this->gateways[$provider] ?? null;
        if (!$gateway) {
            return $this->badRequest('PROVIDER_NOT_SUPPORTED', 'provider not supported.');
        }

        $normalized = $gateway->normalizePayload($payload);
        $providerEventId = trim((string) ($normalized['provider_event_id'] ?? ''));
        $orderNo = trim((string) ($normalized['order_no'] ?? ''));

        if ($providerEventId === '' || $orderNo === '') {
            return $this->badRequest('PAYLOAD_INVALID', 'provider_event_id and order_no are required.');
        }

        $receivedAt = now();
        $orderMeta = $this->resolveOrderMeta($orgId, $orderNo);
        $normalizedSkuMeta = $this->normalizeOrderSkuMeta($orderMeta);
        $eventMeta = $this->buildEventMeta($orderMeta, [
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
        ]);
        $eventContext = $this->buildEventContext($orderMeta, $anonId);

        $payloadJson = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;
        $eventRow = [
            'id' => (string) Str::uuid(),
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'payload_json' => $payloadJson,
            'received_at' => $receivedAt,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ];

        if (Schema::hasColumn('payment_events', 'order_id')) {
            $orderId = $orderMeta['order']?->id ?? null;
            $eventRow['order_id'] = $orderId ?: (string) Str::uuid();
        }
        if (Schema::hasColumn('payment_events', 'event_type')) {
            $eventRow['event_type'] = 'payment_webhook';
        }
        if (Schema::hasColumn('payment_events', 'signature_ok')) {
            $eventRow['signature_ok'] = true;
        }
        if (Schema::hasColumn('payment_events', 'handled_at')) {
            $eventRow['handled_at'] = null;
        }
        if (Schema::hasColumn('payment_events', 'handle_status')) {
            $eventRow['handle_status'] = null;
        }
        if (Schema::hasColumn('payment_events', 'request_id')) {
            $eventRow['request_id'] = null;
        }
        if (Schema::hasColumn('payment_events', 'ip')) {
            $eventRow['ip'] = null;
        }
        if (Schema::hasColumn('payment_events', 'headers_digest')) {
            $eventRow['headers_digest'] = null;
        }
        if (Schema::hasColumn('payment_events', 'requested_sku')) {
            $eventRow['requested_sku'] = $normalizedSkuMeta['requested_sku'] ?? null;
        }
        if (Schema::hasColumn('payment_events', 'effective_sku')) {
            $eventRow['effective_sku'] = $normalizedSkuMeta['effective_sku'] ?? null;
        }
        if (Schema::hasColumn('payment_events', 'entitlement_id')) {
            $eventRow['entitlement_id'] = $normalizedSkuMeta['entitlement_id'] ?? null;
        }

        $inserted = DB::table('payment_events')->insertOrIgnore($eventRow);

        $eventUserId = $orderMeta['user_id'] ?? $userId;
        $this->events->record('payment_webhook_received', $this->numericUserId($eventUserId), $eventMeta, $eventContext);

        if (!$inserted) {
            return [
                'ok' => true,
                'duplicate' => true,
            ];
        }

        return DB::transaction(function () use ($orderNo, $normalized, $providerEventId, $provider, $orgId, $userId, $anonId) {
            $orderResult = $this->orders->getOrder($orgId, $orderNo);
            if (!($orderResult['ok'] ?? false)) {
                return $orderResult;
            }

            $order = $orderResult['order'];
            $sku = strtoupper((string) ($order->effective_sku ?? $order->sku ?? $order->item_sku ?? ''));
            if ($sku === '') {
                return $this->badRequest('SKU_NOT_FOUND', 'sku missing on order.');
            }

            $skuRow = DB::table('skus')->where('sku', $sku)->first();
            if (!$skuRow) {
                return $this->notFound('SKU_NOT_FOUND', 'sku not found.');
            }

            $orderTransition = $this->orders->transition($orderNo, 'paid', $orgId);
            if (!($orderTransition['ok'] ?? false)) {
                return $orderTransition;
            }

            $updateRow = [
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('orders', 'external_trade_no')) {
                $externalTradeNo = $normalized['external_trade_no'] ?? null;
                if ($externalTradeNo) {
                    $updateRow['external_trade_no'] = $externalTradeNo;
                }
            }
            if (Schema::hasColumn('orders', 'requested_sku')) {
                $updateRow['requested_sku'] = $normalizedSkuMeta['requested_sku'] ?? ($order->requested_sku ?? null);
            }
            if (Schema::hasColumn('orders', 'effective_sku')) {
                $updateRow['effective_sku'] = $normalizedSkuMeta['effective_sku'] ?? ($order->effective_sku ?? null);
            }
            if (Schema::hasColumn('orders', 'entitlement_id')) {
                $updateRow['entitlement_id'] = $normalizedSkuMeta['entitlement_id'] ?? ($order->entitlement_id ?? null);
            }

            if (count($updateRow) > 1) {
                DB::table('orders')
                    ->where('order_no', $orderNo)
                    ->update($updateRow);
            }

            $quantity = (int) ($order->quantity ?? 1);
            $benefitCode = strtoupper((string) ($skuRow->benefit_code ?? ''));
            $kind = (string) ($skuRow->kind ?? '');

            $attemptMeta = $this->resolveAttemptMeta((int) $order->org_id, (string) ($order->target_attempt_id ?? ''));
            $eventBaseMeta = $this->buildEventMeta([
                'org_id' => (int) $order->org_id,
                'sku' => $sku,
                'benefit_code' => $benefitCode,
                'attempt' => $attemptMeta,
            ], [
                'order_no' => $orderNo,
                'provider_event_id' => $providerEventId,
            ]);
            $eventContext = $this->buildEventContext([
                'org_id' => (int) $order->org_id,
                'attempt' => $attemptMeta,
            ], $anonId);
            $eventUserId = $order->user_id ? (string) $order->user_id : $userId;

            if ($kind === 'credit_pack') {
                $topupKey = "TOPUP:{$provider}:{$providerEventId}";
                $wallet = $this->wallets->topUp(
                    (int) $order->org_id,
                    $benefitCode,
                    (int) ($skuRow->unit_qty ?? 0) * $quantity,
                    $topupKey,
                    [
                        'order_no' => $orderNo,
                        'provider_event_id' => $providerEventId,
                        'provider' => $provider,
                    ]
                );

                if (!($wallet['ok'] ?? false)) {
                    return $wallet;
                }

                $this->events->record('wallet_topped_up', $this->numericUserId($eventUserId), $eventBaseMeta, $eventContext);
            } elseif ($kind === 'report_unlock') {
                $attemptId = (string) ($order->target_attempt_id ?? '');
                if ($attemptId === '') {
                    return $this->badRequest('ATTEMPT_REQUIRED', 'target_attempt_id is required for report_unlock.');
                }

                $scopeOverride = trim((string) ($skuRow->scope ?? ''));
                if ($scopeOverride === '') {
                    $scopeOverride = 'attempt';
                }

                $expiresAt = null;
                $skuMeta = $skuRow->meta_json ?? null;
                if (is_string($skuMeta)) {
                    $decoded = json_decode($skuMeta, true);
                    $skuMeta = is_array($decoded) ? $decoded : null;
                }
                if (is_array($skuMeta)) {
                    $durationDays = isset($skuMeta['duration_days']) ? (int) $skuMeta['duration_days'] : 0;
                    if ($durationDays > 0) {
                        $expiresAt = now()->addDays($durationDays)->toISOString();
                    }
                }

                $grant = $this->entitlements->grantAttemptUnlock(
                    (int) $order->org_id,
                    $order->user_id ? (string) $order->user_id : $userId,
                    $order->anon_id ? (string) $order->anon_id : $anonId,
                    $benefitCode,
                    $attemptId,
                    $orderNo,
                    $scopeOverride,
                    $expiresAt
                );

                if (!($grant['ok'] ?? false)) {
                    return $grant;
                }

                $this->events->record('entitlement_granted', $this->numericUserId($eventUserId), $eventBaseMeta, $eventContext);

                $snapshot = $this->reportSnapshots->createSnapshotForAttempt([
                    'org_id' => (int) $order->org_id,
                    'attempt_id' => $attemptId,
                    'trigger_source' => 'payment',
                    'order_no' => $orderNo,
                ]);
                if (!($snapshot['ok'] ?? false)) {
                    return $snapshot;
                }
            }

            $this->orders->transition($orderNo, 'fulfilled', $orgId);

            $this->events->record('purchase_success', $this->numericUserId($eventUserId), $eventBaseMeta, $eventContext);

            return [
                'ok' => true,
                'order_no' => $orderNo,
                'provider_event_id' => $providerEventId,
            ];
        });
    }

    private function numericUserId(?string $userId): ?int
    {
        $userId = $userId !== null ? trim($userId) : '';
        if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
            return null;
        }

        return (int) $userId;
    }

    private function buildEventMeta(array $orderMeta, array $extra): array
    {
        $attempt = $orderMeta['attempt'] ?? [];
        return array_merge([
            'scale_code' => $attempt['scale_code'] ?? null,
            'attempt_id' => $attempt['attempt_id'] ?? null,
            'pack_id' => $attempt['pack_id'] ?? null,
            'dir_version' => $attempt['dir_version'] ?? null,
            'sku' => $orderMeta['sku'] ?? null,
            'benefit_code' => $orderMeta['benefit_code'] ?? null,
        ], $extra);
    }

    private function buildEventContext(array $orderMeta, ?string $anonId): array
    {
        $attempt = $orderMeta['attempt'] ?? [];
        return [
            'org_id' => $orderMeta['org_id'] ?? 0,
            'anon_id' => $anonId,
            'attempt_id' => $attempt['attempt_id'] ?? null,
            'pack_id' => $attempt['pack_id'] ?? null,
            'dir_version' => $attempt['dir_version'] ?? null,
        ];
    }

    private function resolveOrderMeta(int $orgId, string $orderNo): array
    {
        $order = null;
        $sku = '';
        $benefitCode = '';
        $orderUserId = null;
        $orderOrgId = $orgId;
        $attempt = [];

        if (Schema::hasTable('orders')) {
            $query = DB::table('orders')->where('order_no', $orderNo);
            if (Schema::hasColumn('orders', 'org_id')) {
                $query->where('org_id', $orgId);
            }
            $order = $query->first();
        }

        if ($order) {
            $orderOrgId = (int) ($order->org_id ?? $orgId);
            $orderUserId = $order->user_id ? (string) $order->user_id : null;
            $sku = strtoupper((string) ($order->sku ?? $order->item_sku ?? ''));
            if ($sku !== '' && Schema::hasTable('skus')) {
                $skuRow = DB::table('skus')->where('sku', $sku)->first();
                if ($skuRow) {
                    $benefitCode = strtoupper((string) ($skuRow->benefit_code ?? ''));
                }
            }
            $attempt = $this->resolveAttemptMeta($orderOrgId, (string) ($order->target_attempt_id ?? ''));
        }

        return [
            'order' => $order,
            'org_id' => $orderOrgId,
            'user_id' => $orderUserId,
            'sku' => $sku,
            'benefit_code' => $benefitCode,
            'attempt' => $attempt,
        ];
    }

    private function normalizeOrderSkuMeta(array $orderMeta): array
    {
        $order = $orderMeta['order'] ?? null;
        $requestedSku = '';
        $effectiveSku = '';

        if ($order) {
            $requestedSku = strtoupper((string) ($order->requested_sku ?? ''));
            if ($requestedSku === '') {
                $requestedSku = strtoupper((string) ($order->sku ?? $order->item_sku ?? ''));
            }

            $effectiveSku = strtoupper((string) ($order->effective_sku ?? ''));
            if ($effectiveSku === '') {
                $effectiveSku = strtoupper((string) ($order->sku ?? $order->item_sku ?? ''));
            }
        }

        $normalized = SkuContract::normalizeRequestedSku($requestedSku !== '' ? $requestedSku : $effectiveSku);
        if ($effectiveSku === '' && ($normalized['effective_sku'] ?? null)) {
            $effectiveSku = strtoupper((string) $normalized['effective_sku']);
        }

        return [
            'requested_sku' => $normalized['requested_sku'] ?? ($requestedSku !== '' ? $requestedSku : null),
            'effective_sku' => $normalized['effective_sku'] ?? ($effectiveSku !== '' ? $effectiveSku : null),
            'entitlement_id' => $normalized['entitlement_id'] ?? ($order?->entitlement_id ?? null),
        ];
    }

    private function resolveAttemptMeta(int $orgId, ?string $attemptId): array
    {
        $attemptId = $attemptId !== null ? trim($attemptId) : '';
        if ($attemptId === '' || !Schema::hasTable('attempts')) {
            return [
                'attempt_id' => null,
                'scale_code' => null,
                'pack_id' => null,
                'dir_version' => null,
            ];
        }

        $query = DB::table('attempts')->where('id', $attemptId);
        if (Schema::hasColumn('attempts', 'org_id')) {
            $query->where('org_id', $orgId);
        }

        $row = $query->first();
        if (!$row) {
            return [
                'attempt_id' => null,
                'scale_code' => null,
                'pack_id' => null,
                'dir_version' => null,
            ];
        }

        return [
            'attempt_id' => (string) ($row->id ?? $attemptId),
            'scale_code' => (string) ($row->scale_code ?? ''),
            'pack_id' => (string) ($row->pack_id ?? ''),
            'dir_version' => (string) ($row->dir_version ?? ''),
        ];
    }

    private function tableMissing(string $table): array
    {
        return [
            'ok' => false,
            'error' => 'TABLE_MISSING',
            'message' => "{$table} table missing.",
        ];
    }

    private function badRequest(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function notFound(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }
}
