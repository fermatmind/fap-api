<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\CreateOrderRequest;
use App\Services\Payments\PaymentService;
use Illuminate\Http\Request;

class PaymentsController extends Controller
{
    public function createOrder(CreateOrderRequest $request)
    {
        if (!$this->paymentsEnabled()) {
            return $this->paymentsDisabledResponse();
        }

        [$userId, $anonId] = $this->resolveActor($request);
        if (!$userId && !$anonId) {
            return $this->unauthorizedResponse();
        }

        $request->merge([
            'user_id' => $userId ?? $anonId,
            'anon_id' => $anonId,
            'ip' => (string) ($request->ip() ?? ''),
        ]);

        $service = app(PaymentService::class);
        $result = $service->createOrder($request->orderData());

        if (!$result['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'CREATE_FAILED',
                'message' => $result['message'] ?? 'create order failed.',
            ], (int) ($result['status'] ?? 500));
        }

        $order = $result['order'];
        $orderId = is_array($order) ? ($order['id'] ?? null) : ($order->id ?? null);
        $status = is_array($order) ? ($order['status'] ?? null) : ($order->status ?? null);
        $currency = is_array($order) ? ($order['currency'] ?? null) : ($order->currency ?? null);
        $amountTotal = is_array($order) ? ($order['amount_total'] ?? null) : ($order->amount_total ?? null);
        $itemSku = is_array($order) ? ($order['item_sku'] ?? null) : ($order->item_sku ?? null);
        $quantity = is_array($order) ? ($order['quantity'] ?? null) : ($order->quantity ?? null);

        return response()->json([
            'ok' => true,
            'order_id' => $orderId,
            'status' => $status,
            'currency' => $currency,
            'amount_total' => $amountTotal,
            'item_sku' => $itemSku,
            'quantity' => $quantity,
        ]);
    }

    public function markPaid(Request $request, string $id)
    {
        if (!$this->paymentsEnabled()) {
            return $this->paymentsDisabledResponse();
        }

        if (!$this->devModeAllowed()) {
            return response()->json([
                'ok' => false,
                'error' => 'DEV_ONLY',
                'message' => 'mark_paid is only available in dev mode.',
            ], 403);
        }

        [$userId, $anonId] = $this->resolveActor($request);
        if (!$userId && !$anonId) {
            return $this->unauthorizedResponse();
        }

        $service = app(PaymentService::class);
        $result = $service->markPaid($id, (string) ($userId ?? $anonId), $anonId, [
            'request_id' => $this->requestId($request),
            'ip' => (string) ($request->ip() ?? ''),
        ]);

        if (!$result['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'MARK_PAID_FAILED',
                'message' => $result['message'] ?? 'mark paid failed.',
            ], (int) ($result['status'] ?? 500));
        }

        $order = $result['order'];

        return response()->json([
            'ok' => true,
            'order_id' => $order->id,
            'status' => $order->status,
            'paid_at' => $order->paid_at,
        ]);
    }

    public function fulfill(Request $request, string $id)
    {
        if (!$this->paymentsEnabled()) {
            return $this->paymentsDisabledResponse();
        }

        [$userId, $anonId] = $this->resolveActor($request);
        if (!$userId && !$anonId) {
            return $this->unauthorizedResponse();
        }

        $service = app(PaymentService::class);
        $result = $service->fulfill($id, (string) ($userId ?? $anonId), $anonId);

        if (!$result['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'FULFILL_FAILED',
                'message' => $result['message'] ?? 'fulfill failed.',
            ], (int) ($result['status'] ?? 500));
        }

        $order = $result['order'];

        return response()->json([
            'ok' => true,
            'order_id' => $order->id,
            'status' => $order->status,
            'fulfilled_at' => $order->fulfilled_at,
            'benefit' => $result['benefit'] ?? null,
        ]);
    }

    public function meBenefits(Request $request)
    {
        if (!$this->paymentsEnabled()) {
            return $this->paymentsDisabledResponse();
        }

        [$userId, $anonId] = $this->resolveActor($request);
        if (!$userId && !$anonId) {
            return $this->unauthorizedResponse();
        }

        $service = app(PaymentService::class);
        $result = $service->listBenefits((string) ($userId ?? $anonId));

        if (!$result['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'BENEFITS_FAILED',
                'message' => $result['message'] ?? 'benefits query failed.',
            ], (int) ($result['status'] ?? 500));
        }

        return response()->json([
            'ok' => true,
            'user_id' => (string) ($userId ?? $anonId),
            'items' => $result['items'] ?? [],
        ]);
    }

    public function webhookMock(Request $request)
    {
        if (!$this->paymentsEnabled()) {
            return $this->paymentsDisabledResponse();
        }

        if (!$this->webhookEnabled()) {
            return response()->json([
                'ok' => false,
                'error' => 'WEBHOOK_DISABLED',
                'message' => 'Webhook is disabled.',
            ], 404);
        }

        $providerEventId = $this->trimString($request->input('provider_event_id', ''));
        $providerOrderId = $this->trimString($request->input('provider_order_id', ''));
        $eventType = $this->trimString($request->input('event_type', ''));
        $currency = strtoupper($this->trimString($request->input('currency', 'CNY')));
        $amountTotal = $request->input('amount_total', null);
        $signature = $this->trimString($request->input('signature', ''));

        $missing = [];
        if ($providerEventId === '') {
            $missing[] = 'provider_event_id';
        }
        if ($providerOrderId === '') {
            $missing[] = 'provider_order_id';
        }
        if ($eventType === '') {
            $missing[] = 'event_type';
        }
        if (!is_numeric($amountTotal)) {
            $missing[] = 'amount_total';
        }

        if ($missing !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_PAYLOAD',
                'message' => 'missing or invalid fields.',
                'fields' => $missing,
            ], 422);
        }

        $payload = [
            'provider_event_id' => $providerEventId,
            'provider_order_id' => $providerOrderId,
            'event_type' => $eventType,
            'currency' => $currency !== '' ? $currency : 'CNY',
            'amount_total' => (int) $amountTotal,
            'signature' => $signature,
        ];

        $service = app(PaymentService::class);
        $result = $service->handleWebhookMock($payload, [
            'signature_ok' => $signature === 'dev',
            'request_id' => $this->requestId($request),
            'ip' => (string) ($request->ip() ?? ''),
            'headers_digest' => $this->headersDigest($request),
        ]);

        if (!$result['ok']) {
            return response()->json([
                'ok' => false,
                'error' => $result['error'] ?? 'WEBHOOK_FAILED',
                'message' => $result['message'] ?? 'webhook handling failed.',
            ], (int) ($result['status'] ?? 500));
        }

        return response()->json([
            'ok' => true,
            'idempotent' => (bool) ($result['idempotent'] ?? false),
            'signature_ok' => (bool) ($result['signature_ok'] ?? false),
            'handle_status' => $result['handle_status'] ?? null,
            'order_id' => $result['order_id'] ?? null,
            'order_status' => $result['order_status'] ?? null,
        ]);
    }

    private function paymentsEnabled(): bool
    {
        return $this->boolish(env('PAYMENTS_ENABLED', '0'));
    }

    private function webhookEnabled(): bool
    {
        return $this->boolish(env('WEBHOOK_ENABLED', '0'));
    }

    private function devModeAllowed(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if (config('app.debug') === true) {
            return true;
        }

        return $this->boolish(env('PAYMENTS_DEV_MODE', '0'));
    }

    private function paymentsDisabledResponse()
    {
        return response()->json([
            'ok' => false,
            'error' => 'PAYMENTS_DISABLED',
            'message' => 'Payments API is disabled.',
        ], 404);
    }

    private function unauthorizedResponse()
    {
        return response()->json([
            'ok' => false,
            'error' => 'UNAUTHORIZED',
            'message' => 'Missing or invalid fm_token.',
        ], 401);
    }

    private function resolveActor(Request $request): array
    {
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);

        if (!$userId && $anonId) {
            $userId = $anonId;
        }

        return [$userId, $anonId];
    }

    private function resolveUserId(Request $request): ?string
    {
        $uid = $request->attributes->get('fm_user_id');
        if (is_string($uid) && trim($uid) !== '') {
            return trim($uid);
        }

        $authId = auth()->id();
        if ($authId !== null && (string) $authId !== '') {
            return (string) $authId;
        }

        $u = $request->user();
        if ($u && isset($u->id)) {
            return (string) $u->id;
        }

        return null;
    }

    private function resolveAnonId(Request $request): ?string
    {
        $anonId = $request->attributes->get('anon_id');
        if (is_string($anonId) && trim($anonId) !== '') {
            return trim($anonId);
        }

        $anonId = $request->input('anon_id', $request->header('X-Anon-Id', null));
        $anonId = is_string($anonId) ? trim($anonId) : null;
        return $anonId !== '' ? $anonId : null;
    }

    private function requestId(Request $request): ?string
    {
        $v = $request->header('X-Request-Id', $request->input('request_id', null));
        $v = is_string($v) ? trim($v) : null;
        return $v !== '' ? $v : null;
    }

    private function headersDigest(Request $request): ?string
    {
        $headers = $request->headers->all();
        if (!$headers) {
            return null;
        }

        $encoded = json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return null;
        }

        return hash('sha256', $encoded);
    }

    private function trimString($value): string
    {
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }

    private function boolish($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return false;
        }
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
