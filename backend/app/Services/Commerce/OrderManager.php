<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Services\Email\EmailOutboxService;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Report\ReportAccess;
use App\Services\Scale\ScaleIdentityWriteProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderManager
{
    private const FINAL_STATUSES = ['fulfilled', 'failed', 'canceled', 'refunded'];

    private const MAX_ORDER_QUANTITY = 1000;

    private const MAX_INT32 = 2147483647;

    public function __construct(
        private SkuCatalog $skus,
        private ScaleIdentityWriteProjector $identityProjector,
        private EmailOutboxService $emailOutbox,
        private PaymentRecoveryToken $paymentRecoveryTokens,
        private PaymentProviderRegistry $paymentProviders,
    ) {}

    public function createOrder(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $sku,
        int $quantity,
        ?string $targetAttemptId,
        string $provider,
        ?string $idempotencyKey = null,
        ?string $contactEmail = null,
        ?string $requestId = null,
        array $attribution = [],
        array $emailCapture = [],
    ): array {
        $requestedSku = $this->skus->normalizeSku($sku);
        if ($requestedSku === '') {
            return $this->badRequest('SKU_REQUIRED', 'sku is required.');
        }

        $resolved = $this->skus->resolveSkuMeta($requestedSku, null, $orgId);
        $effectiveSku = strtoupper(trim((string) ($resolved['effective_sku'] ?? '')));
        $entitlementId = $resolved['entitlement_id'] ?? null;
        $requestedSku = strtoupper(trim((string) ($resolved['requested_sku'] ?? $requestedSku)));

        $quantity = (int) $quantity;
        if ($quantity < 1 || $quantity > self::MAX_ORDER_QUANTITY) {
            return $this->badRequest('QUANTITY_INVALID', 'quantity out of range.');
        }

        $skuRow = $resolved['sku_row'] ?? null;
        if (! $skuRow) {
            return $this->notFound('SKU_NOT_FOUND', 'sku not found.');
        }

        $skuMeta = $this->decodeMeta($skuRow->meta_json ?? null);
        $modulesIncluded = $this->normalizeModulesIncluded($skuMeta['modules_included'] ?? null);

        $unitPriceCents = (int) ($skuRow->price_cents ?? 0);
        if ($unitPriceCents < 0) {
            return $this->badRequest('PRICE_INVALID', 'price invalid.');
        }
        if ($unitPriceCents > 0 && $quantity > intdiv(self::MAX_INT32, $unitPriceCents)) {
            return $this->badRequest('AMOUNT_TOO_LARGE', 'amount too large.');
        }

        $skuToLookup = $effectiveSku !== '' ? $effectiveSku : $requestedSku;
        $provider = strtolower(trim($provider));
        if ($provider === '' || ! in_array($provider, $this->allowedProviders(), true)) {
            return $this->badRequest('PROVIDER_NOT_SUPPORTED', 'provider not supported.');
        }

        $normalizedUserId = $this->trimOrNull($userId);
        $normalizedAnonId = $this->trimOrNull($anonId);
        $contactEmailHash = $this->hashContactEmail($contactEmail);
        if ($normalizedUserId === null && $normalizedAnonId === null && $contactEmailHash === null) {
            return $this->badRequest('EMAIL_REQUIRED', 'email is required.');
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $useIdempotency = $idempotencyKey !== '';

        $createRow = function () use (
            $orgId,
            $normalizedUserId,
            $normalizedAnonId,
            $skuToLookup,
            $quantity,
            $targetAttemptId,
            $provider,
            $skuRow,
            $unitPriceCents,
            $requestedSku,
            $effectiveSku,
            $entitlementId,
            $idempotencyKey,
            $useIdempotency,
            $modulesIncluded,
            $contactEmailHash,
            $requestId,
            $attribution,
            $emailCapture
        ): array {
            $orderNo = 'ord_'.Str::uuid();
            $now = now();

            $orderMeta = [];
            if ($modulesIncluded !== []) {
                $orderMeta['modules_included'] = $modulesIncluded;
            }
            $normalizedAttribution = $this->normalizeAttribution($attribution);
            if ($normalizedAttribution !== []) {
                $orderMeta['attribution'] = $normalizedAttribution;
            }
            $normalizedEmailCapture = $this->normalizeEmailCapture($emailCapture, $contactEmailHash);
            if ($normalizedEmailCapture !== []) {
                $orderMeta['email_capture'] = $normalizedEmailCapture;
            }

            $row = [
                'id' => (string) Str::uuid(),
                'order_no' => $orderNo,
                'org_id' => $orgId,
                'user_id' => $normalizedUserId,
                'anon_id' => $normalizedAnonId,
                'sku' => $skuToLookup,
                'quantity' => $quantity,
                'target_attempt_id' => $this->trimOrNull($targetAttemptId),
                'amount_cents' => $unitPriceCents * $quantity,
                'currency' => (string) ($skuRow->currency ?? 'USD'),
                'status' => 'created',
                'provider' => $provider,
                'external_trade_no' => null,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'requested_sku' => $requestedSku,
                'effective_sku' => $effectiveSku !== '' ? $effectiveSku : $skuToLookup,
                'entitlement_id' => $entitlementId,
                'meta_json' => $orderMeta !== []
                    ? json_encode($orderMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    : null,
            ];
            if (Schema::hasColumn('orders', 'contact_email_hash')) {
                $row['contact_email_hash'] = $contactEmailHash;
            }

            if ($this->shouldWriteScaleIdentityColumns() && $this->ordersTableHasIdentityColumns()) {
                $identity = $this->resolveOrderScaleIdentity($orgId, $targetAttemptId);
                $row['scale_code_v2'] = $identity['scale_code_v2'];
                $row['scale_uid'] = $identity['scale_uid'];
            }

            if ($useIdempotency) {
                $row['idempotency_key'] = $idempotencyKey;
            }

            return $this->applyLegacyColumns($row, $requestId);
        };

        if ($useIdempotency) {
            return DB::transaction(function () use ($orgId, $provider, $idempotencyKey, $createRow) {
                $existing = $this->findIdempotentOrder($orgId, $provider, $idempotencyKey, true);
                if ($existing) {
                    return [
                        'ok' => true,
                        'order_no' => $existing->order_no ?? null,
                        'order' => $existing,
                        'idempotent' => true,
                    ];
                }

                $row = $createRow();
                $inserted = DB::table('orders')->insertOrIgnore($row);
                if ((int) $inserted === 0) {
                    $existing = $this->findIdempotentOrder($orgId, $provider, $idempotencyKey, true);
                    if ($existing) {
                        return [
                            'ok' => true,
                            'order_no' => $existing->order_no ?? null,
                            'order' => $existing,
                            'idempotent' => true,
                        ];
                    }
                }

                $order = DB::table('orders')->where('order_no', $row['order_no'])->first();

                return [
                    'ok' => true,
                    'order_no' => $order->order_no ?? $row['order_no'],
                    'order' => $order ?? $row,
                    'idempotent' => false,
                ];
            });
        }

        $row = $createRow();
        DB::table('orders')->insert($row);
        $order = DB::table('orders')->where('order_no', $row['order_no'])->first();

        return [
            'ok' => true,
            'order_no' => $order->order_no ?? $row['order_no'],
            'order' => $order ?? $row,
        ];
    }

    public function findLookupOrder(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $orderNo,
        ?string $contactEmailHash = null
    ): ?object {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return null;
        }

        $query = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId);

        $uid = $this->trimOrNull($userId);
        $aid = $this->trimOrNull($anonId);
        $emailHash = $this->normalizeEmailHash($contactEmailHash);
        $canMatchByEmailHash = $emailHash !== null && Schema::hasColumn('orders', 'contact_email_hash');
        if ($uid === null && $aid === null && ! $canMatchByEmailHash) {
            return null;
        }

        $query->where(function ($scoped) use ($uid, $aid, $canMatchByEmailHash, $emailHash): void {
            if ($uid !== null) {
                $scoped->orWhere('user_id', $uid);
            }

            if ($aid !== null) {
                $scoped->orWhere('anon_id', $aid);
            }

            if ($canMatchByEmailHash && $emailHash !== null) {
                $scoped->orWhere('contact_email_hash', $emailHash);
            }
        });

        return $query->first();
    }

    public function getOrder(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $orderNo,
        bool $allowAnonymousFallback = false,
        ?string $paymentRecoveryToken = null
    ): array {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no is required.');
        }

        $order = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->first();

        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $uid = $this->trimOrNull($userId);
        $aid = $this->trimOrNull($anonId);

        $ownedByUser = $uid !== null && $uid === $this->trimOrNull($order->user_id ?? null);
        $ownedByAnon = $aid !== null && $aid === $this->trimOrNull($order->anon_id ?? null);
        $ownershipVerified = $ownedByUser || $ownedByAnon;
        $paymentRecoveryVerified = false;
        $paymentRecoveryFailure = null;

        $normalizedPaymentRecoveryToken = $this->trimOrNull($paymentRecoveryToken);
        if (! $ownershipVerified && $normalizedPaymentRecoveryToken !== null) {
            $verified = $this->paymentRecoveryTokens->verify($normalizedPaymentRecoveryToken, $orderNo);
            if (($verified['ok'] ?? false) === true) {
                $paymentRecoveryVerified = true;
            } else {
                $paymentRecoveryFailure = [
                    'ok' => false,
                    'error' => (string) ($verified['error'] ?? 'PAYMENT_RECOVERY_TOKEN_INVALID'),
                    'message' => (string) ($verified['message'] ?? 'payment recovery token invalid.'),
                ];
            }
        }

        if (! $ownershipVerified && ! $paymentRecoveryVerified && $uid === null && $aid === null && $allowAnonymousFallback) {
            return [
                'ok' => true,
                'order' => $order,
                'ownership_verified' => false,
                'payment_recovery_verified' => false,
            ];
        }

        if (! $ownershipVerified && $paymentRecoveryFailure !== null) {
            return $paymentRecoveryFailure;
        }

        if (! $ownershipVerified && ! $paymentRecoveryVerified) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        return [
            'ok' => true,
            'order' => $order,
            'ownership_verified' => $ownershipVerified,
            'payment_recovery_verified' => $paymentRecoveryVerified,
        ];
    }

    public function issuePaymentRecoveryToken(object $order): ?string
    {
        $orderNo = $this->trimOrNull((string) ($order->order_no ?? ''));
        if ($orderNo === null) {
            return null;
        }

        return $this->paymentRecoveryTokens->issue($orderNo);
    }

    /**
     * @return array{wait_url:?string,result_url:?string}
     */
    public function presentPaymentRecoveryUrls(
        object $order,
        ?string $paymentRecoveryToken = null,
        ?string $fallbackLocale = null
    ): array {
        $base = $this->frontendBaseUrl();
        if ($base === null) {
            return [
                'wait_url' => null,
                'result_url' => null,
            ];
        }

        $orderNo = $this->trimOrNull((string) ($order->order_no ?? ''));
        $orgId = (int) ($order->org_id ?? 0);
        $attemptId = $this->resolveKnownAttemptId($orgId, $order->target_attempt_id ?? null);
        $localeSegment = $this->frontendLocaleSegment(
            $this->resolveOrderLocale($orgId, $attemptId, $fallbackLocale)
        );

        $waitUrl = null;
        $normalizedPaymentRecoveryToken = $this->trimOrNull($paymentRecoveryToken);
        if ($orderNo !== null && $normalizedPaymentRecoveryToken !== null) {
            $waitUrl = $base.'/'.$localeSegment.'/pay/wait'
                .'?'.http_build_query([
                    'order_no' => $orderNo,
                    'payment_recovery_token' => $normalizedPaymentRecoveryToken,
                ]);
        }

        return [
            'wait_url' => $waitUrl,
            'result_url' => $attemptId !== null
                ? $base.'/'.$localeSegment.'/result/'.urlencode($attemptId)
                : null,
        ];
    }

    public function findOrderByOrderNo(string $orderNo, int $orgId): ?object
    {
        $normalizedOrderNo = trim($orderNo);
        if ($normalizedOrderNo === '') {
            return null;
        }

        return DB::table('orders')
            ->where('order_no', $normalizedOrderNo)
            ->where('org_id', $orgId)
            ->first();
    }

    public function findLatestAccessibleOrderForAttempt(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $attemptId
    ): ?object {
        $normalizedAttemptId = $this->trimOrNull($attemptId);
        if ($normalizedAttemptId === null) {
            return null;
        }

        $uid = $this->trimOrNull($userId);
        $aid = $this->trimOrNull($anonId);
        if ($uid === null && $aid === null) {
            return null;
        }

        $query = DB::table('orders')
            ->where('org_id', $orgId)
            ->where('target_attempt_id', $normalizedAttemptId)
            ->where(function ($scoped) use ($uid, $aid): void {
                if ($uid !== null) {
                    $scoped->orWhere('user_id', $uid);
                }

                if ($aid !== null) {
                    $scoped->orWhere('anon_id', $aid);
                }
            })
            ->orderByRaw("
                case
                    when lower(coalesce(status, '')) in ('paid', 'fulfilled') then 0
                    when lower(coalesce(status, '')) in ('created', 'pending') then 1
                    else 2
                end
            ")
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at');

        return $query->first();
    }

    public function isPaidOrFulfilledStatus(?string $status): bool
    {
        return $this->isDeliveryEligibleStatus((string) $status);
    }

    /**
     * @return array{
     *     attempt_id:?string,
     *     delivery:array{
     *         can_view_report:bool,
     *         report_url:?string,
     *         can_download_pdf:bool,
     *         report_pdf_url:?string,
     *         can_resend:bool,
     *         contact_email_present:bool,
     *         last_delivery_email_sent_at:?string,
     *         can_request_claim_email:bool
     *     }
     * }
     */
    public function presentOrderDelivery(object $order): array
    {
        $delivery = $this->compileOrderDeliveryState($order);

        return [
            'attempt_id' => $delivery['attempt_id'],
            'delivery' => $this->presentCompiledOrderDeliveryState($delivery),
        ];
    }

    public function resendDelivery(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $orderNo
    ): array {
        $found = $this->getOrder($orgId, $userId, $anonId, $orderNo);
        if (! ($found['ok'] ?? false)) {
            return $found;
        }

        $order = $found['order'];
        $delivery = $this->compileOrderDeliveryState($order);

        if (($delivery['can_resend'] ?? false) !== true) {
            return [
                'ok' => true,
                'queued' => false,
                'order' => $order,
                'delivery' => $this->presentCompiledOrderDeliveryState($delivery),
            ];
        }

        $queued = $this->emailOutbox->queuePaymentSuccess(
            (string) ($delivery['delivery_user_id'] ?? ''),
            (string) ($delivery['delivery_email'] ?? ''),
            (string) ($delivery['attempt_id'] ?? ''),
            $this->trimOrNull((string) ($order->order_no ?? '')),
            $this->resolveOrderProductSummary($order),
            $this->extractAttributionFromOrder($order)
        );

        return [
            'ok' => true,
            'queued' => (bool) ($queued['ok'] ?? false),
            'order' => $order,
            'delivery' => $this->presentCompiledOrderDeliveryState($delivery),
        ];
    }

    /**
     * @return array{
     *     eligible:bool,
     *     order:?object,
     *     attempt_id:?string,
     *     outbox_user_id:?string,
     *     attribution:array<string,mixed>
     * }
     */
    public function resolveClaimRequestContext(int $orgId, string $orderNo, string $email): array
    {
        $normalizedEmail = $this->normalizeEmail($email);
        if ($normalizedEmail === null) {
            return [
                'eligible' => false,
                'order' => null,
                'attempt_id' => null,
                'outbox_user_id' => null,
                'attribution' => [],
            ];
        }

        $order = DB::table('orders')
            ->where('order_no', trim($orderNo))
            ->where('org_id', $orgId)
            ->first();
        if (! $order) {
            return [
                'eligible' => false,
                'order' => null,
                'attempt_id' => null,
                'outbox_user_id' => null,
                'attribution' => [],
            ];
        }

        $delivery = $this->compileOrderDeliveryState($order);
        $attemptId = $this->trimOrNull((string) ($delivery['attempt_id'] ?? ''));
        if ($attemptId === null || ($delivery['can_request_claim_email'] ?? false) !== true) {
            return [
                'eligible' => false,
                'order' => $order,
                'attempt_id' => $attemptId,
                'outbox_user_id' => null,
                'attribution' => $this->extractAttributionFromOrder($order),
            ];
        }

        $emailMatches = $this->emailMatchesOrderContact($order, $normalizedEmail)
            || $this->emailMatchesOrderUser($order, $normalizedEmail)
            || $this->emailOutbox->hasHistoricalRecipient(
                $attemptId,
                $this->trimOrNull((string) ($order->order_no ?? '')),
                $normalizedEmail
            );

        $outboxUserId = $this->trimOrNull((string) ($delivery['delivery_user_id'] ?? ''));
        if ($outboxUserId === null) {
            $outboxUserId = $this->emailOutbox->resolveDeliveryUserId(
                $this->trimOrNull((string) ($order->user_id ?? '')),
                $this->trimOrNull((string) ($order->anon_id ?? '')),
                $attemptId
            );
        }

        return [
            'eligible' => $emailMatches && $outboxUserId !== null,
            'order' => $order,
            'attempt_id' => $attemptId,
            'outbox_user_id' => $outboxUserId,
            'attribution' => $this->extractAttributionFromOrder($order),
        ];
    }

    public function transitionToPaidAtomic(
        string $orderNo,
        int $orgId,
        ?string $externalTradeNo = null,
        ?string $paidAt = null
    ): array {
        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no is required.');
        }

        $order = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->lockForUpdate()
            ->first();
        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $fromStatus = strtolower((string) ($order->status ?? ''));
        if ($fromStatus === '') {
            $fromStatus = 'created';
        }

        if (in_array($fromStatus, ['paid', 'fulfilled'], true)) {
            $this->syncPurchasedInviteFromOrder($order, $paidAt);

            return [
                'ok' => true,
                'order' => $order,
                'already_paid' => true,
            ];
        }

        if (! $this->isTransitionAllowed($fromStatus, 'paid')) {
            return $this->conflict('ORDER_STATUS_INVALID', 'invalid order status transition.');
        }

        $now = now();
        $updates = [
            'status' => 'paid',
            'updated_at' => $now,
        ];

        if (empty($order->paid_at)) {
            $updates['paid_at'] = ($paidAt !== null && $paidAt !== '') ? $paidAt : $now;
        }

        if ($externalTradeNo) {
            $updates['external_trade_no'] = $externalTradeNo;
        }

        $updated = DB::table('orders')
            ->where('order_no', $orderNo)
            ->where('org_id', $orgId)
            ->where('status', $fromStatus)
            ->update($updates);

        if ($updated === 0) {
            $current = DB::table('orders')->where('order_no', $orderNo)->where('org_id', $orgId)->first();
            if (! $current) {
                return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
            }
            $currentStatus = strtolower((string) ($current->status ?? ''));
            if (in_array($currentStatus, ['paid', 'fulfilled'], true)) {
                return [
                    'ok' => true,
                    'order' => $current,
                    'already_paid' => true,
                ];
            }

            return $this->conflict('ORDER_STATUS_CHANGED', 'order status changed.');
        }

        $order = DB::table('orders')->where('order_no', $orderNo)->where('org_id', $orgId)->first();
        $this->syncPurchasedInviteFromOrder($order, $paidAt);

        return [
            'ok' => true,
            'order' => $order,
            'changed' => true,
        ];
    }

    public function transition(string $orderNo, string $toStatus, ?int $orgId = null): array
    {
        $orderNo = trim($orderNo);
        $toStatus = strtolower(trim($toStatus));
        if ($orderNo === '' || $toStatus === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no and status are required.');
        }

        $query = DB::table('orders')->where('order_no', $orderNo);
        if ($orgId !== null) {
            $query->where('org_id', $orgId);
        }

        $order = $query->first();
        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $fromStatus = strtolower((string) ($order->status ?? ''));
        if ($fromStatus === '') {
            $fromStatus = 'created';
        }

        if ($fromStatus === $toStatus) {
            if (in_array($toStatus, ['paid', 'fulfilled'], true)) {
                $this->syncPurchasedInviteFromOrder($order, null);
            }

            return [
                'ok' => true,
                'order' => $order,
            ];
        }

        if (! $this->isTransitionAllowed($fromStatus, $toStatus)) {
            return $this->conflict('ORDER_STATUS_INVALID', 'invalid order status transition.');
        }

        $updates = [
            'status' => $toStatus,
            'updated_at' => now(),
        ];

        if ($toStatus === 'paid') {
            $updates['paid_at'] = $updates['updated_at'];
        }

        if ($toStatus === 'fulfilled') {
            $updates['fulfilled_at'] = $updates['updated_at'];
        }

        if ($toStatus === 'refunded') {
            $updates['refunded_at'] = $updates['updated_at'];
        }

        $updateQuery = DB::table('orders')->where('order_no', $orderNo);
        if ($orgId !== null) {
            $updateQuery->where('org_id', $orgId);
        }
        $updateQuery->where('status', $fromStatus);

        $updated = $updateQuery->update($updates);
        if ($updated === 0) {
            $current = DB::table('orders')->where('order_no', $orderNo)->first();
            if (! $current) {
                return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
            }

            $currentStatus = strtolower((string) ($current->status ?? ''));
            if ($currentStatus === $toStatus) {
                return [
                    'ok' => true,
                    'order' => $current,
                ];
            }

            return $this->conflict('ORDER_STATUS_CHANGED', 'order status changed.');
        }

        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        if (in_array($toStatus, ['paid', 'fulfilled'], true)) {
            $this->syncPurchasedInviteFromOrder($order, null);
        }

        return [
            'ok' => true,
            'order' => $order,
        ];
    }

    public function mergeAttribution(string $orderNo, int $orgId, array $attribution): void
    {
        $this->mergeCheckoutContext($orderNo, $orgId, $attribution, []);
    }

    public function mergeCheckoutContext(string $orderNo, int $orgId, array $attribution, array $emailCapture): void
    {
        $normalizedAttribution = $this->normalizeAttribution($attribution);
        $normalizedEmailCapture = $this->normalizeEmailCapture($emailCapture);
        if ($normalizedAttribution === [] && $normalizedEmailCapture === []) {
            return;
        }

        $order = DB::table('orders')
            ->where('order_no', trim($orderNo))
            ->where('org_id', $orgId)
            ->first();

        if (! $order) {
            return;
        }

        $meta = $this->decodeMeta($order->meta_json ?? null);
        if ($normalizedAttribution !== []) {
            $existingAttribution = is_array($meta['attribution'] ?? null) ? $meta['attribution'] : [];
            $meta['attribution'] = array_replace_recursive($existingAttribution, $normalizedAttribution);
        }
        if ($normalizedEmailCapture !== []) {
            $existingEmailCapture = is_array($meta['email_capture'] ?? null) ? $meta['email_capture'] : [];
            $meta['email_capture'] = array_replace_recursive($existingEmailCapture, $normalizedEmailCapture);
        }

        DB::table('orders')
            ->where('order_no', (string) $order->order_no)
            ->where('org_id', $orgId)
            ->update([
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    private function isTransitionAllowed(string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === 'created' && in_array($toStatus, ['pending', 'paid', 'failed', 'canceled', 'refunded'], true)) {
            return true;
        }
        if ($fromStatus === 'pending' && in_array($toStatus, ['paid', 'failed', 'canceled', 'refunded'], true)) {
            return true;
        }
        if ($fromStatus === 'paid' && $toStatus === 'fulfilled') {
            return true;
        }
        if ($fromStatus === 'fulfilled' && $toStatus === 'refunded') {
            return true;
        }

        return false;
    }

    private function applyLegacyColumns(array $row, ?string $requestId = null): array
    {
        $row['amount_total'] = $row['amount_cents'];
        $row['amount_refunded'] = 0;
        $row['item_sku'] = $row['sku'];
        $row['provider_order_id'] = null;
        $row['device_id'] = null;
        $row['request_id'] = array_key_exists('request_id', $row)
            ? $this->normalizeRequestId($row['request_id'])
            : $this->normalizeRequestId($requestId);
        $row['created_ip'] = null;
        $row['fulfilled_at'] = null;
        $row['refunded_at'] = null;
        $row['refund_amount_cents'] = null;
        $row['refund_reason'] = null;

        return $row;
    }

    /**
     * @return array{
     *     attempt_id:?string,
     *     report_url:?string,
     *     report_pdf_url:?string,
     *     can_view_report:bool,
     *     can_download_pdf:bool,
     *     can_resend:bool,
     *     delivery_email:?string,
     *     delivery_user_id:?string,
     *     contact_email_present:bool,
     *     last_delivery_email_sent_at:?string,
     *     can_request_claim_email:bool
     * }
     */
    private function compileOrderDeliveryState(object $order): array
    {
        $orgId = (int) ($order->org_id ?? 0);
        $attemptId = $this->resolveKnownAttemptId($orgId, $order->target_attempt_id ?? null);
        $reportUrl = $attemptId !== null ? $this->reportUrl($attemptId) : null;
        $reportPdfUrl = $attemptId !== null ? $this->reportPdfUrl($attemptId) : null;
        $deliveryEligible = $attemptId !== null && $this->isDeliveryEligibleStatus((string) ($order->status ?? ''));
        $recipient = $attemptId !== null
            ? $this->emailOutbox->resolvePaymentSuccessRecipient(
                $this->trimOrNull((string) ($order->user_id ?? '')),
                $this->trimOrNull((string) ($order->anon_id ?? '')),
                $attemptId,
                $this->trimOrNull((string) ($order->order_no ?? ''))
            )
            : ['email' => null, 'user_id' => null];

        $deliveryEmail = $this->trimOrNull((string) ($recipient['email'] ?? ''));
        $deliveryUserId = $this->trimOrNull((string) ($recipient['user_id'] ?? ''));
        $contactEmailPresent = $deliveryEmail !== null || $this->orderHasContactEmailHash($order);
        $lastDeliveryEmailSentAt = $attemptId !== null
            ? $this->emailOutbox->lastDeliveryEmailSentAt(
                $attemptId,
                $this->trimOrNull((string) ($order->order_no ?? ''))
            )
            : null;

        return [
            'attempt_id' => $attemptId,
            'report_url' => $reportUrl,
            'report_pdf_url' => $reportPdfUrl,
            'can_view_report' => $deliveryEligible,
            'can_download_pdf' => $deliveryEligible,
            'can_resend' => $deliveryEligible && $deliveryEmail !== null && $deliveryUserId !== null,
            'delivery_email' => $deliveryEmail,
            'delivery_user_id' => $deliveryUserId,
            'contact_email_present' => $contactEmailPresent,
            'last_delivery_email_sent_at' => $lastDeliveryEmailSentAt,
            'can_request_claim_email' => $deliveryEligible && $contactEmailPresent,
        ];
    }

    /**
     * @param  array{
     *     attempt_id:?string,
     *     report_url:?string,
     *     report_pdf_url:?string,
     *     can_view_report:bool,
     *     can_download_pdf:bool,
     *     can_resend:bool,
     *     delivery_email:?string,
     *     delivery_user_id:?string,
     *     contact_email_present:bool,
     *     last_delivery_email_sent_at:?string,
     *     can_request_claim_email:bool
     * }  $delivery
     * @return array{
     *     can_view_report:bool,
     *     report_url:?string,
     *     can_download_pdf:bool,
     *     report_pdf_url:?string,
     *     can_resend:bool,
     *     contact_email_present:bool,
     *     last_delivery_email_sent_at:?string,
     *     can_request_claim_email:bool
     * }
     */
    private function presentCompiledOrderDeliveryState(array $delivery): array
    {
        return [
            'can_view_report' => (bool) ($delivery['can_view_report'] ?? false),
            'report_url' => $delivery['report_url'] ?? null,
            'can_download_pdf' => (bool) ($delivery['can_download_pdf'] ?? false),
            'report_pdf_url' => $delivery['report_pdf_url'] ?? null,
            'can_resend' => (bool) ($delivery['can_resend'] ?? false),
            'contact_email_present' => (bool) ($delivery['contact_email_present'] ?? false),
            'last_delivery_email_sent_at' => $delivery['last_delivery_email_sent_at'] ?? null,
            'can_request_claim_email' => (bool) ($delivery['can_request_claim_email'] ?? false),
        ];
    }

    private function resolveKnownAttemptId(int $orgId, mixed $candidate): ?string
    {
        $attemptId = trim((string) $candidate);
        if ($attemptId === '') {
            return null;
        }

        $exists = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->exists();

        return $exists ? $attemptId : null;
    }

    private function frontendBaseUrl(): ?string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url', '')), '/');

        return $base !== '' ? $base : null;
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return str_starts_with(mb_strtolower(trim($locale), 'UTF-8'), 'zh') ? 'zh' : 'en';
    }

    private function resolveOrderLocale(int $orgId, ?string $attemptId, ?string $fallbackLocale = null): string
    {
        if ($attemptId !== null) {
            $locale = DB::table('attempts')
                ->where('id', $attemptId)
                ->where('org_id', $orgId)
                ->value('locale');
            $normalized = $this->trimOrNull(is_string($locale) ? $locale : null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $fallback = $this->trimOrNull($fallbackLocale);

        return $fallback ?? 'en';
    }

    private function isDeliveryEligibleStatus(string $status): bool
    {
        return in_array(strtolower(trim($status)), ['paid', 'fulfilled'], true);
    }

    private function resolveOrderProductSummary(object $order): ?string
    {
        $candidates = [
            $order->effective_sku ?? null,
            $order->requested_sku ?? null,
            $order->sku ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = $this->trimOrNull((string) $candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function extractAttributionFromOrder(object $order): array
    {
        $meta = $this->decodeMeta($order->meta_json ?? null);
        $attribution = is_array($meta['attribution'] ?? null) ? $meta['attribution'] : [];

        return $this->normalizeAttribution($attribution);
    }

    /**
     * @return array<string,mixed>
     */
    public function extractEmailCaptureFromOrder(object $order): array
    {
        $meta = $this->decodeMeta($order->meta_json ?? null);
        $emailCapture = is_array($meta['email_capture'] ?? null) ? $meta['email_capture'] : [];

        return $this->normalizeEmailCapture(
            $emailCapture,
            $this->normalizeEmailHash((string) ($order->contact_email_hash ?? ''))
        );
    }

    private function reportUrl(string $attemptId): string
    {
        return "/api/v0.3/attempts/{$attemptId}/report";
    }

    private function reportPdfUrl(string $attemptId): string
    {
        return "/api/v0.3/attempts/{$attemptId}/report.pdf";
    }

    /**
     * @param  array<string, mixed>  $attribution
     * @return array<string, mixed>
     */
    private function normalizeAttribution(array $attribution): array
    {
        $normalized = [];

        foreach ([
            'share_id' => 128,
            'compare_invite_id' => 128,
            'share_click_id' => 128,
            'entrypoint' => 128,
            'referrer' => 2048,
            'landing_path' => 2048,
        ] as $field => $maxLength) {
            $value = $this->trimOrNull($attribution[$field] ?? null);
            if ($value === null) {
                continue;
            }

            $normalized[$field] = mb_strlen($value, 'UTF-8') > $maxLength
                ? mb_substr($value, 0, $maxLength, 'UTF-8')
                : $value;
        }

        $utm = $attribution['utm'] ?? null;
        if (is_array($utm)) {
            $normalizedUtm = [];
            foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
                $value = $this->trimOrNull($utm[$key] ?? null);
                if ($value !== null) {
                    $normalizedUtm[$key] = mb_strlen($value, 'UTF-8') > 512
                        ? mb_substr($value, 0, 512, 'UTF-8')
                        : $value;
                }
            }

            if ($normalizedUtm !== []) {
                $normalized['utm'] = $normalizedUtm;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $emailCapture
     * @return array<string,mixed>
     */
    private function normalizeEmailCapture(array $emailCapture, ?string $contactEmailHash = null): array
    {
        $normalized = [];

        $resolvedContactEmailHash = $contactEmailHash ?? $this->normalizeEmailHash(
            is_scalar($emailCapture['contact_email_hash'] ?? null) ? (string) $emailCapture['contact_email_hash'] : null
        );
        if ($resolvedContactEmailHash !== null) {
            $normalized['contact_email_hash'] = $resolvedContactEmailHash;
        }

        $subscriberStatus = strtolower(trim((string) ($emailCapture['subscriber_status'] ?? '')));
        if (in_array($subscriberStatus, ['active', 'unsubscribed', 'suppressed'], true)) {
            $normalized['subscriber_status'] = $subscriberStatus;
        }

        foreach (['marketing_consent', 'transactional_recovery_enabled'] as $field) {
            if (array_key_exists($field, $emailCapture)) {
                $normalized[$field] = filter_var(
                    $emailCapture[$field],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ) ?? false;
            }
        }

        foreach ([
            'captured_at' => 64,
            'surface' => 64,
            'attempt_id' => 64,
        ] as $field => $maxLength) {
            $value = $this->trimOrNull(is_scalar($emailCapture[$field] ?? null) ? (string) $emailCapture[$field] : null);
            if ($value !== null) {
                $normalized[$field] = mb_strlen($value, 'UTF-8') > $maxLength
                    ? mb_substr($value, 0, $maxLength, 'UTF-8')
                    : $value;
            }
        }

        return array_replace($normalized, $this->normalizeAttribution($emailCapture));
    }

    private function syncPurchasedInviteFromOrder(?object $order, ?string $paidAt): void
    {
        if (! $order || ! Schema::hasTable('mbti_compare_invites')) {
            return;
        }

        $meta = $this->decodeMeta($order->meta_json ?? null);
        $attribution = is_array($meta['attribution'] ?? null) ? $meta['attribution'] : [];
        $compareInviteId = trim((string) ($attribution['compare_invite_id'] ?? ''));
        if ($compareInviteId === '') {
            return;
        }

        $update = [
            'invitee_order_no' => $this->trimOrNull((string) ($order->order_no ?? '')),
            'purchased_at' => $paidAt !== null && trim($paidAt) !== '' ? $paidAt : ($order->paid_at ?? now()),
            'status' => 'purchased',
            'updated_at' => now(),
        ];

        DB::table('mbti_compare_invites')
            ->where('id', $compareInviteId)
            ->update($update);
    }

    private function normalizeRequestId(mixed $value): ?string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        return substr($normalized, 0, 128);
    }

    private function allowedProviders(): array
    {
        return $this->paymentProviders->enabledProviders();
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
        if (! is_array($raw)) {
            return [];
        }

        return ReportAccess::normalizeModules($raw);
    }

    private function notFound(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
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

    private function conflict(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    private function trimOrNull(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function normalizeIdempotencyKey(?string $key): string
    {
        $key = $key !== null ? trim($key) : '';
        if ($key === '') {
            return '';
        }

        if (strlen($key) > 128) {
            $key = substr($key, 0, 128);
        }

        return $key;
    }

    private function hashContactEmail(?string $email): ?string
    {
        $normalized = $this->normalizeEmail($email);
        if ($normalized === null) {
            return null;
        }

        return hash('sha256', $normalized);
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email), 'UTF-8');
        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function normalizeEmailHash(?string $hash): ?string
    {
        $hash = strtolower(trim((string) $hash));
        if ($hash === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return null;
        }

        return $hash;
    }

    private function orderHasContactEmailHash(object $order): bool
    {
        return Schema::hasColumn('orders', 'contact_email_hash')
            && $this->normalizeEmailHash((string) ($order->contact_email_hash ?? '')) !== null;
    }

    private function emailMatchesOrderContact(object $order, string $email): bool
    {
        if (! $this->orderHasContactEmailHash($order)) {
            return false;
        }

        return hash('sha256', $email) === strtolower(trim((string) ($order->contact_email_hash ?? '')));
    }

    private function emailMatchesOrderUser(object $order, string $email): bool
    {
        $userId = $this->trimOrNull((string) ($order->user_id ?? ''));
        if ($userId === null || ! Schema::hasTable('users') || ! Schema::hasColumn('users', 'email')) {
            return false;
        }

        $candidate = DB::table('users')->where('id', $userId)->value('email');
        $normalized = $this->normalizeEmail((string) ($candidate ?? ''));

        return $normalized !== null && $normalized === $email;
    }

    private function shouldWriteScaleIdentityColumns(): bool
    {
        $mode = strtolower(trim((string) config('scale_identity.write_mode', 'legacy')));

        return in_array($mode, ['dual', 'v2'], true);
    }

    private function ordersTableHasIdentityColumns(): bool
    {
        return Schema::hasTable('orders')
            && Schema::hasColumn('orders', 'scale_code_v2')
            && Schema::hasColumn('orders', 'scale_uid');
    }

    /**
     * @return array{scale_code_v2:string|null,scale_uid:string|null}
     */
    private function resolveOrderScaleIdentity(int $orgId, ?string $targetAttemptId): array
    {
        $attemptId = $this->trimOrNull($targetAttemptId);
        if ($attemptId === null) {
            return [
                'scale_code_v2' => null,
                'scale_uid' => null,
            ];
        }

        $attempt = DB::table('attempts')
            ->where('id', $attemptId)
            ->where('org_id', $orgId)
            ->first();
        if (! $attempt) {
            return [
                'scale_code_v2' => null,
                'scale_uid' => null,
            ];
        }

        return $this->identityProjector->projectFromCodes(
            (string) ($attempt->scale_code ?? ''),
            (string) ($attempt->scale_code_v2 ?? ''),
            (string) ($attempt->scale_uid ?? '')
        );
    }

    private function findIdempotentOrder(
        int $orgId,
        string $provider,
        string $idempotencyKey,
        bool $lockForUpdate = false
    ): ?object {
        $query = DB::table('orders')
            ->where('idempotency_key', $idempotencyKey)
            ->where('org_id', $orgId)
            ->where('provider', $provider);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
