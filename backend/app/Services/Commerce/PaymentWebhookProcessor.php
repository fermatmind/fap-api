<?php

namespace App\Services\Commerce;

use App\Services\Analytics\EventRecorder;
use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\PaymentGateway\PaymentGatewayInterface;
use App\Services\Commerce\PaymentGateway\StripeGateway;
use App\Services\Commerce\PaymentGateway\StubGateway;
use App\Services\Commerce\SkuCatalog;
use App\Services\Report\ReportSnapshotStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PaymentWebhookProcessor
{
    private const DEFAULT_WEBHOOK_LOCK_TTL_SECONDS = 10;
    private const DEFAULT_WEBHOOK_LOCK_BLOCK_SECONDS = 5;

    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    public function __construct(
        private OrderManager $orders,
        private SkuCatalog $skus,
        private BenefitWalletService $wallets,
        private EntitlementManager $entitlements,
        private ReportSnapshotStore $reportSnapshots,
        private EventRecorder $events,
    ) {
        $stub = new StubGateway();
        $this->gateways[$stub->provider()] = $stub;
        $stripe = new StripeGateway();
        $this->gateways[$stripe->provider()] = $stripe;
        $billing = new BillingGateway();
        $this->gateways[$billing->provider()] = $billing;
    }

    public function handle(
        string $provider,
        array $payload,
        int $orgId = 0,
        ?string $userId = null,
        ?string $anonId = null,
        bool $signatureOk = true
    ): array {
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
        $eventType = $this->normalizeEventType($normalized);

        if ($providerEventId === '' || $orderNo === '') {
            return $this->badRequest('PAYLOAD_INVALID', 'provider_event_id and order_no are required.');
        }

        $receivedAt = now();
        $payloadJson = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : $payload;
        $lockKey = "webhook_pay:{$provider}:{$providerEventId}";
        $lockTtl = max(1, (int) config(
            'services.payment_webhook.lock_ttl_seconds',
            self::DEFAULT_WEBHOOK_LOCK_TTL_SECONDS
        ));
        $lockBlock = max(0, (int) config(
            'services.payment_webhook.lock_block_seconds',
            self::DEFAULT_WEBHOOK_LOCK_BLOCK_SECONDS
        ));

        try {
            return Cache::lock($lockKey, $lockTtl)->block($lockBlock, function () use (
                $orderNo,
                $normalized,
                $providerEventId,
                $provider,
                $orgId,
                $userId,
                $anonId,
                $eventType,
                $receivedAt,
                $payloadJson,
                $signatureOk
            ) {
                return DB::transaction(function () use (
                    $orderNo,
                    $normalized,
                    $providerEventId,
                    $provider,
                    $orgId,
                    $userId,
                    $anonId,
                    $eventType,
                    $receivedAt,
                    $payloadJson,
                    $signatureOk
                ) {
                    $insertSeed = [
                        'id' => (string) Str::uuid(),
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_no' => $orderNo,
                        'payload_json' => $payloadJson,
                        'received_at' => $receivedAt,
                        'created_at' => $receivedAt,
                        'updated_at' => $receivedAt,
                    ];
                    if (Schema::hasColumn('payment_events', 'event_type')) {
                        $insertSeed['event_type'] = $eventType;
                    }
                    if (Schema::hasColumn('payment_events', 'signature_ok')) {
                        $insertSeed['signature_ok'] = $signatureOk;
                    }
                    if (Schema::hasColumn('payment_events', 'status')) {
                        $insertSeed['status'] = 'received';
                    }
                    if (Schema::hasColumn('payment_events', 'attempts')) {
                        $insertSeed['attempts'] = 0;
                    }
                    if (Schema::hasColumn('payment_events', 'last_error_code')) {
                        $insertSeed['last_error_code'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'last_error_message')) {
                        $insertSeed['last_error_message'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'processed_at')) {
                        $insertSeed['processed_at'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'handled_at')) {
                        $insertSeed['handled_at'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'handle_status')) {
                        $insertSeed['handle_status'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'order_id')) {
                        $insertSeed['order_id'] = (string) Str::uuid();
                    }

                    $inserted = (int) DB::table('payment_events')->insertOrIgnore($insertSeed);

                    $eventRow = DB::table('payment_events')
                        ->where('provider', $provider)
                        ->where('provider_event_id', $providerEventId)
                        ->lockForUpdate()
                        ->first();
                    if (!$eventRow) {
                        return $this->serverError('EVENT_INIT_FAILED', 'payment event init failed.');
                    }

                    if ($inserted === 0 && $this->isEventProcessed($eventRow)) {
                        Log::info('payment_webhook_duplicate_event', [
                            'provider' => $provider,
                            'provider_event_id' => $providerEventId,
                            'order_id' => $eventRow->order_id ?? null,
                        ]);

                        return [
                            'ok' => true,
                            'duplicate' => true,
                            'order_no' => $orderNo,
                            'provider_event_id' => $providerEventId,
                        ];
                    }

                    $attempts = (int) ($eventRow->attempts ?? 0);
                    $attempts = $attempts > 0 ? $attempts + 1 : 1;

                    $baseRow = [
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_no' => $orderNo,
                        'payload_json' => $payloadJson,
                        'received_at' => $receivedAt,
                        'updated_at' => $receivedAt,
                    ];
                    if (Schema::hasColumn('payment_events', 'event_type')) {
                        $baseRow['event_type'] = $eventType;
                    }
                    if (Schema::hasColumn('payment_events', 'signature_ok')) {
                        $baseRow['signature_ok'] = $signatureOk;
                    }
                    if (Schema::hasColumn('payment_events', 'status')) {
                        $baseRow['status'] = 'received';
                    }
                    if (Schema::hasColumn('payment_events', 'attempts')) {
                        $baseRow['attempts'] = $attempts;
                    }
                    if (Schema::hasColumn('payment_events', 'last_error_code')) {
                        $baseRow['last_error_code'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'last_error_message')) {
                        $baseRow['last_error_message'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'processed_at')) {
                        $baseRow['processed_at'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'handled_at')) {
                        $baseRow['handled_at'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'handle_status')) {
                        $baseRow['handle_status'] = null;
                    }
                    if (Schema::hasColumn('payment_events', 'order_id')) {
                        $baseRow['order_id'] = $eventRow->order_id ?? ($insertSeed['order_id'] ?? (string) Str::uuid());
                    }

                    DB::table('payment_events')
                        ->where('provider', $provider)
                        ->where('provider_event_id', $providerEventId)
                        ->update($baseRow);

                    $orderQuery = DB::table('orders')->where('order_no', $orderNo);
                    if (Schema::hasColumn('orders', 'org_id')) {
                        $orderQuery->where('org_id', $orgId);
                    } elseif ($orgId !== 0) {
                        $this->markEventError($provider, $providerEventId, 'orphan', 'ORDER_NOT_FOUND', 'order not found.');
                        return $this->serverError('ORDER_NOT_FOUND', 'order not found.');
                    }

                    $order = $orderQuery->lockForUpdate()->first();
                    if (!$order) {
                        $this->markEventError($provider, $providerEventId, 'orphan', 'ORDER_NOT_FOUND', 'order not found.');
                        return $this->serverError('ORDER_NOT_FOUND', 'order not found.');
                    }

                    $isRefundEvent = $this->isRefundEvent($eventType, $normalized);
                    $orderStatus = strtolower((string) ($order->status ?? ''));
                    if (!$isRefundEvent && in_array($orderStatus, ['paid', 'fulfilled', 'completed', 'delivered', 'refunded'], true)) {
                        Log::info('payment_webhook_skip_already_processed', [
                            'provider' => $provider,
                            'provider_event_id' => $providerEventId,
                            'order_id' => $order->id ?? null,
                        ]);

                        $this->markEventProcessed($provider, $providerEventId);

                        return [
                            'ok' => true,
                            'duplicate' => true,
                            'order_no' => $orderNo,
                            'provider_event_id' => $providerEventId,
                        ];
                    }

                    $orderMeta = $this->resolveOrderMeta($orgId, $orderNo, $order);
                    $normalizedSkuMeta = $this->normalizeOrderSkuMeta($order);
                    $this->updatePaymentEvent($provider, $providerEventId, [
                        'order_id' => $order->id ?? null,
                        'event_type' => $eventType,
                        'signature_ok' => $signatureOk,
                        'requested_sku' => $normalizedSkuMeta['requested_sku'] ?? null,
                        'effective_sku' => $normalizedSkuMeta['effective_sku'] ?? null,
                        'entitlement_id' => $normalizedSkuMeta['entitlement_id'] ?? null,
                    ]);

                    $eventUserId = $orderMeta['user_id'] ?? $userId;
                    $eventMeta = $this->buildEventMeta($orderMeta, [
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_no' => $orderNo,
                    ]);
                    $eventContext = $this->buildEventContext($orderMeta, $anonId);
                    $this->events->record('payment_webhook_received', $this->numericUserId($eventUserId), $eventMeta, $eventContext);

                    if ($isRefundEvent) {
                        $refund = $this->handleRefund($orderNo, $order, $normalized, $providerEventId, $orgId);
                        if (!($refund['ok'] ?? false)) {
                            $this->markEventError(
                                $provider,
                                $providerEventId,
                                'failed',
                                (string) ($refund['error'] ?? 'REFUND_FAILED'),
                                (string) ($refund['message'] ?? 'refund failed.')
                            );
                            return $refund;
                        }
                        $this->markEventProcessed($provider, $providerEventId);
                        return $refund;
                    }

                    $effectiveSku = strtoupper((string) ($normalizedSkuMeta['effective_sku']
                        ?? $order->effective_sku
                        ?? $order->sku
                        ?? $order->item_sku
                        ?? ''));
                    if ($effectiveSku === '') {
                        $this->markEventError($provider, $providerEventId, 'failed', 'SKU_NOT_FOUND', 'sku missing on order.');
                        return $this->badRequest('SKU_NOT_FOUND', 'sku missing on order.');
                    }

                    $skuRow = $this->skus->getActiveSku($effectiveSku);
                    if (!$skuRow) {
                        $this->markEventError($provider, $providerEventId, 'failed', 'SKU_NOT_FOUND', 'sku not found.');
                        return $this->notFound('SKU_NOT_FOUND', 'sku not found.');
                    }

                    $orderTransition = $this->orders->transitionToPaidAtomic(
                        $orderNo,
                        $orgId,
                        $provider,
                        $normalized['external_trade_no'] ?? null,
                        $normalized['paid_at'] ?? null
                    );
                    if (!($orderTransition['ok'] ?? false)) {
                        $this->markEventError(
                            $provider,
                            $providerEventId,
                            'failed',
                            (string) ($orderTransition['error'] ?? 'ORDER_STATUS_INVALID'),
                            (string) ($orderTransition['message'] ?? 'order transition failed.')
                        );
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
                        'sku' => $effectiveSku,
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
                            $this->markEventError(
                                $provider,
                                $providerEventId,
                                'failed',
                                (string) ($wallet['error'] ?? 'WALLET_TOPUP_FAILED'),
                                (string) ($wallet['message'] ?? 'wallet topup failed.')
                            );
                            return $wallet;
                        }

                        $this->events->record('wallet_topped_up', $this->numericUserId($eventUserId), $eventBaseMeta, $eventContext);
                    } elseif ($kind === 'report_unlock') {
                        $attemptId = (string) ($order->target_attempt_id ?? '');
                        if ($attemptId === '') {
                            $this->markEventError($provider, $providerEventId, 'failed', 'ATTEMPT_REQUIRED', 'target_attempt_id is required.');
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
                            $this->markEventError(
                                $provider,
                                $providerEventId,
                                'failed',
                                (string) ($grant['error'] ?? 'ENTITLEMENT_FAILED'),
                                (string) ($grant['message'] ?? 'entitlement grant failed.')
                            );
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
                            $this->markEventError(
                                $provider,
                                $providerEventId,
                                'failed',
                                (string) ($snapshot['error'] ?? 'SNAPSHOT_FAILED'),
                                (string) ($snapshot['message'] ?? 'report snapshot failed.')
                            );
                            return $snapshot;
                        }
                    } else {
                        $this->markEventError($provider, $providerEventId, 'failed', 'SKU_KIND_INVALID', 'unsupported sku kind.');
                        return $this->badRequest('SKU_KIND_INVALID', 'unsupported sku kind.');
                    }

                    $fulfilled = $this->orders->transition($orderNo, 'fulfilled', $orgId);
                    if (!($fulfilled['ok'] ?? false)) {
                        $this->markEventError(
                            $provider,
                            $providerEventId,
                            'failed',
                            (string) ($fulfilled['error'] ?? 'ORDER_STATUS_INVALID'),
                            (string) ($fulfilled['message'] ?? 'order transition failed.')
                        );
                        return $fulfilled;
                    }

                    $this->events->record('purchase_success', $this->numericUserId($eventUserId), $eventBaseMeta, $eventContext);

                    $this->markEventProcessed($provider, $providerEventId);

                    return [
                        'ok' => true,
                        'order_no' => $orderNo,
                        'provider_event_id' => $providerEventId,
                    ];
                });
            });
        } catch (LockTimeoutException $e) {
            return $this->serverError('WEBHOOK_BUSY', 'payment webhook is busy, retry later.');
        }
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

    private function resolveOrderMeta(int $orgId, string $orderNo, ?object $order = null): array
    {
        $sku = '';
        $benefitCode = '';
        $orderUserId = null;
        $orderOrgId = $orgId;
        $attempt = [];

        if (!$order && Schema::hasTable('orders')) {
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

    private function normalizeOrderSkuMeta(?object $order): array
    {
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

        $skuToResolve = $requestedSku !== '' ? $requestedSku : $effectiveSku;
        $resolved = $skuToResolve !== '' ? $this->skus->resolveSkuMeta($skuToResolve) : [];

        $resolvedRequested = $resolved['requested_sku'] ?? null;
        $resolvedEffective = $resolved['effective_sku'] ?? null;

        return [
            'requested_sku' => $resolvedRequested ?? ($requestedSku !== '' ? $requestedSku : null),
            'effective_sku' => $resolvedEffective ?? ($effectiveSku !== '' ? $effectiveSku : null),
            'entitlement_id' => $resolved['entitlement_id'] ?? ($order?->entitlement_id ?? null),
        ];
    }

    private function isEventProcessed(object $eventRow): bool
    {
        $status = strtolower((string) ($eventRow->status ?? ''));
        if ($status === 'processed') {
            return true;
        }

        return false;
    }

    private function updatePaymentEvent(string $provider, string $providerEventId, array $updates): void
    {
        if ($provider === '' || $providerEventId === '' || !Schema::hasTable('payment_events')) {
            return;
        }

        $filtered = [];
        foreach ($updates as $column => $value) {
            if (Schema::hasColumn('payment_events', $column)) {
                $filtered[$column] = $value;
            }
        }

        if (count($filtered) === 0) {
            return;
        }

        DB::table('payment_events')
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->update($filtered);
    }

    private function markEventProcessed(string $provider, string $providerEventId): void
    {
        $now = now();
        $this->updatePaymentEvent($provider, $providerEventId, [
            'status' => 'processed',
            'processed_at' => $now,
            'handled_at' => $now,
            'handle_status' => 'processed',
            'last_error_code' => null,
            'last_error_message' => null,
            'updated_at' => $now,
        ]);
    }

    private function markEventError(string $provider, string $providerEventId, string $status, string $code, string $message): void
    {
        $now = now();
        $this->updatePaymentEvent($provider, $providerEventId, [
            'status' => $status,
            'handled_at' => $now,
            'handle_status' => $status,
            'last_error_code' => $code,
            'last_error_message' => $message,
            'updated_at' => $now,
        ]);
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

    private function serverError(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'error_code' => $code,
            'message' => $message,
            'status' => 500,
        ];
    }

    private function notFound(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
            'status' => 404,
        ];
    }

    private function normalizeEventType(array $normalized): string
    {
        $eventType = strtolower(trim((string) ($normalized['event_type'] ?? '')));
        return $eventType !== '' ? $eventType : 'payment_succeeded';
    }

    private function isRefundEvent(string $eventType, array $normalized): bool
    {
        if ($eventType !== '' && str_contains($eventType, 'refund')) {
            return true;
        }

        $refundAmount = (int) ($normalized['refund_amount_cents'] ?? 0);
        return $refundAmount > 0;
    }

    private function handleRefund(
        string $orderNo,
        object $order,
        array $normalized,
        string $providerEventId,
        int $orgId
    ): array {
        $now = now();
        $refundAmount = (int) ($normalized['refund_amount_cents'] ?? 0);
        $refundReason = trim((string) ($normalized['refund_reason'] ?? ''));

        $updates = [
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('orders', 'refunded_at') && empty($order->refunded_at)) {
            $updates['refunded_at'] = $now;
        }
        if (Schema::hasColumn('orders', 'refund_amount_cents')) {
            $updates['refund_amount_cents'] = $refundAmount > 0 ? $refundAmount : ($order->refund_amount_cents ?? null);
        }
        if (Schema::hasColumn('orders', 'refund_reason') && $refundReason !== '') {
            $updates['refund_reason'] = $refundReason;
        }

        if (count($updates) > 1) {
            DB::table('orders')
                ->where('order_no', $orderNo)
                ->update($updates);
        }

        $transition = $this->orders->transition($orderNo, 'refunded', $orgId);
        if (!($transition['ok'] ?? false)) {
            return $transition;
        }

        $revoked = $this->entitlements->revokeByOrderNo($orgId, $orderNo);
        if (!($revoked['ok'] ?? false)) {
            return $revoked;
        }

        return [
            'ok' => true,
            'order_no' => $orderNo,
            'provider_event_id' => $providerEventId,
            'refunded' => true,
            'revoked' => $revoked['revoked'] ?? 0,
        ];
    }
}
