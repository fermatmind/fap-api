<?php

declare(strict_types=1);

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentProviderRegistry;
use App\Services\Report\ReportAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReportGiftService
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PURCHASING = 'purchasing';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    private const PROVIDER = 'wechat_mini_virtual';

    public function __construct(
        private readonly OrderManager $orders,
        private readonly SkuCatalog $skus,
        private readonly EntitlementManager $entitlements,
        private readonly PaymentProviderRegistry $paymentProviders,
        private readonly WechatMiniVirtualPaymentService $wechatMiniVirtual,
        private readonly ReportUnlockProductCatalog $products,
        private readonly BigFiveReportUnlockRolloutGate $bigFiveRollout,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function createRequest(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $attemptId
    ): array {
        if (! $this->giftAvailable() || ! $this->paymentProviders->isEnabled(self::PROVIDER)) {
            return $this->error('GIFT_UNAVAILABLE', 'report gifting is unavailable.', 403);
        }

        $attemptId = trim($attemptId);

        return DB::transaction(function () use ($orgId, $userId, $anonId, $attemptId): array {
            return $this->createRequestLocked($orgId, $userId, $anonId, $attemptId);
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function createRequestLocked(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $attemptId
    ): array {
        $attempt = DB::table('attempts')
            ->where('org_id', $orgId)
            ->where('id', $attemptId)
            ->lockForUpdate()
            ->first();
        if (! $attempt) {
            return $this->error('ATTEMPT_NOT_FOUND', 'attempt not found.', 404);
        }
        if (! $this->actorOwnsAttempt($attempt, $userId, $anonId)) {
            return $this->error('ATTEMPT_OWNER_MISMATCH', 'attempt owner mismatch.', 403);
        }

        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        if ($scaleCode === ReportAccess::SCALE_IQ_RAVEN) {
            return $this->error('IQ_GIFT_DISABLED', 'IQ report gifting is unavailable.', 403);
        }
        if ($this->products->forScale($scaleCode) === null) {
            return $this->error('ROLLOUT_DISABLED', 'report gifting is unavailable for this scale.', 403);
        }
        if ($scaleCode === ReportAccess::SCALE_BIG5_OCEAN && ! $this->bigFiveRollout->allows($attempt)) {
            return $this->error('ROLLOUT_DISABLED', 'report gifting is unavailable for this attempt.', 403);
        }

        $sku = $this->skuForScale($scaleCode);
        $skuGuard = $this->validateSku($orgId, $sku);
        if (($skuGuard['ok'] ?? false) !== true) {
            return $skuGuard;
        }

        $eligibility = app(WechatMiniVirtualPaymentService::class)->validateOrderEligibility(
            $orgId,
            $this->normalizeActorId($attempt->user_id ?? null),
            $this->normalizeActorId($attempt->anon_id ?? null),
            $attemptId,
            $sku,
            1
        );
        if (($eligibility['ok'] ?? false) !== true) {
            return $eligibility;
        }

        $existing = DB::table('report_gift_requests')
            ->where('org_id', $orgId)
            ->where('target_attempt_id', $attemptId)
            ->where(function ($query) {
                $query->where('status', self::STATUS_PURCHASING)
                    ->orWhere(function ($pending) {
                        $pending->where('status', self::STATUS_PENDING)
                            ->where('expires_at', '>', now());
                    });
            })
            ->first();
        if ($existing) {
            return $this->error('GIFT_REQUEST_ACTIVE', 'an active gift request already exists.', 409);
        }

        $token = $this->generateToken();
        $now = now();
        $expiresAt = $now->copy()->addHours($this->ttlHours());
        $id = (string) Str::uuid();

        try {
            DB::table('report_gift_requests')->insert([
                'id' => $id,
                'public_token_hash' => $this->tokenHash($token),
                'org_id' => $orgId,
                'target_attempt_id' => $attemptId,
                'recipient_user_id' => $this->normalizeActorId($attempt->user_id ?? null),
                'recipient_anon_id' => $this->normalizeActorId($attempt->anon_id ?? null),
                'scale_code' => $scaleCode,
                'sku' => $sku,
                'status' => self::STATUS_PENDING,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException) {
            return $this->error('GIFT_REQUEST_CREATE_FAILED', 'gift request could not be created.', 409);
        }

        return [
            'ok' => true,
            'gift_request' => [
                'id' => $id,
                'public_token' => $token,
                'status' => self::STATUS_PENDING,
                'expires_at' => $expiresAt->toISOString(),
                'scale_code' => $scaleCode,
                'sku' => $sku,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function showPublic(string $token, int $orgId): array
    {
        $gift = $this->findByToken($token, $orgId);
        if ($gift === null) {
            return $this->error('GIFT_REQUEST_NOT_FOUND', 'gift request not found.', 404);
        }

        return [
            'ok' => true,
            'gift' => $this->publicSummary($gift),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function showOwner(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $attemptId,
        string $giftRequestId
    ): array {
        $gift = DB::table('report_gift_requests')
            ->where('id', trim($giftRequestId))
            ->where('org_id', $orgId)
            ->where('target_attempt_id', trim($attemptId))
            ->first();
        if ($gift === null) {
            return $this->error('GIFT_REQUEST_NOT_FOUND', 'gift request not found.', 404);
        }
        if (! $this->actorMatchesRecipient($gift, $userId, $anonId)) {
            return $this->error('GIFT_REQUEST_FORBIDDEN', 'gift request is not accessible.', 403);
        }

        return [
            'ok' => true,
            'gift_request' => [
                'id' => (string) $gift->id,
                'status' => $this->effectiveStatus($gift),
                'expires_at' => (string) $gift->expires_at,
                'scale_code' => (string) $gift->scale_code,
                'sku' => (string) $gift->sku,
                'fulfilled_at' => $gift->fulfilled_at !== null ? (string) $gift->fulfilled_at : null,
                'canceled_at' => $gift->canceled_at !== null ? (string) $gift->canceled_at : null,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function cancel(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $attemptId,
        string $giftRequestId
    ): array {
        return DB::transaction(function () use ($orgId, $userId, $anonId, $attemptId, $giftRequestId): array {
            $gift = DB::table('report_gift_requests')
                ->where('id', trim($giftRequestId))
                ->where('org_id', $orgId)
                ->where('target_attempt_id', trim($attemptId))
                ->lockForUpdate()
                ->first();
            if ($gift === null) {
                return $this->error('GIFT_REQUEST_NOT_FOUND', 'gift request not found.', 404);
            }
            if (! $this->actorMatchesRecipient($gift, $userId, $anonId)) {
                return $this->error('GIFT_REQUEST_FORBIDDEN', 'gift request is not accessible.', 403);
            }
            if ($this->effectiveStatus($gift) !== self::STATUS_PENDING || $gift->purchased_order_id !== null) {
                return $this->error('GIFT_REQUEST_NOT_CANCELABLE', 'gift request can no longer be canceled.', 409);
            }

            DB::table('report_gift_requests')
                ->where('id', (string) $gift->id)
                ->update([
                    'status' => self::STATUS_CANCELED,
                    'canceled_at' => now(),
                    'updated_at' => now(),
                ]);

            return [
                'ok' => true,
                'gift_request' => [
                    'id' => (string) $gift->id,
                    'status' => self::STATUS_CANCELED,
                ],
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function reserveWechatMiniVirtualOrder(
        string $token,
        int $orgId,
        ?string $payerUserId,
        ?string $payerAnonId,
        string $idempotencyKey,
        ?string $requestId = null
    ): array {
        if (! $this->giftAvailable() || ! $this->paymentProviders->isEnabled(self::PROVIDER)) {
            return $this->error('GIFT_PAYMENT_UNAVAILABLE', 'gift payment is unavailable.', 403);
        }
        if ($this->normalizeActorId($payerUserId) === null && $this->normalizeActorId($payerAnonId) === null) {
            return $this->error('PAYER_IDENTITY_REQUIRED', 'payer identity is required.', 401);
        }
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            return $this->error('IDEMPOTENCY_KEY_REQUIRED', 'idempotency key is required.', 422);
        }

        return DB::transaction(function () use (
            $token,
            $orgId,
            $payerUserId,
            $payerAnonId,
            $idempotencyKey,
            $requestId
        ): array {
            $gift = DB::table('report_gift_requests')
                ->where('public_token_hash', $this->tokenHash($token))
                ->where('org_id', $orgId)
                ->lockForUpdate()
                ->first();
            if ($gift === null) {
                return $this->error('GIFT_REQUEST_NOT_FOUND', 'gift request not found.', 404);
            }

            $status = $this->effectiveStatus($gift);
            if ($status === self::STATUS_EXPIRED) {
                return $this->error('GIFT_REQUEST_EXPIRED', 'gift request has expired.', 410);
            }
            if ($status === self::STATUS_CANCELED) {
                return $this->error('GIFT_REQUEST_CANCELED', 'gift request was canceled.', 409);
            }
            if (in_array($status, [self::STATUS_FULFILLED, self::STATUS_REFUNDED], true)) {
                return $this->error('GIFT_REQUEST_CLOSED', 'gift request is closed.', 409);
            }

            if ($gift->purchased_order_id !== null) {
                if (! $this->actorMatchesPurchaser($gift, $payerUserId, $payerAnonId)) {
                    return $this->error('GIFT_REQUEST_ALREADY_RESERVED', 'gift request already has a purchaser.', 409);
                }
                $order = DB::table('orders')->where('id', (string) $gift->purchased_order_id)->first();
                if ($order === null) {
                    return $this->error('GIFT_ORDER_NOT_FOUND', 'gift order not found.', 409);
                }

                return [
                    'ok' => true,
                    'order' => $order,
                    'order_no' => (string) $order->order_no,
                    'idempotent' => true,
                ];
            }

            $eligibility = $this->wechatMiniVirtual->validateOrderEligibility(
                $orgId,
                $this->normalizeActorId($gift->recipient_user_id ?? null),
                $this->normalizeActorId($gift->recipient_anon_id ?? null),
                (string) $gift->target_attempt_id,
                (string) $gift->sku,
                1
            );
            if (($eligibility['ok'] ?? false) !== true) {
                return $eligibility;
            }

            $orderResult = $this->orders->createOrder(
                $orgId,
                $this->normalizeActorId($payerUserId),
                $this->normalizeActorId($payerAnonId),
                (string) $gift->sku,
                1,
                (string) $gift->target_attempt_id,
                self::PROVIDER,
                $this->giftIdempotencyKey(
                    (string) $gift->id,
                    $idempotencyKey,
                    $this->normalizeActorId($payerUserId),
                    $this->normalizeActorId($payerAnonId),
                    $this->giftOrderReservationGeneration($gift, $payerUserId, $payerAnonId)
                ),
                null,
                $requestId
            );
            if (($orderResult['ok'] ?? false) !== true || ! is_object($orderResult['order'] ?? null)) {
                return $this->error(
                    (string) ($orderResult['error_code'] ?? $orderResult['error'] ?? 'GIFT_ORDER_CREATE_FAILED'),
                    (string) ($orderResult['message'] ?? 'gift order could not be created.'),
                    409
                );
            }

            $order = $orderResult['order'];
            $updated = DB::table('report_gift_requests')
                ->where('id', (string) $gift->id)
                ->whereNull('purchased_order_id')
                ->update([
                    'purchased_order_id' => (string) $order->id,
                    'purchased_by_user_id' => $this->normalizeActorId($payerUserId),
                    'purchased_by_anon_id' => $this->normalizeActorId($payerAnonId),
                    'status' => self::STATUS_PURCHASING,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return $this->error('GIFT_REQUEST_ALREADY_RESERVED', 'gift request already has a purchaser.', 409);
            }

            return [
                'ok' => true,
                'order' => $order,
                'order_no' => (string) $order->order_no,
                'idempotent' => (bool) ($orderResult['idempotent'] ?? false),
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function createWechatMiniVirtualPaymentAction(object $order, string $loginCode): array
    {
        return DB::transaction(function () use ($order, $loginCode): array {
            $freshOrder = DB::table('orders')
                ->where('id', (string) ($order->id ?? ''))
                ->where('org_id', (int) ($order->org_id ?? 0))
                ->where('order_no', (string) ($order->order_no ?? ''))
                ->lockForUpdate()
                ->first();
            if ($freshOrder === null) {
                return $this->error('ORDER_NOT_FOUND', 'order not found.', 404);
            }

            $gift = $this->findBoundGiftForOrder($freshOrder, true);
            if ($gift === null) {
                return $this->error('GIFT_REQUEST_NOT_PAYABLE', 'gift request is not payable.', 409);
            }
            $identityGuard = $this->validateGiftOrderIdentity($gift, $freshOrder);
            if (($identityGuard['ok'] ?? false) !== true) {
                return $identityGuard;
            }
            if ($this->effectiveStatus($gift) !== self::STATUS_PURCHASING
                || ! in_array(Order::normalizePaymentState(
                    $freshOrder->payment_state ?? null,
                    $freshOrder->status ?? null
                ), [Order::PAYMENT_STATE_CREATED, Order::PAYMENT_STATE_PENDING], true)) {
                return $this->error('GIFT_REQUEST_NOT_PAYABLE', 'gift request is not payable.', 409);
            }

            return $this->wechatMiniVirtual->createPaymentAction($freshOrder, $loginCode);
        }, 3);
    }

    public function findBoundGiftForOrder(object $order, bool $lockForUpdate = false): ?object
    {
        $orderId = trim((string) ($order->id ?? ''));
        if ($orderId === '') {
            return null;
        }

        $query = DB::table('report_gift_requests')->where('purchased_order_id', $orderId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return array{ok:bool,error?:string,message?:string}
     */
    public function restoreTerminalGiftForVerifiedPayment(
        object $gift,
        object $order,
        bool $verifiedPaidEvent = false
    ): array {
        $identityGuard = $this->validateGiftOrderIdentity($gift, $order);
        if (($identityGuard['ok'] ?? false) !== true) {
            return $identityGuard;
        }

        $orderIsPaid = in_array(
            strtolower(trim((string) ($order->payment_state ?? $order->status ?? ''))),
            ['paid', 'fulfilled'],
            true
        );
        if (! $verifiedPaidEvent && ! $orderIsPaid) {
            return $this->error(
                'GIFT_PAYMENT_NOT_VERIFIED',
                'gift request can only be restored for a verified paid order.',
                409
            );
        }

        $status = $this->effectiveStatus($gift);
        if (! in_array($status, [self::STATUS_CANCELED, self::STATUS_EXPIRED], true)) {
            return ['ok' => true];
        }

        $competitors = DB::table('report_gift_requests')
            ->where('org_id', (int) $gift->org_id)
            ->where('target_attempt_id', (string) $gift->target_attempt_id)
            ->where('id', '<>', (string) $gift->id)
            ->lockForUpdate()
            ->get();
        foreach ($competitors as $competitor) {
            $competitorStatus = $this->effectiveStatus($competitor);
            if ($competitorStatus === self::STATUS_FULFILLED) {
                return $this->error(
                    'GIFT_COMPETING_ORDER_FULFILLED',
                    'another gift payment already fulfilled this report.',
                    409
                );
            }
            if (in_array($competitorStatus, [self::STATUS_PENDING, self::STATUS_PURCHASING], true)) {
                if ($competitorStatus === self::STATUS_PURCHASING) {
                    $closed = $this->closeCompetingPurchasingOrder($competitor);
                    if (($closed['ok'] ?? false) !== true) {
                        return $closed;
                    }
                }
                DB::table('report_gift_requests')
                    ->where('id', (string) $competitor->id)
                    ->whereIn('status', [self::STATUS_PENDING, self::STATUS_PURCHASING])
                    ->update([
                        'status' => self::STATUS_CANCELED,
                        'canceled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
        }

        $restored = DB::table('report_gift_requests')
            ->where('id', (string) $gift->id)
            ->whereIn('status', [self::STATUS_CANCELED, self::STATUS_EXPIRED])
            ->update([
                'status' => self::STATUS_PURCHASING,
                'canceled_at' => null,
                'updated_at' => now(),
            ]);

        return $restored === 1
            ? ['ok' => true]
            : $this->error('GIFT_REQUEST_STATUS_CHANGED', 'gift request status changed.', 409);
    }

    /**
     * @return array{ok:bool,error?:string,message?:string}
     */
    private function closeCompetingPurchasingOrder(object $gift): array
    {
        $orderId = trim((string) ($gift->purchased_order_id ?? ''));
        if ($orderId === '') {
            return $this->error(
                'GIFT_COMPETING_ORDER_NOT_FOUND',
                'competing gift order not found.',
                409
            );
        }

        $order = DB::table('orders')
            ->where('id', $orderId)
            ->where('org_id', (int) ($gift->org_id ?? 0))
            ->lockForUpdate()
            ->first();
        if ($order === null) {
            return $this->error(
                'GIFT_COMPETING_ORDER_NOT_FOUND',
                'competing gift order not found.',
                409
            );
        }

        $paymentState = Order::normalizePaymentState(
            $order->payment_state ?? null,
            $order->status ?? null
        );
        if (in_array($paymentState, [Order::PAYMENT_STATE_PAID, Order::PAYMENT_STATE_REFUNDED], true)) {
            return $this->error(
                'GIFT_COMPETING_ORDER_SETTLED',
                'competing gift order is already settled.',
                409
            );
        }

        $transition = $this->orders->transition(
            (string) $order->order_no,
            Order::STATUS_CANCELED,
            (int) $order->org_id,
            [
                'payment_state' => Order::PAYMENT_STATE_CANCELED,
                'closed_at' => now(),
            ]
        );
        if (($transition['ok'] ?? false) !== true) {
            return $this->error(
                (string) ($transition['error'] ?? 'GIFT_COMPETING_ORDER_CLOSE_FAILED'),
                (string) ($transition['message'] ?? 'competing gift order could not be closed.'),
                409
            );
        }

        $attempt = $this->orders->latestPaymentAttemptForOrder(
            (string) $order->order_no,
            (int) $order->org_id
        );
        if ($attempt !== null && ! PaymentAttempt::isFinalState($attempt->state ?? null)) {
            $this->orders->advancePaymentAttempt((string) $attempt->id, [
                'state' => PaymentAttempt::STATE_CANCELED,
                'verified_at' => now(),
            ]);
        }

        return ['ok' => true];
    }

    /**
     * @return array{ok:bool,error?:string,message?:string}
     */
    public function validateBoundGiftOrder(object $gift, object $order): array
    {
        $identityGuard = $this->validateGiftOrderIdentity($gift, $order);
        if (($identityGuard['ok'] ?? false) !== true) {
            return $identityGuard;
        }
        if (! in_array($this->effectiveStatus($gift), [self::STATUS_PURCHASING, self::STATUS_FULFILLED], true)) {
            return [
                'ok' => false,
                'error' => 'GIFT_REQUEST_NOT_PAYABLE',
                'message' => 'gift request is not payable.',
            ];
        }

        return ['ok' => true];
    }

    /**
     * @param  list<string>  $modules
     * @return array<string,mixed>
     */
    public function grantVerifiedPaidGift(
        object $gift,
        object $order,
        string $benefitCode,
        string $scope,
        ?string $expiresAt,
        array $modules
    ): array {
        $guard = $this->validateBoundGiftOrder($gift, $order);
        if (($guard['ok'] ?? false) !== true) {
            return $guard;
        }

        $existingGrant = DB::table('benefit_grants')
            ->where('org_id', (int) $gift->org_id)
            ->where('benefit_code', strtoupper(trim($benefitCode)))
            ->where('scope', $scope)
            ->where('attempt_id', (string) $gift->target_attempt_id)
            ->lockForUpdate()
            ->first();
        $orderNo = (string) $order->order_no;
        $existingGrantIsUsable = $existingGrant !== null
            && strtolower(trim((string) ($existingGrant->status ?? ''))) === 'active'
            && ($existingGrant->expires_at === null || now()->lessThan($existingGrant->expires_at));
        $existingGrantBelongsToOrder = $existingGrantIsUsable
            && trim((string) ($existingGrant->order_no ?? '')) === $orderNo;

        if ($existingGrant !== null && ! $existingGrantIsUsable) {
            DB::table('benefit_grants')
                ->where('id', (string) $existingGrant->id)
                ->update([
                    'user_id' => $this->normalizeActorId($gift->recipient_user_id ?? null)
                        ?? $this->normalizeActorId($gift->recipient_anon_id ?? null)
                        ?? 'attempt:'.(string) $gift->target_attempt_id,
                    'benefit_ref' => $this->normalizeActorId($gift->recipient_anon_id ?? null)
                        ?? $this->normalizeActorId($gift->recipient_user_id ?? null)
                        ?? 'attempt:'.(string) $gift->target_attempt_id,
                    'order_no' => $orderNo,
                    'source_order_id' => (string) $order->id,
                    'status' => 'active',
                    'expires_at' => $expiresAt,
                    'revoked_at' => null,
                    'updated_at' => now(),
                ]);
            $existingGrantBelongsToOrder = true;
        }

        $grant = $this->entitlements->grantAttemptUnlock(
            (int) $gift->org_id,
            $this->normalizeActorId($gift->recipient_user_id ?? null),
            $this->normalizeActorId($gift->recipient_anon_id ?? null),
            $benefitCode,
            (string) $gift->target_attempt_id,
            $orderNo,
            $scope,
            $expiresAt,
            $modules,
            $existingGrant === null || $existingGrantBelongsToOrder
                ? [
                    'unlock_source' => ReportAccess::UNLOCK_SOURCE_GIFT_PURCHASE,
                    'granted_via' => ReportAccess::UNLOCK_SOURCE_GIFT_PURCHASE,
                    'gift_request_id' => (string) $gift->id,
                ]
                : null
        );
        if (($grant['ok'] ?? false) !== true) {
            return $grant;
        }

        DB::table('report_gift_requests')
            ->where('id', (string) $gift->id)
            ->whereIn('status', [self::STATUS_PURCHASING, self::STATUS_FULFILLED])
            ->update([
                'status' => self::STATUS_FULFILLED,
                'fulfilled_at' => $gift->fulfilled_at ?? now(),
                'updated_at' => now(),
            ]);

        return $grant;
    }

    public function markRefundedForOrder(object $order): void
    {
        $orderId = trim((string) ($order->id ?? ''));
        if ($orderId === '') {
            return;
        }

        DB::table('report_gift_requests')
            ->where('purchased_order_id', $orderId)
            ->whereIn('status', [self::STATUS_PURCHASING, self::STATUS_FULFILLED])
            ->update([
                'status' => self::STATUS_REFUNDED,
                'updated_at' => now(),
            ]);
    }

    public function releaseReservationForOrder(object $order, string $paymentState): void
    {
        $orderId = trim((string) ($order->id ?? ''));
        $orderNo = trim((string) ($order->order_no ?? ''));
        $orgId = (int) ($order->org_id ?? 0);
        if ($orderId === '' || $orderNo === '') {
            return;
        }

        $paymentAttempt = $this->orders->latestPaymentAttemptForOrder($orderNo, $orgId);
        if ($paymentAttempt !== null && ! in_array(
            PaymentAttempt::normalizedState($paymentAttempt->state ?? null),
            [
                PaymentAttempt::STATE_FAILED,
                PaymentAttempt::STATE_CANCELED,
                PaymentAttempt::STATE_EXPIRED,
            ],
            true
        )) {
            return;
        }

        $status = strtolower(trim($paymentState)) === 'expired'
            ? self::STATUS_EXPIRED
            : self::STATUS_CANCELED;
        $updates = [
            'status' => $status,
            'updated_at' => now(),
        ];
        if ($status === self::STATUS_CANCELED) {
            $updates['canceled_at'] = now();
        }

        DB::table('report_gift_requests')
            ->where('purchased_order_id', $orderId)
            ->where('status', self::STATUS_PURCHASING)
            ->update($updates);
    }

    public function releaseUnpayableReservationForOrder(object $order): void
    {
        $orderNo = trim((string) ($order->order_no ?? ''));
        $orgId = (int) ($order->org_id ?? 0);
        if ($orderNo === '' || $this->orders->latestPaymentAttemptForOrder($orderNo, $orgId) !== null) {
            return;
        }

        DB::transaction(function () use ($orderNo, $orgId): void {
            $freshOrder = DB::table('orders')
                ->where('order_no', $orderNo)
                ->where('org_id', $orgId)
                ->lockForUpdate()
                ->first();
            if ($freshOrder === null || $this->orders->latestPaymentAttemptForOrder($orderNo, $orgId) !== null) {
                return;
            }

            $paymentState = strtolower(trim((string) ($freshOrder->payment_state ?? '')));
            if (in_array($paymentState, ['paid', 'refunded'], true)) {
                return;
            }

            $transition = $this->orders->transition($orderNo, 'canceled', $orgId, [
                'payment_state' => 'canceled',
                'closed_at' => now(),
            ]);
            if (($transition['ok'] ?? false) !== true) {
                return;
            }

            DB::table('report_gift_requests')
                ->where('purchased_order_id', (string) $freshOrder->id)
                ->where('status', self::STATUS_PURCHASING)
                ->update([
                    'purchased_order_id' => null,
                    'purchased_by_user_id' => null,
                    'purchased_by_anon_id' => null,
                    'status' => self::STATUS_PENDING,
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function presentOwnedOrderPayload(int $orgId, string $orderNo, array $payload): array
    {
        $giftOrderExists = DB::table('report_gift_requests as gifts')
            ->join('orders', 'orders.id', '=', 'gifts.purchased_order_id')
            ->where('orders.org_id', $orgId)
            ->where('orders.order_no', trim($orderNo))
            ->exists();
        if (! $giftOrderExists) {
            return $payload;
        }

        $payload['attempt_id'] = null;
        $payload['result_url'] = null;
        $payload['delivery'] = [
            'can_view_report' => false,
            'report_url' => null,
            'can_download_pdf' => false,
            'report_pdf_url' => null,
            'can_resend' => false,
            'contact_email_present' => false,
            'last_delivery_email_sent_at' => null,
            'can_request_claim_email' => false,
        ];
        $payload['exact_result_entry'] = null;
        $payload['mbti_form_v1'] = null;
        $payload['big5_form_v1'] = null;
        unset($payload['order'], $payload[ReportAccess::ACCESS_HUB_KEY]);

        return $payload;
    }

    /**
     * @return array{ok:bool,error?:string,message?:string}
     */
    private function validateGiftOrderIdentity(object $gift, object $order): array
    {
        if ((int) ($gift->org_id ?? -1) !== (int) ($order->org_id ?? -2)
            || trim((string) ($gift->target_attempt_id ?? '')) !== trim((string) ($order->target_attempt_id ?? ''))
            || strtoupper(trim((string) ($gift->sku ?? ''))) !== strtoupper(trim((string) ($order->sku ?? '')))
            || ! $this->actorMatchesPurchaser($gift, $order->user_id ?? null, $order->anon_id ?? null)) {
            return [
                'ok' => false,
                'error' => 'GIFT_ORDER_MISMATCH',
                'message' => 'gift request does not match the paid order.',
            ];
        }

        return ['ok' => true];
    }

    private function findByToken(string $token, int $orgId): ?object
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', trim($token)) !== 1) {
            return null;
        }

        return DB::table('report_gift_requests')
            ->where('public_token_hash', $this->tokenHash($token))
            ->where('org_id', $orgId)
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function publicSummary(object $gift): array
    {
        $skuRow = $this->skus->getActiveSku((string) $gift->sku, null, (int) $gift->org_id);
        $priceCents = (int) ($skuRow->price_cents ?? 0);
        $currency = strtoupper(trim((string) ($skuRow->currency ?? 'CNY')));
        $status = $this->effectiveStatus($gift);
        $skuValid = $this->skuContractIsValid($skuRow);

        return [
            'status' => $status,
            'scale_code' => (string) $gift->scale_code,
            'sku' => (string) $gift->sku,
            'expires_at' => (string) $gift->expires_at,
            'price_cents' => $priceCents,
            'currency' => $currency,
            'display_price' => $currency === 'CNY' ? '¥'.number_format($priceCents / 100, 2, '.', '') : null,
            'can_purchase' => $status === self::STATUS_PENDING
                && $this->giftAvailable()
                && $this->paymentProviders->isEnabled(self::PROVIDER)
                && $skuValid,
        ];
    }

    /**
     * @return array{ok:bool,error_code?:string,message?:string,status?:int}
     */
    private function validateSku(int $orgId, string $sku): array
    {
        $skuRow = $this->skus->getActiveSku($sku, null, $orgId);
        if (! $skuRow) {
            return $this->error('SKU_NOT_FOUND', 'gift SKU is unavailable.', 404);
        }
        if (! $this->skuContractIsValid($skuRow)) {
            return $this->error('GIFT_SKU_INVALID', 'gift SKU contract is invalid.', 409);
        }

        return ['ok' => true];
    }

    private function skuContractIsValid(?object $skuRow): bool
    {
        $contract = $skuRow !== null ? $this->products->forSku((string) ($skuRow->sku ?? '')) : null;

        return $skuRow !== null
            && $contract !== null
            && (int) ($skuRow->price_cents ?? 0) === (int) $contract['price_cents']
            && strtoupper(trim((string) ($skuRow->currency ?? ''))) === 'CNY'
            && strtolower(trim((string) ($skuRow->kind ?? ''))) === 'report_unlock'
            && strtoupper(trim((string) ($skuRow->benefit_code ?? ''))) === $contract['benefit_code'];
    }

    private function actorOwnsAttempt(object $attempt, ?string $userId, ?string $anonId): bool
    {
        $actorUserId = $this->normalizeActorId($userId);
        $actorAnonId = $this->normalizeActorId($anonId);
        $attemptUserId = $this->normalizeActorId($attempt->user_id ?? null);
        $attemptAnonId = $this->normalizeActorId($attempt->anon_id ?? null);

        return ($actorUserId !== null && $actorUserId === $attemptUserId)
            || ($actorAnonId !== null && $actorAnonId === $attemptAnonId);
    }

    private function actorMatchesRecipient(object $gift, ?string $userId, ?string $anonId): bool
    {
        return ($this->normalizeActorId($userId) !== null
                && $this->normalizeActorId($userId) === $this->normalizeActorId($gift->recipient_user_id ?? null))
            || ($this->normalizeActorId($anonId) !== null
                && $this->normalizeActorId($anonId) === $this->normalizeActorId($gift->recipient_anon_id ?? null));
    }

    private function actorMatchesPurchaser(object $gift, mixed $userId, mixed $anonId): bool
    {
        $expectedUserId = $this->normalizeActorId($gift->purchased_by_user_id ?? null);
        $expectedAnonId = $this->normalizeActorId($gift->purchased_by_anon_id ?? null);
        $actualUserId = $this->normalizeActorId($userId);
        $actualAnonId = $this->normalizeActorId($anonId);

        return ($expectedUserId !== null && $expectedUserId === $actualUserId)
            || ($expectedAnonId !== null && $expectedAnonId === $actualAnonId);
    }

    private function effectiveStatus(object $gift): string
    {
        $status = strtolower(trim((string) ($gift->status ?? '')));
        if ($status === self::STATUS_PENDING && now()->greaterThanOrEqualTo($gift->expires_at)) {
            return self::STATUS_EXPIRED;
        }

        return in_array($status, [
            self::STATUS_PENDING,
            self::STATUS_PURCHASING,
            self::STATUS_FULFILLED,
            self::STATUS_CANCELED,
            self::STATUS_EXPIRED,
            self::STATUS_REFUNDED,
        ], true) ? $status : self::STATUS_CANCELED;
    }

    private function skuForScale(string $scaleCode): string
    {
        return (string) data_get($this->products->forScale($scaleCode), 'sku', '');
    }

    private function giftAvailable(): bool
    {
        return (bool) config('report_unlock.providers.gift_purchase.available', false);
    }

    private function ttlHours(): int
    {
        return min(168, max(1, (int) config('report_unlock.gift_request_ttl_hours', 72)));
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    private function giftIdempotencyKey(
        string $giftRequestId,
        string $key,
        ?string $payerUserId,
        ?string $payerAnonId,
        string $reservationGeneration
    ): string {
        $payerIdentity = $payerUserId !== null
            ? 'user:'.$payerUserId
            : 'anon:'.(string) $payerAnonId;

        return 'gift:'.$giftRequestId.':'.hash(
            'sha256',
            $payerIdentity."\0".$key."\0".$reservationGeneration
        );
    }

    private function giftOrderReservationGeneration(
        object $gift,
        ?string $payerUserId,
        ?string $payerAnonId
    ): string {
        return (string) DB::table('orders')
            ->where('org_id', (int) ($gift->org_id ?? 0))
            ->where('target_attempt_id', (string) ($gift->target_attempt_id ?? ''))
            ->where('sku', (string) ($gift->sku ?? ''))
            ->where('provider', self::PROVIDER)
            ->where('user_id', $this->normalizeActorId($payerUserId))
            ->where('anon_id', $this->normalizeActorId($payerAnonId))
            ->count();
    }

    private function normalizeActorId(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return array{ok:false,error_code:string,message:string,status:int}
     */
    private function error(string $code, string $message, int $status): array
    {
        return [
            'ok' => false,
            'error_code' => $code,
            'message' => $message,
            'status' => $status,
        ];
    }
}
