<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PaymentService
{
    public const BENEFIT_TYPE = 'report_unlock';
    public const BENEFIT_REF = 'mbti_report_v1';

    public function createOrder(array $data, array $actor): array
    {
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }

        $orderId = (string) Str::uuid();
        $now = now();

        $row = [
            'id' => $orderId,
            'user_id' => $this->trimOrNull($actor['user_id'] ?? null),
            'anon_id' => $this->trimOrNull($actor['anon_id'] ?? null),
            'device_id' => $this->trimOrNull($data['device_id'] ?? null),
            'provider' => 'internal',
            'provider_order_id' => null,
            'status' => 'pending',
            'currency' => (string) ($data['currency'] ?? 'CNY'),
            'amount_total' => (int) ($data['amount_total'] ?? 0),
            'amount_refunded' => 0,
            'item_sku' => (string) ($data['item_sku'] ?? ''),
            'request_id' => $this->trimOrNull($data['request_id'] ?? null),
            'created_ip' => $this->trimOrNull($data['ip'] ?? null),
            'paid_at' => null,
            'fulfilled_at' => null,
            'refunded_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('orders')->insert($row);

        return [
            'ok' => true,
            'order' => $row,
        ];
    }

    public function markPaid(string $orderId, string $userId, ?string $anonId, array $context = []): array
    {
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }

        $order = DB::table('orders')->where('id', $orderId)->first();
        if (!$order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        if (!$this->orderBelongsToActor($order, $userId, $anonId)) {
            return $this->forbidden('ORDER_FORBIDDEN', 'order not owned.');
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

        if (Schema::hasTable('payment_events')) {
            $providerEventId = 'dev_mark_paid:' . $orderId;
            $existing = DB::table('payment_events')->where('provider_event_id', $providerEventId)->first();

            if (!$existing) {
                $payload = [
                    'mode' => 'dev',
                    'event' => 'mark_paid',
                    'order_id' => $orderId,
                ];

                DB::table('payment_events')->insert([
                    'id' => (string) Str::uuid(),
                    'provider' => 'internal',
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
        if (!Schema::hasTable('orders')) {
            return $this->tableMissing('orders');
        }
        if (!Schema::hasTable('benefit_grants')) {
            return $this->tableMissing('benefit_grants');
        }

        $order = DB::table('orders')->where('id', $orderId)->first();
        if (!$order) {
            return $this->notFound('ORDER_NOT_FOUND', 'order not found.');
        }

        if (!$this->orderBelongsToActor($order, $userId, $anonId)) {
            return $this->forbidden('ORDER_FORBIDDEN', 'order not owned.');
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
                'error' => 'MISSING_USER',
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
        if (!Schema::hasTable('benefit_grants')) {
            return $this->tableMissing('benefit_grants');
        }

        $userId = trim($userId);
        if ($userId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'INVALID_USER',
                'message' => 'user id missing.',
            ];
        }

        $q = DB::table('benefit_grants')->where('user_id', $userId);

        if (Schema::hasColumn('benefit_grants', 'status')) {
            $q->where('status', 'active');
        }

        if (Schema::hasColumn('benefit_grants', 'expires_at')) {
            $q->where(function ($sub) {
                $sub->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        }

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

    private function orderBelongsToActor(object $order, ?string $userId, ?string $anonId): bool
    {
        $userId = $this->trimOrNull($userId);
        $anonId = $this->trimOrNull($anonId);

        if ($userId && isset($order->user_id) && $order->user_id === $userId) {
            return true;
        }

        if ($anonId && isset($order->anon_id) && $order->anon_id === $anonId) {
            return true;
        }

        return false;
    }

    private function tableMissing(string $table): array
    {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'TABLE_MISSING',
            'message' => "{$table} table missing.",
        ];
    }

    private function notFound(string $error, string $message): array
    {
        return [
            'ok' => false,
            'status' => 404,
            'error' => $error,
            'message' => $message,
        ];
    }

    private function forbidden(string $error, string $message): array
    {
        return [
            'ok' => false,
            'status' => 403,
            'error' => $error,
            'message' => $message,
        ];
    }

    private function conflict(string $error, string $message): array
    {
        return [
            'ok' => false,
            'status' => 409,
            'error' => $error,
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
