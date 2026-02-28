<?php

namespace App\Services\Commerce\Webhook;

use App\Internal\Commerce\PaymentWebhookHandlerCore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookEntitlementService
{
    public function __construct(private PaymentWebhookHandlerCore $core)
    {
    }

    public function handle(array $ctx): array
    {
        $provider = (string) $ctx['provider'];
        $normalized = (array) $ctx['normalized'];
        $providerEventId = (string) $ctx['provider_event_id'];
        $orderNo = (string) $ctx['order_no'];
        $eventType = (string) $ctx['event_type'];
        $orgId = (int) $ctx['org_id'];
        $normalizedOrgId = (int) $ctx['normalized_org_id'];
        $userId = isset($ctx['user_id']) && $ctx['user_id'] !== null ? (string) $ctx['user_id'] : null;
        $anonId = isset($ctx['anon_id']) && $ctx['anon_id'] !== null ? (string) $ctx['anon_id'] : null;
        $signatureOk = (bool) $ctx['signature_ok'];
        $receivedAt = $ctx['received_at'];
        $payloadSummaryJson = (string) $ctx['payload_summary_json'];
        $payloadExcerpt = (string) $ctx['payload_excerpt'];
        $resolvedPayloadMeta = (array) $ctx['resolved_payload_meta'];
        $lockKey = (string) $ctx['lock_key'];
        $lockTtl = (int) $ctx['lock_ttl'];
        $lockBlock = (int) $ctx['lock_block'];
        $contentionBudgetMs = (int) $ctx['contention_budget_ms'];

        $postCommitCtx = is_array($ctx['post_commit_ctx'] ?? null)
            ? $ctx['post_commit_ctx']
            : null;

        $result = $this->core->runWithTransientDbRetry(function () use (
            $lockKey,
            $lockTtl,
            $lockBlock,
            $contentionBudgetMs,
            $orderNo,
            $normalized,
            $providerEventId,
            $provider,
            $orgId,
            $normalizedOrgId,
            $userId,
            $anonId,
            $eventType,
            $receivedAt,
            $payloadSummaryJson,
            $payloadExcerpt,
            $resolvedPayloadMeta,
            $signatureOk,
            &$ctx,
            &$postCommitCtx
        ) {
            $ctx['lock_wait_started_at'] = microtime(true);

            return Cache::lock($lockKey, $lockTtl)->block($lockBlock, function () use (
                $orderNo,
                $normalized,
                $providerEventId,
                $provider,
                $orgId,
                $normalizedOrgId,
                $userId,
                $anonId,
                $eventType,
                $receivedAt,
                $payloadSummaryJson,
                $payloadExcerpt,
                $resolvedPayloadMeta,
                $signatureOk,
                $lockBlock,
                $contentionBudgetMs,
                $lockKey,
                &$ctx,
                &$postCommitCtx
            ) {
                $lockWaitMs = $this->core->resolveLockWaitMs((float) $ctx['lock_wait_started_at']);
                $this->core->observeWebhookLockWait(
                    $provider,
                    $normalizedOrgId,
                    $providerEventId,
                    $lockKey,
                    $lockWaitMs,
                    $lockBlock,
                    $contentionBudgetMs,
                    false
                );

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
                    $requestId = $this->resolveRequestIdForStorage();

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
                        'reason' => null,
                        'processed_at' => null,
                        'handled_at' => null,
                        'handle_status' => null,
                        'payload_size_bytes' => $resolvedPayloadMeta['size_bytes'],
                        'payload_sha256' => $resolvedPayloadMeta['sha256'],
                        'payload_s3_key' => $resolvedPayloadMeta['s3_key'],
                        'payload_excerpt' => $payloadExcerpt,
                        'request_id' => $requestId,
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
                    if (! $eventRow) {
                        return $this->core->serverError('EVENT_INIT_FAILED', 'payment event init failed.');
                    }

                    if ($inserted === 0 && $this->core->isEventProcessed($eventRow)) {
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
                        'reason' => null,
                        'processed_at' => null,
                        'handled_at' => null,
                        'handle_status' => null,
                        'payload_size_bytes' => $resolvedPayloadMeta['size_bytes'],
                        'payload_sha256' => $resolvedPayloadMeta['sha256'],
                        'payload_s3_key' => $resolvedPayloadMeta['s3_key'],
                        'payload_excerpt' => $payloadExcerpt,
                        'request_id' => $requestId,
                        'received_at' => $receivedAt,
                        'updated_at' => $receivedAt,
                    ];

                    DB::table('payment_events')
                        ->where('provider', $provider)
                        ->where('provider_event_id', $providerEventId)
                        ->update($baseRow);

                    if ($signatureOk !== true) {
                        $this->core->markEventError($provider, $providerEventId, 'rejected', 'INVALID_SIGNATURE', 'signature invalid.');

                        return $this->core->badRequest('INVALID_SIGNATURE', 'invalid signature.');
                    }

                    $orderQuery = DB::table('orders')
                        ->where('order_no', $orderNo)
                        ->where('org_id', $orgId);

                    $order = $orderQuery->lockForUpdate()->first();
                    if (! $order) {
                        $this->core->markEventError($provider, $providerEventId, 'orphan', 'ORDER_NOT_FOUND', 'order not found.');

                        return $this->core->semanticReject('ORDER_NOT_FOUND', 'order not found.');
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

                        $this->core->markEventError(
                            $provider,
                            $providerEventId,
                            'rejected',
                            'rejected_provider_mismatch',
                            $detail
                        );

                        return $this->core->semanticReject('PROVIDER_MISMATCH', 'provider mismatch');
                    }

                    $isRefundEvent = $this->core->isRefundEvent($eventType, $normalized);
                    $orderStatus = strtolower((string) ($order->status ?? ''));
                    $orderAlreadySettled = ! $isRefundEvent
                        && in_array($orderStatus, ['paid', 'fulfilled', 'completed', 'delivered', 'refunded'], true);

                    $orderMeta = $this->core->resolveOrderMeta($orgId, $orderNo, $order);
                    $normalizedSkuMeta = $this->core->normalizeOrderSkuMeta($order);
                    $this->core->updatePaymentEvent($provider, $providerEventId, [
                        'order_id' => $order->id ?? null,
                        'event_type' => $eventType,
                        'signature_ok' => $signatureOk,
                        'requested_sku' => $normalizedSkuMeta['requested_sku'] ?? null,
                        'effective_sku' => $normalizedSkuMeta['effective_sku'] ?? null,
                        'entitlement_id' => $normalizedSkuMeta['entitlement_id'] ?? null,
                    ]);

                    $eventUserId = $orderMeta['user_id'] ?? $userId;
                    $eventMeta = $this->core->buildEventMeta($orderMeta, [
                        'provider' => $provider,
                        'provider_event_id' => $providerEventId,
                        'order_no' => $orderNo,
                    ]);
                    $eventContext = $this->core->buildEventContext($orderMeta, $anonId);

                    if ($isRefundEvent) {
                        $refund = $this->core->handleRefund($orderNo, $order, $normalized, $providerEventId, $orgId);
                        if (! ($refund['ok'] ?? false)) {
                            $this->core->markEventError(
                                $provider,
                                $providerEventId,
                                'failed',
                                (string) ($refund['error'] ?? 'REFUND_FAILED'),
                                (string) ($refund['message'] ?? 'refund failed.')
                            );

                            return $refund;
                        }
                        $this->core->markEventProcessed($provider, $providerEventId);

                        return $refund;
                    }

                    $effectiveSku = strtoupper((string) ($normalizedSkuMeta['effective_sku']
                        ?? $order->effective_sku
                        ?? $order->sku
                        ?? $order->item_sku
                        ?? ''));
                    if ($effectiveSku === '') {
                        $this->core->markEventError($provider, $providerEventId, 'failed', 'SKU_NOT_FOUND', 'sku missing on order.');

                        return $this->core->semanticReject('SKU_NOT_FOUND', 'sku missing on order.');
                    }

                    $skuRow = $this->core->skuCatalog()->getActiveSku($effectiveSku, null, (int) ($order->org_id ?? $orgId));
                    if (! $skuRow) {
                        $this->core->markEventError($provider, $providerEventId, 'failed', 'SKU_NOT_FOUND', 'sku not found.');

                        return $this->core->semanticReject('SKU_NOT_FOUND', 'sku not found.');
                    }

                    $guard = $this->core->validatePaidEventGuard($provider, $eventType, $normalized, $order);
                    if (! ($guard['ok'] ?? false)) {
                        $guardCode = (string) ($guard['code'] ?? 'WEBHOOK_REJECTED');
                        $guardMessage = (string) ($guard['message'] ?? 'webhook rejected.');
                        $this->core->markEventError(
                            $provider,
                            $providerEventId,
                            'rejected',
                            $guardCode,
                            $guardMessage
                        );

                        return $this->core->semanticReject($guardCode, $guardMessage);
                    }

                    if (! $orderAlreadySettled) {
                        $orderTransition = $this->core->orderManager()->transitionToPaidAtomic(
                            $orderNo,
                            $orgId,
                            $normalized['external_trade_no'] ?? null,
                            $normalized['paid_at'] ?? null
                        );
                        if (! ($orderTransition['ok'] ?? false)) {
                            $this->core->markEventError(
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
                        $skuMetaForOrder = $this->core->decodeMeta($skuRow->meta_json ?? null);
                        $modulesIncludedForOrder = $this->core->normalizeModulesIncluded($skuMetaForOrder['modules_included'] ?? null);
                        if ($modulesIncludedForOrder !== []) {
                            $orderMeta = $this->core->decodeMeta($order->meta_json ?? null);
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
                        $this->core->markEventError(
                            $provider,
                            $providerEventId,
                            'failed',
                            'BENEFIT_CODE_NOT_FOUND',
                            'benefit code missing on sku.'
                        );

                        return $this->core->semanticReject('BENEFIT_CODE_NOT_FOUND', 'benefit code missing on sku.');
                    }

                    $attemptMeta = $this->core->resolveAttemptMeta((int) $order->org_id, (string) ($order->target_attempt_id ?? ''));
                    $this->core->writePaymentEventScaleIdentity(
                        $provider,
                        $providerEventId,
                        $attemptMeta
                    );
                    $eventBaseMeta = $this->core->buildEventMeta([
                        'org_id' => (int) $order->org_id,
                        'sku' => $effectiveSku,
                        'benefit_code' => $benefitCode,
                        'attempt' => $attemptMeta,
                    ], [
                        'order_no' => $orderNo,
                        'provider_event_id' => $providerEventId,
                    ]);
                    $eventContext = $this->core->buildEventContext([
                        'org_id' => (int) $order->org_id,
                        'attempt' => $attemptMeta,
                    ], $anonId);
                    $eventUserId = $order->user_id ? (string) $order->user_id : $userId;

                    $retryingPostCommitOnly = $inserted === 0
                        && ! $this->core->isEventProcessed($eventRow)
                        && $orderAlreadySettled;

                    if ($kind === 'credit_pack') {
                        $unitQty = (int) ($skuRow->unit_qty ?? 0);
                        if (
                            $unitQty <= 0
                            || $quantity <= 0
                            || $quantity > intdiv(2147483647, $unitQty)
                        ) {
                            $this->core->markEventError($provider, $providerEventId, 'failed', 'TOPUP_DELTA_INVALID', 'topup delta invalid.');

                            return $this->core->semanticReject('TOPUP_DELTA_INVALID', 'topup delta invalid.');
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
                            $this->core->markEventError($provider, $providerEventId, 'failed', 'ATTEMPT_REQUIRED', 'target_attempt_id is required.');

                            return $this->core->semanticReject('ATTEMPT_REQUIRED', 'target_attempt_id is required for report_unlock.');
                        }

                        $ownerGuard = $this->core->validateAttemptOwnershipForOrder($order, $attemptMeta);
                        if (! ($ownerGuard['ok'] ?? false)) {
                            $code = (string) ($ownerGuard['error'] ?? 'ATTEMPT_OWNER_MISMATCH');
                            $message = (string) ($ownerGuard['message'] ?? 'order owner mismatch.');
                            $this->core->markEventError($provider, $providerEventId, 'rejected', $code, $message);

                            return $this->core->semanticReject($code, $message);
                        }

                        $scaleGuard = $this->core->validateAttemptScaleForSku($skuRow, $attemptMeta);
                        if (! ($scaleGuard['ok'] ?? false)) {
                            $code = (string) ($scaleGuard['error'] ?? 'ATTEMPT_SCALE_MISMATCH');
                            $message = (string) ($scaleGuard['message'] ?? 'attempt scale does not match sku scale.');
                            $this->core->markEventError($provider, $providerEventId, 'rejected', $code, $message);

                            return $this->core->semanticReject($code, $message);
                        }

                        if (! $retryingPostCommitOnly) {
                            $scopeOverride = trim((string) ($skuRow->scope ?? ''));
                            if ($scopeOverride === '') {
                                $scopeOverride = 'attempt';
                            }

                            $expiresAt = null;
                            $skuMeta = $this->core->decodeMeta($skuRow->meta_json ?? null);
                            $modulesIncluded = $this->core->normalizeModulesIncluded($skuMeta['modules_included'] ?? null);
                            if ($skuMeta !== []) {
                                $durationDays = isset($skuMeta['duration_days']) ? (int) $skuMeta['duration_days'] : 0;
                                if ($durationDays > 0) {
                                    $expiresAt = now()->addDays($durationDays)->toISOString();
                                }
                            }

                            $grant = $this->core->entitlementManager()->grantAttemptUnlock(
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

                            if (! ($grant['ok'] ?? false)) {
                                $this->core->markEventError(
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
                                'scale_code_v2' => (string) ($attemptMeta['scale_code_v2'] ?? ''),
                                'scale_uid' => (string) ($attemptMeta['scale_uid'] ?? ''),
                                'pack_id' => (string) ($attemptMeta['pack_id'] ?? ''),
                                'dir_version' => (string) ($attemptMeta['dir_version'] ?? ''),
                                'scoring_spec_version' => (string) ($attemptMeta['scoring_spec_version'] ?? ''),
                            ],
                        ];
                    } else {
                        $this->core->markEventError($provider, $providerEventId, 'failed', 'SKU_KIND_INVALID', 'unsupported sku kind.');

                        return $this->core->semanticReject('SKU_KIND_INVALID', 'unsupported sku kind.');
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

                        $this->core->markEventProcessed($provider, $providerEventId);

                        return [
                            'ok' => true,
                            'duplicate' => true,
                            'order_no' => $orderNo,
                            'provider_event_id' => $providerEventId,
                        ];
                    }

                    $fulfilled = $this->core->orderManager()->transition($orderNo, 'fulfilled', $orgId);
                    if (! ($fulfilled['ok'] ?? false)) {
                        $this->core->markEventError(
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
        });

        $ctx['result'] = $result;
        $ctx['post_commit_ctx'] = $postCommitCtx;

        return $ctx;
    }

    private function resolveRequestIdForStorage(): ?string
    {
        $request = request();
        if (! $request instanceof Request) {
            return null;
        }

        foreach ([
            (string) ($request->attributes->get('request_id') ?? ''),
            (string) $request->header('X-Request-Id', ''),
            (string) $request->header('X-Request-ID', ''),
        ] as $candidate) {
            $value = trim($candidate);
            if ($value !== '') {
                return substr($value, 0, 128);
            }
        }

        return null;
    }
}
