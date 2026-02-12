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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
        if (!Schema::hasTable('skus')) {
            abort(500, 'skus table missing.');
        }

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
            'quantity' => ['nullable', 'integer', 'min:1'],
            'target_attempt_id' => ['nullable', 'string', 'max:64'],
            'provider' => ['nullable', 'string', 'max:32'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
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
        $result = $this->orders->getOrder($orgId, $userId !== null ? (string) $userId : null, $anonId, $order_no);
        if (!($result['ok'] ?? false)) {
            $status = $this->mapErrorStatus((string) data_get($result, 'error_code', data_get($result, 'error', '')));
            $message = trim((string) ($result['message'] ?? ''));
            abort($status, $message !== '' ? $message : 'request failed.');
        }

        return response()->json([
            'ok' => true,
            'order' => $result['order'],
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
            $request->query('anon_id'),
            $request->header('X-Anon-Id'),
            $request->header('X-Fm-Anon-Id'),
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
}
