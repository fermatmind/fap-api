<?php

namespace App\Services\Payments;

use App\Services\Commerce\SkuPriceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public const BENEFIT_TYPE = 'report_unlock';
    public const BENEFIT_REF = 'mbti_report_v1';
    private const PAYMENT_PROVIDER_INTERNAL = 'internal';
    private const PAYMENT_PROVIDER_MOCK = 'mock';

    public function __construct(
        private PaymentRouter $router,
        private SkuPriceService $skuPriceService,
    ) {
    }

    public function createOrder(array $data, array $actor = []): array
    {
        $itemSku = strtoupper(trim((string) ($data['item_sku'] ?? '')));
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'CNY')));
        $qtyRaw = $data['quantity'] ?? 1;
        $qty = is_numeric($qtyRaw) ? max(1, (int) $qtyRaw) : 1;
        $legacyOrgId = (int) config('fap.legacy_org_id', 1);
        if ($legacyOrgId <= 0) {
            $legacyOrgId = 1;
        }
        $unitPriceCents = $this->skuPriceService->getPrice($itemSku, $currency);
        $amountCents = $unitPriceCents * $qty;

        $orderId = (string) Str::uuid();
        $now = now();

        $row = [
            'id' => $orderId,
            'user_id' => $this->trimOrNull($data['user_id'] ?? ($actor['user_id'] ?? null)),
            'anon_id' => $this->trimOrNull($data['anon_id'] ?? ($actor['anon_id'] ?? null)),
            'device_id' => $this->trimOrNull($data['device_id'] ?? null),
            'provider' => $this->trimOrNull($data['provider'] ?? null) ?: 'internal',
            'provider_order_id' => $this->trimOrNull($data['provider_order_id'] ?? null),
            'org_id' => $legacyOrgId,
            'status' => 'pending',
            'currency' => $currency,
            'amount_total' => $amountCents,
            'amount_cents' => $amountCents,
            'amount_refunded' => 0,
            'item_sku' => $itemSku,
            'sku' => $itemSku,
            'quantity' => $qty,
            'request_id' => $this->trimOrNull($data['request_id'] ?? null),
            'created_ip' => $this->trimOrNull($data['ip'] ?? null),
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('orders')->insert($row);
        $order = DB::table('orders')->where('id', $orderId)->first();

        return [
            'ok' => true,
            'order' => $order ?: (object) $row,
        ];
    }

    public function markPaid(string $orderId, string $userId, ?string $anonId, array $context = []): array
    {
        $order = $this->ownedOrderQuery($orderId, $userId, $anonId)->first();
        if (!$order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        if (in_array($order->status, ['paid', 'fulfilled'], true)) {
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
        $providerEventId = 'dev_mark_paid:' . $orderId;
        $existing = $this->paymentEventQuery($provider, $providerEventId)->first();

        if (!$existing) {
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
                'status' => 'paid',
                'paid_at' => $now,
                'updated_at' => $now,
            ]);

        $order = DB::table('orders')->where('id', $orderId)->first();

        return [
            'ok' => true,
            'order' => $order,
        ];
    }

    public function fulfill(string $orderId, string $userId, ?string $anonId): array
    {
        $order = $this->ownedOrderQuery($orderId, $userId, $anonId)->first();
        if (!$order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        if (!in_array($order->status, ['paid', 'fulfilled'], true)) {
            return $this->conflict('ORDER_NOT_PAID', 'order status not paid.');
        }

        $targetUserId = $this->trimOrNull($userId) ?: $this->trimOrNull($order->user_id ?? null);
        if (!$targetUserId) {
            $targetUserId = $this->trimOrNull($anonId) ?: $this->trimOrNull($order->anon_id ?? null);
        }

        if (!$targetUserId) {
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

        if (!$benefitRow) {
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
                    'status' => 'fulfilled',
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
        if (!$providerEventId) {
            return $this->invalid('MISSING_EVENT_ID', 'provider_event_id missing.');
        }

        $providerOrderId = $this->trimOrNull($payload['provider_order_id'] ?? null);
        if (!$providerOrderId) {
            return $this->invalid('MISSING_ORDER_ID', 'provider_order_id missing.');
        }

        $eventType = $this->trimOrNull($payload['event_type'] ?? null);
        if (!$eventType) {
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
            if (!$order) {
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

            if (!$signatureOk) {
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
                            'paid_at' => $now,
                            'updated_at' => $now,
                        ]);
                }

                if (in_array($order->status, ['pending', 'paid', 'fulfilled'], true)) {
                    $actorUserId = $this->trimOrNull($order->user_id ?? null);
                    $actorAnonId = $this->trimOrNull($order->anon_id ?? null);
                    $actorId = $actorUserId ?? $actorAnonId ?? '';

                    $fulfillResult = $this->fulfill($orderId, $actorId, $actorAnonId);
                    if (!$fulfillResult['ok']) {
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
        if (!$userId && !$anonId) {
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

            if (!$applied) {
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
        if (!is_string($value)) {
            return null;
        }
        $v = trim($value);
        return $v !== '' ? $v : null;
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
