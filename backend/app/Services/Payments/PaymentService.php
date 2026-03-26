<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Services\Commerce\SkuPriceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public const BENEFIT_TYPE = 'report_unlock';

    public const BENEFIT_REF = 'mbti_report_v1';

    private const PAYMENT_PROVIDER_INTERNAL = 'internal';

    private const PAYMENT_PROVIDER_MOCK = 'mock';

    private const MAX_ORDER_QUANTITY = 1000;

    private const MAX_INT32 = 2147483647;

    public function __construct(
        private PaymentRouter $router,
        private SkuPriceService $skuPriceService,
    ) {}

    public function createOrder(array $data, array $actor = []): array
    {
        $itemSku = strtoupper(trim((string) ($data['item_sku'] ?? '')));
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'CNY')));
        $userId = $this->trimOrNull($data['user_id'] ?? ($actor['user_id'] ?? null));
        $anonId = $this->trimOrNull($data['anon_id'] ?? ($actor['anon_id'] ?? null));
        $channel = Order::normalizeChannel($this->trimOrNull($data['channel'] ?? null)) ?? 'web';
        $providerApp = $this->trimOrNull($data['provider_app'] ?? null);
        $qtyRaw = $data['quantity'] ?? 1;
        $qty = is_numeric($qtyRaw) ? (int) $qtyRaw : 1;
        if ($qty < 1 || $qty > self::MAX_ORDER_QUANTITY) {
            return $this->invalid('QUANTITY_INVALID', 'quantity out of range.');
        }
        $legacyOrgId = (int) config('fap.legacy_org_id', 1);
        if ($legacyOrgId <= 0) {
            $legacyOrgId = 1;
        }
        $unitPriceCents = $this->skuPriceService->getPrice($itemSku, $currency);
        if ($unitPriceCents < 0) {
            return $this->invalid('PRICE_INVALID', 'price invalid.');
        }
        if ($unitPriceCents > 0 && $qty > intdiv(self::MAX_INT32, $unitPriceCents)) {
            return $this->invalid('AMOUNT_TOO_LARGE', 'amount too large.');
        }
        $amountCents = $unitPriceCents * $qty;

        $orderId = (string) Str::uuid();
        $orderNo = 'ord_'.Str::uuid();
        $now = now();

        $row = [
            'id' => $orderId,
            'order_no' => $orderNo,
            'user_id' => $userId,
            'anon_id' => $anonId,
            'device_id' => $this->trimOrNull($data['device_id'] ?? null),
            'provider' => $this->trimOrNull($data['provider'] ?? null) ?: 'internal',
            'channel' => $channel,
            'provider_app' => $providerApp,
            'provider_order_id' => $this->trimOrNull($data['provider_order_id'] ?? null),
            'org_id' => $legacyOrgId,
            'status' => Order::STATUS_PENDING,
            'payment_state' => Order::PAYMENT_STATE_PENDING,
            'grant_state' => Order::GRANT_STATE_NOT_STARTED,
            'currency' => $currency,
            'amount_total' => $amountCents,
            'amount_cents' => $amountCents,
            'amount_refunded' => 0,
            'item_sku' => $itemSku,
            'sku' => $itemSku,
            'quantity' => $qty,
            'request_id' => $this->trimOrNull($data['request_id'] ?? null),
            'created_ip' => $this->trimOrNull($data['ip'] ?? null),
            'external_user_ref' => $this->resolveExternalUserRef($userId, $anonId),
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('orders')->insert($row);
        $order = DB::table('orders')->where('id', $orderId)->first();
        if ($order) {
            $this->ensureLegacyPaymentAttempt($order);
        }

        return [
            'ok' => true,
            'order' => $order ?: (object) $row,
        ];
    }

    public function markPaid(string $orderId, string $userId, ?string $anonId, array $context = []): array
    {
        $order = $this->ownedOrderQuery($orderId, $userId, $anonId)->first();
        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        if (in_array($order->status, ['paid', 'fulfilled'], true)) {
            $this->syncLedgerStateForLegacyOrder($orderId, (string) ($order->status ?? ''));
            $order = DB::table('orders')->where('id', $orderId)->first() ?: $order;

            return [
                'ok' => true,
                'order' => $order,
            ];
        }

        if ($order->status !== 'pending') {
            return $this->conflict('ORDER_NOT_PENDING', 'order status not pending.');
        }

        $now = now();

        $provider = self::PAYMENT_PROVIDER_INTERNAL;
        $providerEventId = 'dev_mark_paid:'.$orderId;
        $paymentAttempt = $this->ensureLegacyPaymentAttempt($order);
        $existing = $this->paymentEventQuery($provider, $providerEventId)->first();

        if (! $existing) {
            $payload = [
                'mode' => 'dev',
                'event' => 'mark_paid',
                'order_id' => $orderId,
            ];

            DB::table('payment_events')->insert([
                'id' => (string) Str::uuid(),
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'order_id' => $orderId,
                'payment_attempt_id' => is_object($paymentAttempt) ? ($paymentAttempt->id ?? null) : null,
                'order_no' => $order->order_no ?? null,
                'event_type' => 'mark_paid',
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'signature_ok' => true,
                'handled_at' => $now,
                'handle_status' => 'ok',
                'request_id' => $this->trimOrNull($context['request_id'] ?? null),
                'ip' => $this->trimOrNull($context['ip'] ?? null),
                'headers_digest' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('orders')
            ->where('id', $orderId)
            ->update([
                'status' => Order::STATUS_PAID,
                'payment_state' => Order::PAYMENT_STATE_PAID,
                'paid_at' => $now,
                'updated_at' => $now,
            ]);
        if ($paymentAttempt) {
            $this->advanceLegacyPaymentAttempt((string) $paymentAttempt->id, [
                'state' => PaymentAttempt::STATE_PAID,
                'latest_payment_event_id' => $existing->id ?? $this->trimOrNull($this->paymentEventQuery($provider, $providerEventId)->value('id')),
                'verified_at' => $now,
                'callback_received_at' => $now,
                'finalized_at' => $now,
            ]);
        }

        $order = DB::table('orders')->where('id', $orderId)->first();

        return [
            'ok' => true,
            'order' => $order,
        ];
    }

    public function fulfill(string $orderId, string $userId, ?string $anonId): array
    {
        $order = $this->ownedOrderQuery($orderId, $userId, $anonId)->first();
        if (! $order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        if ($order->status === 'fulfilled') {
            $this->syncLedgerStateForLegacyOrder($orderId, (string) ($order->status ?? ''));
            $order = DB::table('orders')->where('id', $orderId)->first() ?: $order;
        }

        if (! in_array($order->status, ['paid', 'fulfilled'], true)) {
            return $this->conflict('ORDER_NOT_PAID', 'order status not paid.');
        }

        $targetUserId = $this->trimOrNull($userId) ?: $this->trimOrNull($order->user_id ?? null);
        if (! $targetUserId) {
            $targetUserId = $this->trimOrNull($anonId) ?: $this->trimOrNull($order->anon_id ?? null);
        }

        if (! $targetUserId) {
            return [
                'ok' => false,
                'status' => 422,
                'error_code' => 'MISSING_USER',
                'message' => 'missing user id for benefit grant.',
            ];
        }

        $now = now();
        $benefitRow = DB::table('benefit_grants')
            ->where('source_order_id', $orderId)
            ->where('benefit_type', self::BENEFIT_TYPE)
            ->where('benefit_ref', self::BENEFIT_REF)
            ->first();

        if (! $benefitRow) {
            $benefitRow = [
                'id' => (string) Str::uuid(),
                'user_id' => $targetUserId,
                'benefit_type' => self::BENEFIT_TYPE,
                'benefit_ref' => self::BENEFIT_REF,
                'source_order_id' => $orderId,
                'source_event_id' => null,
                'status' => 'active',
                'expires_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            DB::table('benefit_grants')->insert($benefitRow);
        }

        if ($order->status !== 'fulfilled') {
            DB::table('orders')
                ->where('id', $orderId)
                ->update([
                    'status' => Order::STATUS_FULFILLED,
                    'payment_state' => Order::PAYMENT_STATE_PAID,
                    'grant_state' => Order::GRANT_STATE_GRANTED,
                    'fulfilled_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $order = DB::table('orders')->where('id', $orderId)->first();

        return [
            'ok' => true,
            'order' => $order,
            'benefit' => $this->presentBenefit($benefitRow),
        ];
    }

    public function listBenefits(string $userId): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'error_code' => 'INVALID_USER',
                'message' => 'user id missing.',
            ];
        }

        $q = DB::table('benefit_grants')->where('user_id', $userId);
        $q->where('status', 'active');
        $q->where(function ($sub) {
            $sub->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });

        $rows = $q->orderByDesc('created_at')->limit(100)->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => $row->id ?? null,
                'benefit_type' => $row->benefit_type ?? null,
                'benefit_ref' => $row->benefit_ref ?? null,
                'status' => $row->status ?? null,
                'expires_at' => $row->expires_at ?? null,
                'source_order_id' => $row->source_order_id ?? null,
            ];
        }

        return [
            'ok' => true,
            'items' => $items,
        ];
    }

    public function handleWebhookMock(array $payload, array $context = []): array
    {
        $provider = self::PAYMENT_PROVIDER_MOCK;
        $providerEventId = $this->trimOrNull($payload['provider_event_id'] ?? null);
        if (! $providerEventId) {
            return $this->invalid('MISSING_EVENT_ID', 'provider_event_id missing.');
        }

        $providerOrderId = $this->trimOrNull($payload['provider_order_id'] ?? null);
        if (! $providerOrderId) {
            return $this->invalid('MISSING_ORDER_ID', 'provider_order_id missing.');
        }

        $eventType = $this->trimOrNull($payload['event_type'] ?? null);
        if (! $eventType) {
            return $this->invalid('MISSING_EVENT_TYPE', 'event_type missing.');
        }

        $signatureOk = (bool) ($context['signature_ok'] ?? false);

        return DB::transaction(function () use (
            $provider,
            $providerEventId,
            $providerOrderId,
            $eventType,
            $signatureOk,
            $payload,
            $context
        ) {
            $order = DB::table('orders')
                ->where('provider_order_id', $providerOrderId)
                ->lockForUpdate()
                ->first();

            $now = now();
            if (! $order) {
                return [
                    'ok' => false,
                    'status' => 404,
                    'error_code' => 'ORDER_NOT_FOUND',
                    'message' => 'order not found.',
                ];
            }

            $orderId = $order->id;
            $handleStatus = 'ok';

            $eventRow = [
                'id' => (string) Str::uuid(),
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'order_id' => $orderId,
                'payment_attempt_id' => $this->trimOrNull((string) (is_object($legacyAttempt = $this->ensureLegacyPaymentAttempt($order)) ? ($legacyAttempt->id ?? '') : '')),
                'order_no' => $order->order_no ?? null,
                'event_type' => $eventType,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'signature_ok' => $signatureOk,
                'handled_at' => null,
                'handle_status' => null,
                'request_id' => $this->trimOrNull($context['request_id'] ?? null),
                'ip' => $this->trimOrNull($context['ip'] ?? null),
                'headers_digest' => $this->trimOrNull($context['headers_digest'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $inserted = DB::table('payment_events')->insertOrIgnore($eventRow);
            if ((int) $inserted === 0) {
                $this->paymentEventQuery($provider, $providerEventId)->update([
                    'handled_at' => $now,
                    'handle_status' => 'already_processed',
                    'updated_at' => $now,
                ]);

                return [
                    'ok' => true,
                    'idempotent' => true,
                    'signature_ok' => $signatureOk,
                    'provider_event_id' => $providerEventId,
                    'handle_status' => 'already_processed',
                ];
            }

            if (! $signatureOk) {
                $this->paymentEventQuery($provider, $providerEventId)->update([
                    'handled_at' => $now,
                    'handle_status' => 'signature_invalid',
                    'updated_at' => $now,
                ]);

                return [
                    'ok' => true,
                    'idempotent' => false,
                    'signature_ok' => false,
                    'handle_status' => 'signature_invalid',
                ];
            }

            if ($eventType === 'payment_succeeded') {
                if ($order->status === 'pending') {
                    DB::table('orders')
                        ->where('id', $orderId)
                        ->update([
                            'status' => 'paid',
                            'payment_state' => Order::PAYMENT_STATE_PAID,
                            'paid_at' => $now,
                            'updated_at' => $now,
                        ]);
                }
                if (! empty($eventRow['payment_attempt_id'])) {
                    $this->advanceLegacyPaymentAttempt((string) $eventRow['payment_attempt_id'], [
                        'state' => PaymentAttempt::STATE_PAID,
                        'latest_payment_event_id' => (string) $eventRow['id'],
                        'callback_received_at' => $now,
                        'verified_at' => $now,
                        'finalized_at' => $now,
                    ]);
                }

                if (in_array($order->status, ['pending', 'paid', 'fulfilled'], true)) {
                    $actorUserId = $this->trimOrNull($order->user_id ?? null);
                    $actorAnonId = $this->trimOrNull($order->anon_id ?? null);
                    $actorId = $actorUserId ?? $actorAnonId ?? '';

                    $fulfillResult = $this->fulfill($orderId, $actorId, $actorAnonId);
                    if (! $fulfillResult['ok']) {
                        $handleStatus = 'fulfill_failed';
                    }
                } else {
                    $handleStatus = 'payment_ignored';
                }
            } elseif ($eventType === 'refund_succeeded') {
                if (in_array($order->status, ['paid', 'fulfilled'], true)) {
                    DB::table('orders')
                        ->where('id', $orderId)
                        ->update([
                            'status' => 'refunded',
                            'payment_state' => Order::PAYMENT_STATE_REFUNDED,
                            'refunded_at' => $now,
                            'updated_at' => $now,
                        ]);
                    $handleStatus = 'refunded';
                } elseif ($order->status === 'refunded') {
                    $handleStatus = 'already_refunded';
                } else {
                    $handleStatus = 'refund_ignored';
                }
            } else {
                $handleStatus = 'event_type_ignored';
            }

            $this->paymentEventQuery($provider, $providerEventId)->update([
                'handled_at' => $now,
                'handle_status' => $handleStatus,
                'updated_at' => $now,
            ]);

            $order = DB::table('orders')->where('id', $orderId)->first();

            return [
                'ok' => true,
                'idempotent' => false,
                'signature_ok' => $signatureOk,
                'handle_status' => $handleStatus,
                'order_id' => $orderId,
                'order_status' => $order->status ?? null,
            ];
        });
    }

    private function paymentEventQuery(string $provider, string $providerEventId)
    {
        return DB::table('payment_events')
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId);
    }

    private function ownedOrderQuery(string $orderId, ?string $userId, ?string $anonId)
    {
        $userId = $this->trimOrNull($userId);
        $anonId = $this->trimOrNull($anonId);

        $query = DB::table('orders')->where('id', $orderId);
        if (! $userId && ! $anonId) {
            return $query->whereRaw('1=0');
        }

        $query->where(function ($q) use ($userId, $anonId): void {
            $applied = false;

            if ($userId) {
                $q->where('user_id', $userId);
                $applied = true;
            }

            if ($anonId) {
                if ($applied) {
                    $q->orWhere('anon_id', $anonId);
                } else {
                    $q->where('anon_id', $anonId);
                    $applied = true;
                }
            }

            if (! $applied) {
                $q->whereRaw('1=0');
            }
        });

        return $query;
    }

    private function tableMissing(string $table): array
    {
        return [
            'ok' => false,
            'status' => 500,
            'error_code' => 'TABLE_MISSING',
            'message' => "{$table} table missing.",
        ];
    }

    private function notFound(string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'status' => 404,
            'error_code' => $errorCode,
            'message' => $message,
        ];
    }

    private function conflict(string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'status' => 409,
            'error_code' => $errorCode,
            'message' => $message,
        ];
    }

    private function invalid(string $errorCode, string $message): array
    {
        return [
            'ok' => false,
            'status' => 422,
            'error_code' => $errorCode,
            'message' => $message,
        ];
    }

    private function trimOrNull($value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $v = trim($value);

        return $v !== '' ? $v : null;
    }

    private function resolveExternalUserRef(?string $userId, ?string $anonId): ?string
    {
        if ($userId !== null) {
            return substr('user:'.$userId, 0, 128);
        }

        if ($anonId !== null) {
            return substr('anon:'.$anonId, 0, 128);
        }

        return null;
    }

    private function syncLedgerStateForLegacyOrder(string $orderId, string $status): void
    {
        $normalizedStatus = strtolower(trim($status));
        $updates = [];

        if (in_array($normalizedStatus, [Order::STATUS_PAID, Order::STATUS_FULFILLED], true)) {
            $updates['payment_state'] = Order::PAYMENT_STATE_PAID;
        }

        if ($normalizedStatus === Order::STATUS_FULFILLED) {
            $updates['grant_state'] = Order::GRANT_STATE_GRANTED;
        }

        if ($updates === []) {
            return;
        }

        $updates['updated_at'] = now();

        DB::table('orders')
            ->where('id', $orderId)
            ->update($updates);
    }

    private function ensureLegacyPaymentAttempt(object $order): ?object
    {
        if (! DB::getSchemaBuilder()->hasTable('payment_attempts')) {
            return null;
        }

        $existing = $this->latestPaymentAttemptForOrder((string) ($order->id ?? ''));
        if ($existing) {
            return $existing;
        }

        $now = now();
        $row = [
            'id' => (string) Str::uuid(),
            'org_id' => (int) ($order->org_id ?? 0),
            'order_id' => (string) ($order->id ?? ''),
            'order_no' => (string) ($order->order_no ?? ''),
            'attempt_no' => 1,
            'provider' => strtolower(trim((string) ($order->provider ?? self::PAYMENT_PROVIDER_INTERNAL))),
            'channel' => Order::normalizeChannel((string) ($order->channel ?? '')) ?? 'web',
            'provider_app' => $this->trimOrNull($order->provider_app ?? null),
            'pay_scene' => null,
            'state' => PaymentAttempt::STATE_INITIATED,
            'external_trade_no' => $this->trimOrNull($order->external_trade_no ?? null),
            'provider_trade_no' => $this->trimOrNull($order->provider_trade_no ?? null),
            'provider_session_ref' => null,
            'amount_expected' => (int) ($order->amount_cents ?? $order->amount_total ?? 0),
            'currency' => strtoupper(trim((string) ($order->currency ?? 'USD'))),
            'payload_meta_json' => json_encode(['source' => 'legacy_payment_service'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'latest_payment_event_id' => null,
            'initiated_at' => $now,
            'provider_created_at' => null,
            'client_presented_at' => null,
            'callback_received_at' => null,
            'verified_at' => null,
            'finalized_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'meta_json' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('payment_attempts')->insert($row);

        return DB::table('payment_attempts')->where('id', $row['id'])->first();
    }

    private function latestPaymentAttemptForOrder(string $orderId): ?object
    {
        if ($orderId === '' || ! DB::getSchemaBuilder()->hasTable('payment_attempts')) {
            return null;
        }

        return DB::table('payment_attempts')
            ->where('order_id', $orderId)
            ->orderByDesc('attempt_no')
            ->first();
    }

    private function advanceLegacyPaymentAttempt(string $attemptId, array $updates): void
    {
        if ($attemptId === '' || ! DB::getSchemaBuilder()->hasTable('payment_attempts')) {
            return;
        }

        $current = DB::table('payment_attempts')->where('id', $attemptId)->first();
        if (! $current) {
            return;
        }

        $state = isset($updates['state']) ? PaymentAttempt::normalizedState((string) $updates['state']) : null;
        if ($state !== null) {
            $updates['state'] = $this->resolveLegacyAttemptState(
                (string) ($current->state ?? ''),
                $state
            );
        }

        foreach (['callback_received_at', 'verified_at', 'finalized_at'] as $field) {
            if (isset($updates[$field]) && ! is_string($updates[$field])) {
                $updates[$field] = (string) $updates[$field];
            }
        }

        $updates['updated_at'] = now();

        DB::table('payment_attempts')
            ->where('id', $attemptId)
            ->update($updates);
    }

    private function resolveLegacyAttemptState(string $currentState, string $requestedState): string
    {
        $rank = [
            PaymentAttempt::STATE_INITIATED => 10,
            PaymentAttempt::STATE_PROVIDER_CREATED => 20,
            PaymentAttempt::STATE_CLIENT_PRESENTED => 30,
            PaymentAttempt::STATE_CALLBACK_RECEIVED => 40,
            PaymentAttempt::STATE_VERIFIED => 50,
            PaymentAttempt::STATE_PAID => 60,
            PaymentAttempt::STATE_FAILED => 60,
            PaymentAttempt::STATE_CANCELED => 60,
            PaymentAttempt::STATE_EXPIRED => 60,
        ];

        $normalizedCurrent = PaymentAttempt::normalizedState($currentState);
        $normalizedRequested = PaymentAttempt::normalizedState($requestedState);

        return ($rank[$normalizedRequested] ?? 0) >= ($rank[$normalizedCurrent] ?? 0)
            ? $normalizedRequested
            : $normalizedCurrent;
    }

    private function presentBenefit($row): array
    {
        if (is_array($row)) {
            return [
                'id' => $row['id'] ?? null,
                'benefit_type' => $row['benefit_type'] ?? null,
                'benefit_ref' => $row['benefit_ref'] ?? null,
                'status' => $row['status'] ?? null,
                'expires_at' => $row['expires_at'] ?? null,
                'source_order_id' => $row['source_order_id'] ?? null,
            ];
        }

        return [
            'id' => $row->id ?? null,
            'benefit_type' => $row->benefit_type ?? null,
            'benefit_ref' => $row->benefit_ref ?? null,
            'status' => $row->status ?? null,
            'expires_at' => $row->expires_at ?? null,
            'source_order_id' => $row->source_order_id ?? null,
        ];
    }
}
