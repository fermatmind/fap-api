<?php

namespace App\Services\Commerce;

use App\Support\Commerce\SkuContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class OrderManager
{
    private const FINAL_STATUSES = ['fulfilled', 'failed', 'canceled', 'refunded'];

    public function createOrder(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        string $sku,
        int $quantity,
        ?string $targetAttemptId,
        string $provider
    ): array {
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }
        if (!Schema::hasTable('skus')) {
            return $this->tableMissing('skus');
        }

        $requestedSku = strtoupper(trim($sku));
        if ($requestedSku === '') {
            return $this->badRequest('SKU_REQUIRED', 'sku is required.');
        }

        $normalized = SkuContract::normalizeRequestedSku($requestedSku);
        $effectiveSku = strtoupper(trim((string) ($normalized['effective_sku'] ?? '')));
        $entitlementId = $normalized['entitlement_id'] ?? null;

        $quantity = max(1, (int) $quantity);

        $skuToLookup = $effectiveSku !== '' ? $effectiveSku : $requestedSku;
        $skuRow = DB::table('skus')->where('sku', $skuToLookup)->first();
        if (!$skuRow) {
            return $this->notFound('SKU_NOT_FOUND', 'sku not found.');
        }

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

        DB::table('orders')->insert($row);

        return [
            'ok' => true,
            'order_no' => $orderNo,
            'order' => $row,
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

        DB::table('orders')
            ->where('order_no', $orderNo)
            ->update($updates);

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
}
