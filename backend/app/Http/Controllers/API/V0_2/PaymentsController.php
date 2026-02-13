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
            return $this->apiError(
                (int) ($result['status'] ?? 500),
                $this->resolveResultErrorCode($result, 'CREATE_FAILED'),
                (string) ($result['message'] ?? 'create order failed.')
            );
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
            return $this->apiError(403, 'DEV_ONLY', 'mark_paid is only available in dev mode.');
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
            return $this->apiError(
                (int) ($result['status'] ?? 500),
                $this->resolveResultErrorCode($result, 'MARK_PAID_FAILED'),
                (string) ($result['message'] ?? 'mark paid failed.')
            );
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
            return $this->apiError(
                (int) ($result['status'] ?? 500),
                $this->resolveResultErrorCode($result, 'FULFILL_FAILED'),
                (string) ($result['message'] ?? 'fulfill failed.')
            );
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
            return $this->apiError(
                (int) ($result['status'] ?? 500),
                $this->resolveResultErrorCode($result, 'BENEFITS_FAILED'),
                (string) ($result['message'] ?? 'benefits query failed.')
            );
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
            return $this->apiError(404, 'WEBHOOK_DISABLED', 'Webhook is disabled.');
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
            return $this->apiError(422, 'INVALID_PAYLOAD', 'missing or invalid fields.', [
                'fields' => $missing,
            ]);
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
            return $this->apiError(
                (int) ($result['status'] ?? 500),
                $this->resolveResultErrorCode($result, 'WEBHOOK_FAILED'),
                (string) ($result['message'] ?? 'webhook handling failed.')
            );
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
        return (bool) config('fap.payments.enabled', true) === true;
    }

    private function webhookEnabled(): bool
    {
        return (bool) config('fap.payments.webhooks_enabled', true) === true;
    }

    private function devModeAllowed(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if (config('app.debug') === true) {
            return true;
        }

        return $this->boolish(\App\Support\RuntimeConfig::value('PAYMENTS_DEV_MODE', '0'));
    }

    private function paymentsDisabledResponse()
    {
        return $this->apiError(404, 'PAYMENTS_DISABLED', 'Payments API is disabled.');
    }

    private function unauthorizedResponse()
    {
        return $this->apiError(401, 'UNAUTHORIZED', 'Missing or invalid fm_token.');
    }

    private function resolveResultErrorCode(array $result, string $fallback): string
    {
        $raw = trim((string) data_get($result, 'error_code', data_get($result, 'error', $fallback)));
        return $raw !== '' ? strtoupper($raw) : $fallback;
    }

    private function apiError(int $status, string $errorCode, string $message, array $details = []): \Illuminate\Http\JsonResponse
    {
        $payload = [
            'ok' => false,
            'error_code' => strtoupper(trim($errorCode)),
            'message' => $message,
        ];
        if ($details !== []) {
            $payload['details'] = $details;
        }

        return response()->json($payload, $status);
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
