<?php

namespace App\Internal\Commerce;

use App\Jobs\GenerateReportSnapshotJob;
use App\Services\Analytics\EventRecorder;
use App\Services\Commerce\BenefitWalletService;
use App\Services\Commerce\EntitlementManager;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\PaymentGateway\PaymentGatewayInterface;
use App\Services\Commerce\PaymentGateway\StripeGateway;
use App\Services\Commerce\SkuCatalog;
use App\Services\Observability\BigFiveTelemetry;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportSnapshotStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentWebhookHandlerCore
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
        private ?BigFiveTelemetry $bigFiveTelemetry = null,
    ) {
        $stripe = new StripeGateway();
        $this->gateways[$stripe->provider()] = $stripe;
        $billing = new BillingGateway();
        $this->gateways[$billing->provider()] = $billing;

        if ($this->isStubEnabled()) {
            $stubGatewayClass = \App\Services\Commerce\PaymentGateway\StubGateway::class;
            if (class_exists($stubGatewayClass)) {
                $stub = new $stubGatewayClass();
                if ($stub instanceof PaymentGatewayInterface) {
                    $this->gateways[$stub->provider()] = $stub;
                }
            }
        }
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
        $provider = strtolower(trim($provider));
        if ($provider === 'stub' && !$this->isStubEnabled()) {
            return $this->notFound('PROVIDER_DISABLED', 'not found.');
        }

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
        $resolvedPayloadMeta = $this->resolvePayloadMeta($payload, $payloadMeta, $rawPayloadSha256, $rawPayloadBytes);
        $payloadSummary = $this->buildPayloadSummary(
            $normalized,
            $eventType,
            $resolvedPayloadMeta['sha256'],
            $resolvedPayloadMeta['size_bytes']
        );
        $payloadSummaryJson = $this->encodePayloadSummary($payloadSummary);
        $payloadExcerpt = $this->buildPayloadExcerpt($payloadSummaryJson);
        $lockKey = "webhook_pay:{$provider}:{$providerEventId}";
        $lockTtl = max(1, (int) config(
            'services.payment_webhook.lock_ttl_seconds',
            self::DEFAULT_WEBHOOK_LOCK_TTL_SECONDS
        ));
        $lockBlock = max(0, (int) config(
            'services.payment_webhook.lock_block_seconds',
            self::DEFAULT_WEBHOOK_LOCK_BLOCK_SECONDS
        ));
        $postCommitOutcome = null;
        $postCommitCtx = null;

        try {
            $result = Cache::lock($lockKey, $lockTtl)->block($lockBlock, function () use (
                $orderNo,
                $normalized,
                $providerEventId,
                $provider,
                $orgId,
                $userId,
                $anonId,
                $eventType,
                $receivedAt,
                $payloadSummaryJson,
                $payloadExcerpt,
                $resolvedPayloadMeta,
                $signatureOk,
                &$postCommitCtx
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
                    $payloadSummaryJson,
                    $payloadExcerpt,
                    $resolvedPayloadMeta,
                    $signatureOk,
                    &$postCommitCtx
                ) {
                    $insertSeed = [
                        'id' => (string) Str::uuid(),
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_id' => (string) Str::uuid(),
                        'event_type' => $eventType,
                        'order_no' => $orderNo,
                        'payload_json' => $payloadSummaryJson,
                        'signature_ok' => $signatureOk,
                        'status' => 'received',
                        'attempts' => 0,
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'processed_at' => null,
                        'handled_at' => null,
                        'handle_status' => null,
                        'payload_size_bytes' => $resolvedPayloadMeta['size_bytes'],
                        'payload_sha256' => $resolvedPayloadMeta['sha256'],
                        'payload_s3_key' => $resolvedPayloadMeta['s3_key'],
                        'payload_excerpt' => $payloadExcerpt,
                        'received_at' => $receivedAt,
                        'created_at' => $receivedAt,
                        'updated_at' => $receivedAt,
                    ];

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
                        Log::info('PAYMENT_EVENT_ALREADY_PROCESSED', [
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
                        'order_id' => $eventRow->order_id ?? ($insertSeed['order_id'] ?? (string) Str::uuid()),
                        'event_type' => $eventType,
                        'order_no' => $orderNo,
                        'payload_json' => $payloadSummaryJson,
                        'signature_ok' => $signatureOk,
                        'status' => 'received',
                        'attempts' => $attempts,
                        'last_error_code' => null,
                        'last_error_message' => null,
                        'processed_at' => null,
                        'handled_at' => null,
                        'handle_status' => null,
                        'payload_size_bytes' => $resolvedPayloadMeta['size_bytes'],
                        'payload_sha256' => $resolvedPayloadMeta['sha256'],
                        'payload_s3_key' => $resolvedPayloadMeta['s3_key'],
                        'payload_excerpt' => $payloadExcerpt,
                        'received_at' => $receivedAt,
                        'updated_at' => $receivedAt,
                    ];

                    DB::table('payment_events')
                        ->where('provider', $provider)
                        ->where('provider_event_id', $providerEventId)
                        ->update($baseRow);

                    if ($signatureOk !== true) {
                        $this->markEventError($provider, $providerEventId, 'rejected', 'INVALID_SIGNATURE', 'signature invalid.');
                        return $this->badRequest('INVALID_SIGNATURE', 'invalid signature.');
                    }

                    $orderQuery = DB::table('orders')
                        ->where('order_no', $orderNo)
                        ->where('org_id', $orgId);

                    $order = $orderQuery->lockForUpdate()->first();
                    if (!$order) {
                        $this->markEventError($provider, $providerEventId, 'orphan', 'ORDER_NOT_FOUND', 'order not found.');
                        return $this->notFound('ORDER_NOT_FOUND', 'not found.');
                    }

                    $orderProvider = strtolower(trim((string) ($order->provider ?? '')));
                    $webhookProvider = strtolower(trim((string) $provider));
                    if ($orderProvider !== $webhookProvider) {
                        $detail = "order.provider={$orderProvider}; webhook.provider={$webhookProvider}";

                        Log::warning('PAYMENT_EVENT_PROVIDER_MISMATCH', [
                            'provider' => $provider,
                            'order_provider' => $orderProvider !== '' ? $orderProvider : null,
                            'provider_event_id' => $providerEventId,
                            'order_no' => $orderNo,
                            'order_id' => $order->id ?? null,
                        ]);

                        $this->markEventError(
                            $provider,
                            $providerEventId,
                            'rejected',
                            'rejected_provider_mismatch',
                            $detail
                        );

                        return $this->badRequest('PROVIDER_MISMATCH', 'provider mismatch');
                    }

                    $isRefundEvent = $this->isRefundEvent($eventType, $normalized);
                    $orderStatus = strtolower((string) ($order->status ?? ''));
                    $orderAlreadySettled = !$isRefundEvent
                        && in_array($orderStatus, ['paid', 'fulfilled', 'completed', 'delivered', 'refunded'], true);

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

                    $guard = $this->validatePaidEventGuard($provider, $eventType, $normalized, $order);
                    if (!($guard['ok'] ?? false)) {
                        $this->markEventError(
                            $provider,
                            $providerEventId,
                            'rejected',
                            (string) ($guard['code'] ?? 'WEBHOOK_REJECTED'),
                            (string) ($guard['message'] ?? 'webhook rejected.')
                        );
                        return $this->notFound('NOT_FOUND', 'not found.');
                    }

                    if (!$orderAlreadySettled) {
                        $orderTransition = $this->orders->transitionToPaidAtomic(
                            $orderNo,
                            $orgId,
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
                    }

                    $updateRow = [
                        'updated_at' => now(),
                        'requested_sku' => $normalizedSkuMeta['requested_sku'] ?? ($order->requested_sku ?? null),
                        'effective_sku' => $normalizedSkuMeta['effective_sku'] ?? ($order->effective_sku ?? null),
                        'entitlement_id' => $normalizedSkuMeta['entitlement_id'] ?? ($order->entitlement_id ?? null),
                    ];
                    $externalTradeNo = $normalized['external_trade_no'] ?? null;
                    if ($externalTradeNo) {
                        $updateRow['external_trade_no'] = $externalTradeNo;
                    }

                    if (count($updateRow) > 1) {
                        $skuMetaForOrder = $this->decodeMeta($skuRow->meta_json ?? null);
                        $modulesIncludedForOrder = $this->normalizeModulesIncluded($skuMetaForOrder['modules_included'] ?? null);
                        if ($modulesIncludedForOrder !== []) {
                            $orderMeta = $this->decodeMeta($order->meta_json ?? null);
                            $orderMeta['modules_included'] = $modulesIncludedForOrder;
                            $updateRow['meta_json'] = json_encode($orderMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }

                        DB::table('orders')
                            ->where('order_no', $orderNo)
                            ->update($updateRow);
                    }

                    $quantity = (int) ($order->quantity ?? 1);
                    $benefitCode = strtoupper((string) ($skuRow->benefit_code ?? ''));
                    $kind = (string) ($skuRow->kind ?? '');
                    if ($benefitCode === '') {
                        $this->markEventError(
                            $provider,
                            $providerEventId,
                            'failed',
                            'BENEFIT_CODE_NOT_FOUND',
                            'benefit code missing on sku.'
                        );
                        return $this->badRequest('BENEFIT_CODE_NOT_FOUND', 'benefit code missing on sku.');
                    }

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

                    $retryingPostCommitOnly = $inserted === 0
                        && !$this->isEventProcessed($eventRow)
                        && $orderAlreadySettled;

                    if ($kind === 'credit_pack') {
                        $unitQty = (int) ($skuRow->unit_qty ?? 0);
                        if (
                            $unitQty <= 0
                            || $quantity <= 0
                            || $quantity > intdiv(2147483647, $unitQty)
                        ) {
                            $this->markEventError($provider, $providerEventId, 'failed', 'TOPUP_DELTA_INVALID', 'topup delta invalid.');
                            return $this->badRequest('TOPUP_DELTA_INVALID', 'topup delta invalid.');
                        }

                        $postCommitCtx = [
                            'kind' => 'credit_pack',
                            'org_id' => (int) $order->org_id,
                            'provider' => $provider,
                            'provider_event_id' => $providerEventId,
                            'order_no' => $orderNo,
                            'benefit_code' => $benefitCode,
                            'topup_delta' => $unitQty * $quantity,
                            'event_user_id' => $eventUserId,
                            'event_meta' => $eventBaseMeta,
                            'event_context' => $eventContext,
                            'received_event_meta' => $eventMeta,
                            'received_event_context' => $eventContext,
                        ];
                    } elseif ($kind === 'report_unlock') {
                        $attemptId = (string) ($order->target_attempt_id ?? '');
                        if ($attemptId === '') {
                            $this->markEventError($provider, $providerEventId, 'failed', 'ATTEMPT_REQUIRED', 'target_attempt_id is required.');
                            return $this->badRequest('ATTEMPT_REQUIRED', 'target_attempt_id is required for report_unlock.');
                        }

                        if (!$retryingPostCommitOnly) {
                            $scopeOverride = trim((string) ($skuRow->scope ?? ''));
                            if ($scopeOverride === '') {
                                $scopeOverride = 'attempt';
                            }

                            $expiresAt = null;
                            $skuMeta = $this->decodeMeta($skuRow->meta_json ?? null);
                            $modulesIncluded = $this->normalizeModulesIncluded($skuMeta['modules_included'] ?? null);
                            if ($skuMeta !== []) {
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
                                $expiresAt,
                                $modulesIncluded
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
                        }

                        $postCommitCtx = [
                            'kind' => 'report_unlock',
                            'org_id' => (int) $order->org_id,
                            'provider' => $provider,
                            'provider_event_id' => $providerEventId,
                            'order_no' => $orderNo,
                            'attempt_id' => $attemptId,
                            'event_user_id' => $eventUserId,
                            'event_meta' => $eventBaseMeta,
                            'event_context' => $eventContext,
                            'received_event_meta' => $eventMeta,
                            'received_event_context' => $eventContext,
                            'snapshot_meta' => [
                                'scale_code' => (string) ($attemptMeta['scale_code'] ?? ''),
                                'pack_id' => (string) ($attemptMeta['pack_id'] ?? ''),
                                'dir_version' => (string) ($attemptMeta['dir_version'] ?? ''),
                                'scoring_spec_version' => (string) ($attemptMeta['scoring_spec_version'] ?? ''),
                            ],
                        ];
                    } else {
                        $this->markEventError($provider, $providerEventId, 'failed', 'SKU_KIND_INVALID', 'unsupported sku kind.');
                        return $this->badRequest('SKU_KIND_INVALID', 'unsupported sku kind.');
                    }

                    if ($orderAlreadySettled) {
                        if ($retryingPostCommitOnly) {
                            return [
                                'ok' => true,
                                'duplicate' => false,
                                'order_no' => $orderNo,
                                'provider_event_id' => $providerEventId,
                            ];
                        }

                        Log::info('PAYMENT_EVENT_ALREADY_PROCESSED', [
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

                    return [
                        'ok' => true,
                        'order_no' => $orderNo,
                        'provider_event_id' => $providerEventId,
                    ];
                });
            });

            if (($result['ok'] ?? false) && is_array($postCommitCtx)) {
                $postCommitOutcome = $this->runWebhookPostCommitSideEffects($postCommitCtx);

                if (($postCommitOutcome['ok'] ?? false) === true) {
                    $this->markEventProcessed($provider, $providerEventId);
                } else {
                    $this->markEventError(
                        $provider,
                        $providerEventId,
                        'post_commit_failed',
                        (string) ($postCommitOutcome['error_code'] ?? 'POST_COMMIT_FAILED'),
                        (string) ($postCommitOutcome['error_message'] ?? 'post commit side effects failed.')
                    );
                }
            } elseif (($result['ok'] ?? false) && !($result['duplicate'] ?? false) && !($result['ignored'] ?? false)) {
                $this->markEventProcessed($provider, $providerEventId);
            }

            $snapshotJobCtx = is_array($postCommitOutcome['snapshot_job_ctx'] ?? null)
                ? $postCommitOutcome['snapshot_job_ctx']
                : null;
            if (is_array($snapshotJobCtx) && ($result['ok'] ?? false)) {
                GenerateReportSnapshotJob::dispatch(
                    (int) $snapshotJobCtx['org_id'],
                    (string) $snapshotJobCtx['attempt_id'],
                    (string) $snapshotJobCtx['trigger_source'],
                    $snapshotJobCtx['order_no'] !== null ? (string) $snapshotJobCtx['order_no'] : null,
                )->afterCommit();
            }

            $normalizedResult = $this->normalizeResultStatus($result);
            $this->emitBigFiveWebhookTelemetry(
                $normalizedResult,
                is_array($postCommitCtx) ? $postCommitCtx : null,
                $orgId,
                $provider,
                $providerEventId,
                $orderNo
            );

            return $normalizedResult;
        } catch (LockTimeoutException $e) {
            return $this->serverError('WEBHOOK_BUSY', 'payment webhook is busy, retry later.');
        }
    }

    public function evaluateDryRun(
        string $provider,
        array $payload,
        bool $signatureOk = true
    ): array {
        $provider = strtolower(trim($provider));
        $gateway = $this->gateways[$provider] ?? null;

        if (!$gateway) {
            return $this->errorResult(400, 'PROVIDER_NOT_SUPPORTED', 'provider not supported.', null, [
                'dry_run' => true,
            ]);
        }

        $normalized = $gateway->normalizePayload($payload);
        $eventType = $this->normalizeEventType($normalized);
        $providerEventId = trim((string) ($normalized['provider_event_id'] ?? ''));
        $orderNo = trim((string) ($normalized['order_no'] ?? ''));

        if ($providerEventId === '' || $orderNo === '') {
            return $this->errorResult(400, 'PAYLOAD_INVALID', 'provider_event_id and order_no are required.', $normalized, [
                'dry_run' => true,
                'normalized' => $normalized,
            ]);
        }

        if ($signatureOk !== true) {
            return $this->errorResult(400, 'INVALID_SIGNATURE', 'invalid signature.', null, [
                'dry_run' => true,
                'provider_event_id' => $providerEventId,
                'order_no' => $orderNo,
                'event_type' => $eventType,
            ]);
        }

        $isRefund = $this->isRefundEvent($eventType, $normalized);
        if (!$isRefund && !$this->isAllowedSuccessEventType($provider, $eventType)) {
            return $this->errorResult(404, 'EVENT_TYPE_NOT_ALLOWED', 'event type not allowed.', null, [
                'dry_run' => true,
                'provider_event_id' => $providerEventId,
                'order_no' => $orderNo,
                'event_type' => $eventType,
            ]);
        }

        return [
            'ok' => true,
            'dry_run' => true,
            'provider' => $provider,
            'provider_event_id' => $providerEventId,
            'order_no' => $orderNo,
            'event_type' => $eventType,
            'is_refund' => $isRefund,
        ];
    }

    private function runWebhookPostCommitSideEffects(array $ctx): array
    {
        $kind = strtolower(trim((string) ($ctx['kind'] ?? '')));
        $orgId = (int) ($ctx['org_id'] ?? 0);
        $provider = strtolower(trim((string) ($ctx['provider'] ?? '')));
        $providerEventId = trim((string) ($ctx['provider_event_id'] ?? ''));
        $orderNo = trim((string) ($ctx['order_no'] ?? ''));
        $eventUserId = $this->numericUserId(
            is_string($ctx['event_user_id'] ?? null) ? (string) $ctx['event_user_id'] : null
        );
        $eventMeta = is_array($ctx['event_meta'] ?? null) ? $ctx['event_meta'] : [];
        $eventContext = is_array($ctx['event_context'] ?? null) ? $ctx['event_context'] : [];
        $receivedEventMeta = is_array($ctx['received_event_meta'] ?? null) ? $ctx['received_event_meta'] : [];
        $receivedEventContext = is_array($ctx['received_event_context'] ?? null) ? $ctx['received_event_context'] : [];
        $outcome = [
            'ok' => true,
            'snapshot_job_ctx' => null,
            'error_code' => null,
            'error_message' => null,
        ];

        if ($receivedEventMeta !== [] || $receivedEventContext !== []) {
            try {
                $this->events->record('payment_webhook_received', $eventUserId, $receivedEventMeta, $receivedEventContext);
            } catch (\Throwable $e) {
                Log::error('PAYMENT_WEBHOOK_POST_COMMIT_EVENT_FAILED', [
                    'event' => 'payment_webhook_received',
                    'provider' => $provider,
                    'provider_event_id' => $providerEventId,
                    'order_no' => $orderNo,
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        if ($kind === 'credit_pack') {
            $benefitCode = strtoupper(trim((string) ($ctx['benefit_code'] ?? '')));
            $topupDelta = (int) ($ctx['topup_delta'] ?? 0);
            if ($benefitCode === '' || $topupDelta <= 0) {
                $outcome['ok'] = false;
                $outcome['error_code'] = 'TOPUP_CONTEXT_INVALID';
                $outcome['error_message'] = 'topup context invalid.';
            } else {
                try {
                    $topupKey = "TOPUP:{$provider}:{$providerEventId}";
                    $wallet = $this->wallets->topUp(
                        $orgId,
                        $benefitCode,
                        $topupDelta,
                        $topupKey,
                        [
                            'order_no' => $orderNo,
                            'provider_event_id' => $providerEventId,
                            'provider' => $provider,
                        ]
                    );

                    if (!($wallet['ok'] ?? false)) {
                        Log::warning('PAYMENT_WEBHOOK_POST_COMMIT_TOPUP_FAILED', [
                            'provider' => $provider,
                            'provider_event_id' => $providerEventId,
                            'order_no' => $orderNo,
                            'org_id' => $orgId,
                            'benefit_code' => $benefitCode,
                            'wallet_error_code' => $wallet['error'] ?? 'WALLET_TOPUP_FAILED',
                            'message' => $wallet['message'] ?? 'wallet topup failed.',
                        ]);
                        $outcome['ok'] = false;
                        $outcome['error_code'] = (string) ($wallet['error'] ?? 'WALLET_TOPUP_FAILED');
                        $outcome['error_message'] = (string) ($wallet['message'] ?? 'wallet topup failed.');
                    } else {
                        try {
                            $this->events->record('wallet_topped_up', $eventUserId, $eventMeta, $eventContext);
                        } catch (\Throwable $e) {
                            Log::error('PAYMENT_WEBHOOK_POST_COMMIT_EVENT_FAILED', [
                                'event' => 'wallet_topped_up',
                                'provider' => $provider,
                                'provider_event_id' => $providerEventId,
                                'order_no' => $orderNo,
                                'error_message' => $e->getMessage(),
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('PAYMENT_WEBHOOK_POST_COMMIT_TOPUP_EXCEPTION', [
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_no' => $orderNo,
                        'org_id' => $orgId,
                        'benefit_code' => $benefitCode,
                        'error_message' => $e->getMessage(),
                    ]);
                    $outcome['ok'] = false;
                    $outcome['error_code'] = 'WALLET_TOPUP_EXCEPTION';
                    $outcome['error_message'] = $e->getMessage();
                }
            }
        } elseif ($kind === 'report_unlock') {
            try {
                $this->events->record('entitlement_granted', $eventUserId, $eventMeta, $eventContext);
            } catch (\Throwable $e) {
                Log::error('PAYMENT_WEBHOOK_POST_COMMIT_EVENT_FAILED', [
                    'event' => 'entitlement_granted',
                    'provider' => $provider,
                    'provider_event_id' => $providerEventId,
                    'order_no' => $orderNo,
                    'error_message' => $e->getMessage(),
                ]);
            }

            $attemptId = trim((string) ($ctx['attempt_id'] ?? ''));
            if ($attemptId === '') {
                $outcome['ok'] = false;
                $outcome['error_code'] = 'ATTEMPT_REQUIRED';
                $outcome['error_message'] = 'target_attempt_id is required for report_unlock.';
            } else {
                $snapshotMeta = is_array($ctx['snapshot_meta'] ?? null) ? $ctx['snapshot_meta'] : [];
                try {
                    $this->reportSnapshots->seedPendingSnapshot($orgId, $attemptId, $orderNo !== '' ? $orderNo : null, [
                        'scale_code' => (string) ($snapshotMeta['scale_code'] ?? ''),
                        'pack_id' => (string) ($snapshotMeta['pack_id'] ?? ''),
                        'dir_version' => (string) ($snapshotMeta['dir_version'] ?? ''),
                        'scoring_spec_version' => (string) ($snapshotMeta['scoring_spec_version'] ?? ''),
                    ]);

                    $outcome['snapshot_job_ctx'] = [
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'trigger_source' => 'payment',
                        'order_no' => $orderNo !== '' ? $orderNo : null,
                    ];
                } catch (\Throwable $e) {
                    Log::error('PAYMENT_WEBHOOK_POST_COMMIT_SEED_SNAPSHOT_FAILED', [
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_no' => $orderNo,
                        'org_id' => $orgId,
                        'attempt_id' => $attemptId,
                        'error_message' => $e->getMessage(),
                    ]);
                    $outcome['ok'] = false;
                    $outcome['error_code'] = 'SEED_SNAPSHOT_FAILED';
                    $outcome['error_message'] = $e->getMessage();
                }
            }
        } else {
            $outcome['ok'] = false;
            $outcome['error_code'] = 'POST_COMMIT_KIND_INVALID';
            $outcome['error_message'] = 'unsupported post commit kind.';
        }

        if ($eventMeta !== [] || $eventContext !== []) {
            try {
                $this->events->record('purchase_success', $eventUserId, $eventMeta, $eventContext);
            } catch (\Throwable $e) {
                Log::error('PAYMENT_WEBHOOK_POST_COMMIT_EVENT_FAILED', [
                    'event' => 'purchase_success',
                    'provider' => $provider,
                    'provider_event_id' => $providerEventId,
                    'order_no' => $orderNo,
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        return $outcome;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed>|null $postCommitCtx
     */
    private function emitBigFiveWebhookTelemetry(
        array $result,
        ?array $postCommitCtx,
        int $orgId,
        string $provider,
        string $providerEventId,
        string $orderNo
    ): void {
        if (!$this->bigFiveTelemetry instanceof BigFiveTelemetry) {
            return;
        }

        $meta = is_array($postCommitCtx['event_meta'] ?? null) ? $postCommitCtx['event_meta'] : [];
        $snapshotMeta = is_array($postCommitCtx['snapshot_meta'] ?? null) ? $postCommitCtx['snapshot_meta'] : [];

        $scaleCode = strtoupper(trim((string) ($snapshotMeta['scale_code'] ?? ($meta['scale_code'] ?? ''))));
        $attemptId = trim((string) ($postCommitCtx['attempt_id'] ?? ($meta['attempt_id'] ?? '')));
        $orgId = (int) ($postCommitCtx['org_id'] ?? $orgId);
        $anonId = null;
        $locale = '';
        $region = '';

        if ($attemptId === '' && $orderNo !== '') {
            $orderRow = DB::table('orders')
                ->where('order_no', $orderNo)
                ->where('org_id', $orgId)
                ->first();
            if ($orderRow) {
                $attemptId = trim((string) ($orderRow->target_attempt_id ?? ''));
                if ($attemptId === '') {
                    $attemptId = trim((string) ($orderRow->attempt_id ?? ''));
                }
            }
        }

        if ($attemptId !== '') {
            $attemptRow = DB::table('attempts')->where('id', $attemptId)->first();
            if ($attemptRow) {
                $anonId = $attemptRow->anon_id ? (string) $attemptRow->anon_id : null;
                $locale = (string) ($attemptRow->locale ?? '');
                $region = (string) ($attemptRow->region ?? '');
                if ($orgId <= 0) {
                    $orgId = (int) ($attemptRow->org_id ?? 0);
                }
                if ($scaleCode === '') {
                    $scaleCode = strtoupper(trim((string) ($attemptRow->scale_code ?? '')));
                }
            }
        }

        if ($scaleCode !== 'BIG5_OCEAN') {
            return;
        }

        $status = 'failed';
        if (($result['ok'] ?? false) === true) {
            $status = ($result['duplicate'] ?? false) === true ? 'duplicate' : 'processed';
        } elseif (is_string($result['error_code'] ?? null) && trim((string) $result['error_code']) !== '') {
            $status = strtolower(trim((string) $result['error_code']));
        }

        $this->bigFiveTelemetry->recordPaymentWebhookProcessed(
            $orgId,
            $this->numericUserId(is_string($postCommitCtx['event_user_id'] ?? null) ? $postCommitCtx['event_user_id'] : null),
            $anonId,
            $attemptId !== '' ? $attemptId : null,
            $locale,
            $region,
            $status,
            (string) ($meta['sku'] ?? ''),
            (string) ($meta['sku'] ?? ''),
            $provider,
            $providerEventId,
            $orderNo
        );
    }

    private function numericUserId(?string $userId): ?int
    {
        $userId = $userId !== null ? trim($userId) : '';
        if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
            return null;
        }

        return (int) $userId;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function normalizeModulesIncluded(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($raw)) {
            return [];
        }

        return ReportAccess::normalizeModules($raw);
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

        if (!$order) {
            $order = DB::table('orders')
                ->where('order_no', $orderNo)
                ->where('org_id', $orgId)
                ->first();
        }

        if ($order) {
            $orderOrgId = (int) ($order->org_id ?? $orgId);
            $orderUserId = $order->user_id ? (string) $order->user_id : null;
            $sku = strtoupper((string) ($order->sku ?? $order->item_sku ?? ''));
            if ($sku !== '') {
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

    private function buildPayloadSummary(array $normalized, string $eventType, string $rawSha256, int $rawBytes): array
    {
        $providerEventId = trim((string) ($normalized['provider_event_id'] ?? ''));
        $orderNo = trim((string) ($normalized['order_no'] ?? ''));
        $externalTradeNo = trim((string) ($normalized['external_trade_no'] ?? ''));

        $amount = $normalized['amount_cents'] ?? null;
        if (!is_numeric($amount)) {
            $amount = null;
        } else {
            $amount = (int) $amount;
        }

        $currency = strtoupper(trim((string) ($normalized['currency'] ?? '')));
        if ($currency === '') {
            $currency = null;
        }

        return [
            'provider_event_id' => $providerEventId !== '' ? $providerEventId : null,
            'order_no' => $orderNo !== '' ? $orderNo : null,
            'event_type' => $eventType !== '' ? $eventType : null,
            'amount_cents' => $amount,
            'currency' => $currency,
            'external_trade_no' => $externalTradeNo !== '' ? $externalTradeNo : null,
            'raw_sha256' => $rawSha256,
            'raw_bytes' => $rawBytes,
        ];
    }

    private function encodePayloadSummary(array $summary): string
    {
        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return '{}';
        }

        return $encoded;
    }

    private function buildPayloadExcerpt(string $payloadSummaryJson, int $maxBytes = 8192): string
    {
        if ($maxBytes <= 0) {
            return '';
        }

        if (strlen($payloadSummaryJson) <= $maxBytes) {
            return $payloadSummaryJson;
        }

        return substr($payloadSummaryJson, 0, $maxBytes);
    }

    private function resolvePayloadMeta(
        array $payload,
        array $payloadMeta,
        string $rawPayloadSha256,
        int $rawPayloadBytes
    ): array
    {
        $rawFallback = $this->resolvePayloadRawFallback($payload);

        $size = $rawPayloadBytes >= 0 ? $rawPayloadBytes : ($payloadMeta['size_bytes'] ?? null);
        if (!is_numeric($size)) {
            $size = strlen($rawFallback);
        }
        $size = max(0, (int) $size);

        $sha = strtolower(trim($rawPayloadSha256));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            $sha = strtolower(trim((string) ($payloadMeta['sha256'] ?? '')));
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            $sha = hash('sha256', $rawFallback);
        }

        $s3Key = trim((string) ($payloadMeta['s3_key'] ?? ''));
        if ($s3Key === '') {
            $s3Key = null;
        } else {
            $s3Key = substr($s3Key, 0, 255);
        }

        return [
            'size_bytes' => $size,
            'sha256' => $sha,
            's3_key' => $s3Key,
        ];
    }

    private function resolvePayloadRawFallback(array $payload): string
    {
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($raw)) {
            return '';
        }

        return $raw;
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
        if ($provider === '' || $providerEventId === '') {
            return;
        }

        DB::table('payment_events')
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->update($updates);
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
        if ($attemptId === '') {
            return [
                'attempt_id' => null,
                'scale_code' => null,
                'pack_id' => null,
                'dir_version' => null,
            ];
        }

        $row = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
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

    private function isStubEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
    }

    private function normalizeResultStatus(array $result): array
    {
        $isOk = ($result['ok'] ?? false) === true;
        if (!$isOk) {
            $result = $this->canonicalizeErrorResult($result);
        }

        if (array_key_exists('status', $result)) {
            $candidate = (int) $result['status'];
            if ($candidate >= 100 && $candidate <= 599) {
                $result['status'] = $candidate;
                return $result;
            }
        }

        $result['status'] = $isOk ? 200 : 500;
        return $result;
    }

    private function badRequest(string $code, string $message): array
    {
        return $this->errorResult(400, $code, $message);
    }

    private function serverError(string $code, string $message): array
    {
        return $this->errorResult(500, $code, $message);
    }

    private function notFound(string $code, string $message): array
    {
        return $this->errorResult(404, $code, $message);
    }

    private function errorResult(
        int $status,
        string $errorCode,
        string $message,
        mixed $details = null,
        array $extra = []
    ): array {
        $base = [
            'ok' => false,
            'error_code' => $this->normalizeErrorCode($errorCode),
            'message' => trim($message) !== '' ? trim($message) : 'request failed',
            'details' => $this->normalizeDetailsValue($details),
            'status' => $status,
        ];

        return array_merge($base, $extra);
    }

    private function canonicalizeErrorResult(array $result): array
    {
        $errorCode = $this->firstNonEmptyString([
            $result['error_code'] ?? null,
            $result['error'] ?? null,
            $result['message'] ?? null,
        ]);
        $message = $this->firstNonEmptyString([
            $result['message'] ?? null,
            $result['error'] ?? null,
        ]);

        $result['ok'] = false;
        $result['error_code'] = $this->normalizeErrorCode($errorCode);
        $result['message'] = $message !== '' ? $message : 'request failed';
        $details = array_key_exists('details', $result) ? $result['details'] : ($result['errors'] ?? null);
        $result['details'] = $this->normalizeDetailsValue($details);

        unset($result['error'], $result['errors']);

        return $result;
    }

    private function normalizeErrorCode(string $raw): string
    {
        $code = trim($raw);
        if ($code === '') {
            return 'HTTP_ERROR';
        }

        $code = str_replace(['-', ' '], '_', $code);
        $code = (string) preg_replace('/[^A-Za-z0-9_]+/', '_', $code);
        $code = trim($code, '_');

        return $code !== '' ? strtoupper($code) : 'HTTP_ERROR';
    }

    private function normalizeDetailsValue(mixed $details): mixed
    {
        if (is_array($details) && $details === []) {
            return null;
        }

        if (is_object($details) && count((array) $details) === 0) {
            return null;
        }

        return $details;
    }

    /**
     * @param array<int, mixed> $candidates
     */
    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) && !is_numeric($candidate)) {
                continue;
            }

            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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

    private function validatePaidEventGuard(string $provider, string $eventType, array $normalized, object $order): array
    {
        if (!$this->isAllowedSuccessEventType($provider, $eventType)) {
            return [
                'ok' => false,
                'code' => 'EVENT_TYPE_NOT_ALLOWED',
                'message' => 'event type not allowed.',
            ];
        }

        $normalizedAmount = (int) ($normalized['amount_cents'] ?? 0);
        $orderAmount = (int) ($order->amount_cents ?? 0);
        if ($normalizedAmount !== $orderAmount) {
            return [
                'ok' => false,
                'code' => 'AMOUNT_MISMATCH',
                'message' => 'amount mismatch.',
            ];
        }

        $normalizedCurrency = $this->normalizeCurrency($normalized['currency'] ?? null);
        $orderCurrency = $this->normalizeCurrency($order->currency ?? null);
        if ($normalizedCurrency === '' || $orderCurrency === '' || $normalizedCurrency !== $orderCurrency) {
            return [
                'ok' => false,
                'code' => 'CURRENCY_MISMATCH',
                'message' => 'currency mismatch.',
            ];
        }

        return ['ok' => true];
    }

    private function normalizeCurrency(mixed $currency): string
    {
        return strtoupper(trim((string) $currency));
    }

    /**
     * @return array<int, string>
     */
    private function allowedSuccessEventTypes(string $provider): array
    {
        $provider = strtolower(trim($provider));
        $configured = config("services.payment_webhook.success_event_types.{$provider}");
        $types = is_array($configured) ? $configured : [];
        if (count($types) === 0) {
            $types = match ($provider) {
                'stripe' => [
                    'payment_succeeded',
                    'payment_intent.succeeded',
                    'charge.succeeded',
                    'checkout.session.completed',
                    'invoice.payment_succeeded',
                ],
                'billing' => [
                    'payment_succeeded',
                    'payment.success',
                    'payment_completed',
                    'paid',
                ],
                default => ['payment_succeeded'],
            };
        }

        $normalized = [];
        foreach ($types as $type) {
            $value = strtolower(trim((string) $type));
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function isAllowedSuccessEventType(string $provider, string $eventType): bool
    {
        $eventType = strtolower(trim($eventType));
        if ($eventType === '') {
            return false;
        }

        return in_array($eventType, $this->allowedSuccessEventTypes($provider), true);
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
            'refund_amount_cents' => $refundAmount > 0 ? $refundAmount : ($order->refund_amount_cents ?? null),
        ];
        if (empty($order->refunded_at)) {
            $updates['refunded_at'] = $now;
        }
        if ($refundReason !== '') {
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
