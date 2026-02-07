<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Services\Commerce\SkuCatalog;

class OrderManager
{
    private const FINAL_STATUSES = ['fulfilled', 'failed', 'canceled', 'refunded'];

    public function __construct(
        private SkuCatalog $skus,
    ) {
    }

    public function createOrder(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $sku,
        int $quantity,
        ?string $targetAttemptId,
        string $provider,
        ?string $idempotencyKey = null
    ): array {
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }
        if (!Schema::hasTable('skus')) {
            return $this->tableMissing('skus');
        }

        $requestedSku = $this->skus->normalizeSku($sku);
        if ($requestedSku === '') {
            return $this->badRequest('SKU_REQUIRED', 'sku is required.');
        }
        $resolved = $this->skus->resolveSkuMeta($requestedSku);
        $effectiveSku = strtoupper(trim((string) ($resolved['effective_sku'] ?? '')));
        $entitlementId = $resolved['entitlement_id'] ?? null;
        $requestedSku = strtoupper(trim((string) ($resolved['requested_sku'] ?? $requestedSku)));

        $quantity = max(1, (int) $quantity);

        $skuRow = $resolved['sku_row'] ?? null;
        if (!$skuRow) {
            return $this->notFound('SKU_NOT_FOUND', 'sku not found.');
        }

        $skuToLookup = $effectiveSku !== '' ? $effectiveSku : $requestedSku;
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            $provider = 'stub';
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $useIdempotency = $idempotencyKey !== '' && Schema::hasColumn('orders', 'idempotency_key');

        $createRow = function () use (
            $orgId,
            $userId,
            $anonId,
            $skuToLookup,
            $quantity,
            $targetAttemptId,
            $provider,
            $skuRow,
            $requestedSku,
            $effectiveSku,
            $entitlementId,
            $idempotencyKey,
            $useIdempotency
        ): array {
            $orderNo = 'ord_' . Str::uuid();
            $now = now();

            $row = [
                'id' => (string) Str::uuid(),
                'order_no' => $orderNo,
                'org_id' => $orgId,
                'user_id' => $this->trimOrNull($userId),
                'anon_id' => $this->trimOrNull($anonId),
                'sku' => $skuToLookup,
                'quantity' => $quantity,
                'target_attempt_id' => $this->trimOrNull($targetAttemptId),
                'amount_cents' => (int) ($skuRow->price_cents ?? 0) * $quantity,
                'currency' => (string) ($skuRow->currency ?? 'USD'),
                'status' => 'created',
                'provider' => $provider !== '' ? $provider : 'stub',
                'external_trade_no' => null,
                'paid_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($useIdempotency) {
                $row['idempotency_key'] = $idempotencyKey;
            }
            if (Schema::hasColumn('orders', 'requested_sku')) {
                $row['requested_sku'] = $requestedSku;
            }
            if (Schema::hasColumn('orders', 'effective_sku')) {
                $row['effective_sku'] = $effectiveSku !== '' ? $effectiveSku : $skuToLookup;
            }
            if (Schema::hasColumn('orders', 'entitlement_id')) {
                $row['entitlement_id'] = $entitlementId;
            }

            $row = $this->applyLegacyColumns($row, $skuRow);

            return $row;
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

    public function getOrder(int $orgId, string $orderNo): array
    {
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }

        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no is required.');
        }

        $query = DB::table('orders')->where('order_no', $orderNo);
        if (Schema::hasColumn('orders', 'org_id')) {
            $query->where('org_id', $orgId);
        } elseif ($orgId !== 0) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $order = $query->first();
        if (!$order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        return [
            'ok' => true,
            'order' => $order,
        ];
    }

    public function transitionToPaidAtomic(
        string $orderNo,
        int $orgId,
        string $provider,
        ?string $externalTradeNo = null,
        ?string $paidAt = null
    ): array {
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }

        $orderNo = trim($orderNo);
        if ($orderNo === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no is required.');
        }

        $query = DB::table('orders')->where('order_no', $orderNo);
        if (Schema::hasColumn('orders', 'org_id')) {
            $query->where('org_id', $orgId);
        } elseif ($orgId !== 0) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $order = $query->lockForUpdate()->first();
        if (!$order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $fromStatus = strtolower((string) ($order->status ?? ''));
        if ($fromStatus === '') {
            $fromStatus = 'created';
        }

        if (in_array($fromStatus, ['paid', 'fulfilled'], true)) {
            return [
                'ok' => true,
                'order' => $order,
                'already_paid' => true,
            ];
        }

        if (!$this->isTransitionAllowed($fromStatus, 'paid')) {
            return $this->conflict('ORDER_STATUS_INVALID', 'invalid order status transition.');
        }

        $now = now();
        $updates = [
            'status' => 'paid',
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('orders', 'paid_at') && empty($order->paid_at)) {
            $updates['paid_at'] = ($paidAt !== null && $paidAt !== '') ? $paidAt : $now;
        }

        if ($provider !== '' && Schema::hasColumn('orders', 'provider')) {
            $updates['provider'] = $provider;
        }

        if ($externalTradeNo && Schema::hasColumn('orders', 'external_trade_no')) {
            $updates['external_trade_no'] = $externalTradeNo;
        }

        $updateQuery = DB::table('orders')->where('order_no', $orderNo);
        if (Schema::hasColumn('orders', 'org_id')) {
            $updateQuery->where('org_id', $orgId);
        }
        $updateQuery->where('status', $fromStatus);

        $updated = $updateQuery->update($updates);
        if ($updated === 0) {
            $current = DB::table('orders')->where('order_no', $orderNo)->first();
            if (!$current) {
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

        $order = DB::table('orders')->where('order_no', $orderNo)->first();

        return [
            'ok' => true,
            'order' => $order,
            'changed' => true,
        ];
    }

    public function transition(string $orderNo, string $toStatus, ?int $orgId = null): array
    {
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }

        $orderNo = trim($orderNo);
        $toStatus = strtolower(trim($toStatus));
        if ($orderNo === '' || $toStatus === '') {
            return $this->badRequest('ORDER_REQUIRED', 'order_no and status are required.');
        }

        $query = DB::table('orders')->where('order_no', $orderNo);
        if ($orgId !== null && Schema::hasColumn('orders', 'org_id')) {
            $query->where('org_id', $orgId);
        }

        $order = $query->first();
        if (!$order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        $fromStatus = strtolower((string) ($order->status ?? ''));
        if ($fromStatus === '') {
            $fromStatus = 'created';
        }

        if ($fromStatus === $toStatus) {
            return [
                'ok' => true,
                'order' => $order,
            ];
        }

        if (!$this->isTransitionAllowed($fromStatus, $toStatus)) {
            return $this->conflict('ORDER_STATUS_INVALID', 'invalid order status transition.');
        }

        $updates = [
            'status' => $toStatus,
            'updated_at' => now(),
        ];

        if ($toStatus === 'paid' && Schema::hasColumn('orders', 'paid_at')) {
            $updates['paid_at'] = $updates['updated_at'];
        }

        if ($toStatus === 'fulfilled' && Schema::hasColumn('orders', 'fulfilled_at')) {
            $updates['fulfilled_at'] = $updates['updated_at'];
        }

        if ($toStatus === 'refunded' && Schema::hasColumn('orders', 'refunded_at')) {
            $updates['refunded_at'] = $updates['updated_at'];
        }

        $updateQuery = DB::table('orders')->where('order_no', $orderNo);
        if ($orgId !== null && Schema::hasColumn('orders', 'org_id')) {
            $updateQuery->where('org_id', $orgId);
        }
        $updateQuery->where('status', $fromStatus);

        $updated = $updateQuery->update($updates);
        if ($updated === 0) {
            $current = DB::table('orders')->where('order_no', $orderNo)->first();
            if (!$current) {
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

        return [
            'ok' => true,
            'order' => $order,
        ];
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

    private function applyLegacyColumns(array $row, object $skuRow): array
    {
        if (Schema::hasColumn('orders', 'amount_total')) {
            $row['amount_total'] = $row['amount_cents'];
        }
        if (Schema::hasColumn('orders', 'amount_refunded')) {
            $row['amount_refunded'] = 0;
        }
        if (Schema::hasColumn('orders', 'item_sku')) {
            $row['item_sku'] = $row['sku'];
        }
        if (Schema::hasColumn('orders', 'provider_order_id')) {
            $row['provider_order_id'] = null;
        }
        if (Schema::hasColumn('orders', 'device_id')) {
            $row['device_id'] = null;
        }
        if (Schema::hasColumn('orders', 'request_id')) {
            $row['request_id'] = null;
        }
        if (Schema::hasColumn('orders', 'created_ip')) {
            $row['created_ip'] = null;
        }
        if (Schema::hasColumn('orders', 'fulfilled_at')) {
            $row['fulfilled_at'] = null;
        }
        if (Schema::hasColumn('orders', 'refunded_at')) {
            $row['refunded_at'] = null;
        }
        if (Schema::hasColumn('orders', 'refund_amount_cents')) {
            $row['refund_amount_cents'] = null;
        }
        if (Schema::hasColumn('orders', 'refund_reason')) {
            $row['refund_reason'] = null;
        }

        return $row;
    }

    private function tableMissing(string $table): array
    {
        return [
            'ok' => false,
            'error' => 'TABLE_MISSING',
            'message' => "{$table} table missing.",
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

    private function findIdempotentOrder(
        int $orgId,
        string $provider,
        string $idempotencyKey,
        bool $lockForUpdate = false
    ): ?object
    {
        $query = DB::table('orders')->where('idempotency_key', $idempotencyKey);
        if (Schema::hasColumn('orders', 'org_id')) {
            $query->where('org_id', $orgId);
        }
        if (Schema::hasColumn('orders', 'provider')) {
            $query->where('provider', $provider);
        }
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
