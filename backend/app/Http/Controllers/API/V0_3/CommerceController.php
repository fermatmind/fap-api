<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Commerce\OrderManager;
use App\Services\Commerce\SkuCatalog;
use App\Services\Payments\PaymentRouter;
use App\Support\OrgContext;
use App\Support\RegionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CommerceController extends Controller
{
    public function __construct(
        private OrgContext $orgContext,
        private RegionContext $regionContext,
        private OrderManager $orders,
        private SkuCatalog $skus,
        private PaymentRouter $paymentRouter,
    ) {
    }

    /**
     * GET /api/v0.3/skus?scale=MBTI
     */
    public function listSkus(Request $request): JsonResponse
    {
        $scale = strtoupper(trim((string) $request->query('scale', '')));
        if ($scale === '') {
            abort(400, 'scale is required.');
        }

        $items = $this->skus->listActiveSkus($scale);

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * POST /api/v0.3/orders
     */
    public function createOrder(Request $request, ?string $providerFromRoute = null): JsonResponse
    {
        $payload = $request->validate([
            'sku' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'target_attempt_id' => ['nullable', 'string', 'max:64'],
            'provider' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'org_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'anon_id' => ['prohibited'],
        ]);

        $requestedProvider = $this->resolveRequestedProvider($payload, $providerFromRoute);
        $this->guardStubProvider($request, $requestedProvider);

        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);

        $provider = $this->resolveProvider($payload, $providerFromRoute);
        if ($provider === '') {
            abort(422, 'provider unavailable.');
        }

        $idempotencyKey = $this->resolveIdempotencyKey($request, $payload);

        $result = $this->orders->createOrder(
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId !== null ? (string) $anonId : null,
            (string) $payload['sku'],
            (int) ($payload['quantity'] ?? 1),
            $payload['target_attempt_id'] ?? null,
            $provider,
            $idempotencyKey
        );

        if (!($result['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) data_get($result, 'error_code', data_get($result, 'error', '')));
            $message = trim((string) ($result['message'] ?? ''));
            abort($status, $message !== '' ? $message : 'request failed.');
        }

        return response()->json([
            'ok' => true,
            'order_no' => $result['order_no'] ?? null,
        ]);
    }

    /**
     * GET /api/v0.3/orders/{order_no}
     */
    public function getOrder(Request $request, string $order_no): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);
        $result = $this->orders->getOrder($orgId, $userId !== null ? (string) $userId : null, $anonId, $order_no, true);
        if (!($result['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) data_get($result, 'error_code', data_get($result, 'error', '')));
            $message = trim((string) ($result['message'] ?? ''));
            abort($status, $message !== '' ? $message : 'request failed.');
        }

        $order = $result['order'];
        $ownershipVerified = (bool) ($result['ownership_verified'] ?? true);
        $status = $this->normalizePublicOrderStatus((string) ($order->status ?? ''));
        $message = $this->publicOrderMessage((string) ($order->status ?? ''));

        if (!$ownershipVerified) {
            return response()->json([
                'ok' => true,
                'ownership_verified' => false,
                'order_no' => $order->order_no ?? null,
                'attempt_id' => $order->target_attempt_id ?? null,
                'status' => $status,
                'updated_at' => $order->updated_at ?? null,
                'message' => $message,
            ]);
        }

        return response()->json([
            'ok' => true,
            'ownership_verified' => true,
            'order' => $order,
            'order_no' => $order->order_no ?? null,
            'attempt_id' => $order->target_attempt_id ?? null,
            'status' => $status,
            'message' => $message,
            'amount_cents' => $order->amount_cents ?? $order->amount_total ?? null,
            'currency' => $order->currency ?? null,
        ]);
    }

    /**
     * POST /api/v0.3/orders/checkout
     */
    public function checkout(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'attempt_id' => ['nullable', 'string', 'max:64'],
            'sku' => ['nullable', 'string', 'max:64'],
            'order_no' => ['nullable', 'string', 'max:64'],
            'provider' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);

        $existingOrderNo = trim((string) ($payload['order_no'] ?? ''));
        if ($existingOrderNo !== '') {
            $existing = $this->orders->getOrder($orgId, $userId !== null ? (string) $userId : null, $anonId, $existingOrderNo);
            if ($existing['ok'] ?? false) {
                $order = $existing['order'];
                return response()->json([
                    'ok' => true,
                    'order_no' => $order->order_no ?? $existingOrderNo,
                    'attempt_id' => $order->target_attempt_id ?? null,
                    'status' => $this->normalizePublicOrderStatus((string) ($order->status ?? '')),
                    'message' => $this->publicOrderMessage((string) ($order->status ?? '')),
                    'checkout_url' => null,
                ]);
            }
        }

        $sku = trim((string) ($payload['sku'] ?? ''));
        if ($sku === '') {
            $sku = $this->resolveDefaultCheckoutSku();
        }
        if ($sku === '') {
            abort(422, 'sku unavailable.');
        }

        $provider = strtolower(trim((string) ($payload['provider'] ?? '')));
        if ($provider === '') {
            $provider = (string) $this->paymentRouter->primaryProviderForRegion($this->regionContext->region());
        }
        if ($provider === '' || !in_array($provider, ['stripe', 'billing', 'stub'], true)) {
            abort(422, 'provider unavailable.');
        }
        $this->guardStubProvider($request, $provider);

        $idempotencyKey = $this->resolveIdempotencyKey($request, $payload);
        $attemptId = trim((string) ($payload['attempt_id'] ?? ''));

        $created = $this->orders->createOrder(
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId !== null ? (string) $anonId : null,
            $sku,
            1,
            $attemptId !== '' ? $attemptId : null,
            $provider,
            $idempotencyKey
        );

        if (!($created['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) data_get($created, 'error_code', data_get($created, 'error', '')));
            $message = trim((string) ($created['message'] ?? 'request failed.'));
            abort($status, $message);
        }

        return response()->json([
            'ok' => true,
            'order_no' => $created['order_no'] ?? null,
            'attempt_id' => $attemptId !== '' ? $attemptId : null,
            'status' => 'pending',
            'message' => 'Order created, waiting for payment.',
            'checkout_url' => null,
        ]);
    }

    /**
     * POST /api/v0.3/orders/lookup
     */
    public function lookup(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_no' => ['required', 'string', 'max:64'],
            'email' => ['required', 'string', 'max:320'],
        ]);

        $orderNo = trim((string) ($payload['order_no'] ?? ''));
        $orgId = $this->orgContext->orgId();

        $query = DB::table('orders')->where('order_no', $orderNo);
        if ($orgId > 0) {
            $query->where('org_id', $orgId);
        }

        $order = $query->first();
        if (!$order) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORDER_NOT_FOUND',
                'message' => 'order not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'order_no' => $order->order_no ?? $orderNo,
            'status' => $this->normalizePublicOrderStatus((string) ($order->status ?? '')),
        ]);
    }

    /**
     * POST /api/v0.3/orders/{order_no}/resend
     */
    public function resend(Request $request, string $order_no): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);
        $found = $this->orders->getOrder($orgId, $userId !== null ? (string) $userId : null, $anonId, $order_no);
        if (!($found['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ORDER_NOT_FOUND',
                'message' => 'order not found.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Delivery notice has been queued.',
        ]);
    }

    private function mapErrorStatus(string $code): int
    {
        return match ($code) {
            'SKU_NOT_FOUND', 'ORDER_NOT_FOUND' => 404,
            'TABLE_MISSING' => 500,
            default => 400,
        };
    }

    private function resolveAnonId(Request $request): ?string
    {
        $candidates = [
            $this->orgContext->anonId(),
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) || is_numeric($candidate)) {
                $value = trim((string) $candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function resolveIdempotencyKey(Request $request, array $payload): ?string
    {
        $header = trim((string) $request->header('Idempotency-Key', ''));
        if ($header !== '') {
            return $header;
        }

        $body = trim((string) ($payload['idempotency_key'] ?? ''));
        return $body !== '' ? $body : null;
    }

    private function resolveProvider(array $payload, ?string $providerFromRoute): string
    {
        $provider = $this->resolveRequestedProvider($payload, $providerFromRoute);
        return in_array($provider, $this->allowedProviders(), true) ? $provider : '';
    }

    private function resolveRequestedProvider(array $payload, ?string $providerFromRoute): string
    {
        $provider = trim((string) ($providerFromRoute ?? ''));
        if ($provider === '') {
            $provider = trim((string) ($payload['provider'] ?? ''));
        }
        if ($provider === '') {
            $provider = (string) $this->paymentRouter->primaryProviderForRegion($this->regionContext->region());
        }

        return strtolower(trim($provider));
    }

    private function guardStubProvider(Request $request, string $provider): void
    {
        if ($provider !== 'stub' || $this->isStubEnabled()) {
            return;
        }

        Log::warning('SECURITY_STUB_PROVIDER_BLOCKED', [
            'request_id' => $this->resolveRequestId($request),
            'provider' => $provider,
            'ip' => $request->ip(),
        ]);

        abort(404);
    }

    private function allowedProviders(): array
    {
        $providers = ['stripe', 'billing'];
        if ($this->isStubEnabled()) {
            $providers[] = 'stub';
        }

        return $providers;
    }

    private function isStubEnabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('payments.allow_stub') === true;
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-ID', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->input('request_id', ''));
        return $requestId !== '' ? $requestId : (string) Str::uuid();
    }

    private function resolveDefaultCheckoutSku(): string
    {
        $sku = DB::table('skus')
            ->where('is_active', 1)
            ->orderBy('price_cents')
            ->value('sku');

        $sku = trim((string) $sku);
        return $sku !== '' ? strtoupper($sku) : '';
    }

    private function normalizePublicOrderStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'paid', 'fulfilled' => 'paid',
            'failed' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }

    private function publicOrderMessage(string $status): string
    {
        return match ($this->normalizePublicOrderStatus($status)) {
            'paid' => 'Payment confirmed.',
            'failed' => 'Payment failed.',
            'canceled' => 'Order canceled.',
            'refunded' => 'Order refunded.',
            default => 'Confirming your payment...',
        };
    }
}
