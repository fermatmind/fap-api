<?php

namespace App\Http\Controllers\API\V0_3\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Commerce\PaymentWebhookProcessor;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentWebhookProcessor $processor,
        private OrgContext $orgContext,
    ) {
    }

    /**
     * POST /api/v0.3/webhooks/payment/{provider}
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));
        $this->guardStubProvider($request, $provider);

        if (!in_array($provider, ['stripe', 'billing'], true)) {
            Log::warning('PAYMENT_WEBHOOK_PROVIDER_UNSUPPORTED', [
                'provider' => $provider,
                'request_id' => $this->resolveRequestId($request),
            ]);
            return $this->notFoundResponse();
        }

        $rawBody = (string) $request->getContent();

        if ($provider === 'billing') {
            $misconfigured = $this->billingSecretMisconfiguredResponse($request, $provider);
            if ($misconfigured instanceof JsonResponse) {
                return $misconfigured;
            }
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->notFoundResponse();
        }

        $signatureOk = true;
        if ($provider === 'billing') {
            $signatureOk = $this->verifyBillingSignature($request, $rawBody);
            if (!$signatureOk) {
                return $this->notFoundResponse();
            }
        } elseif ($provider === 'stripe') {
            $signatureOk = $this->verifyStripeSignature($request, $rawBody);
            if (!$signatureOk) {
                return $this->notFoundResponse();
            }
        }

        $ctx = $this->resolveContext($request, $payload);
        $orgId = (int) $ctx['org_id'];
        $userId = $ctx['user_id'];
        $anonId = $ctx['anon_id'];

        $result = $this->processor->handle(
            $provider,
            $payload,
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId !== null ? (string) $anonId : null,
            $signatureOk,
        );

        if (!($result['ok'] ?? false)) {
            $status = (int) ($result['status'] ?? 400);
            return response()->json($result, $status);
        }

        return response()->json([
            'ok' => true,
            'duplicate' => (bool) ($result['duplicate'] ?? false),
            'order_no' => $result['order_no'] ?? null,
            'provider_event_id' => $result['provider_event_id'] ?? null,
        ]);
    }

    /**
     * webhook 不走 ResolveOrgContext 中间件：这里必须自己兜底 org_id / user_id
     */
    private function resolveContext(Request $request, array $payload): array
    {
        // OrgContext 在没跑中间件时也能工作（通常会给出 null/0）；这里做强兜底
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->orgContext->anonId();

        // 1) Header: X-Org-Id
        $headerOrg = (string) $request->header('X-Org-Id', '');
        if ($headerOrg !== '' && ctype_digit($headerOrg)) {
            $orgId = (int) $headerOrg;
        }

        // 2) Payload: org_id / orgId
        $payloadOrg = $payload['org_id'] ?? ($payload['orgId'] ?? null);
        if (is_int($payloadOrg)) {
            $orgId = $payloadOrg;
        } elseif (is_string($payloadOrg) && $payloadOrg !== '' && ctype_digit($payloadOrg)) {
            $orgId = (int) $payloadOrg;
        }

        // 3) Payload: user_id / userId
        $payloadUser = $payload['user_id'] ?? ($payload['userId'] ?? null);
        if ($userId === null) {
            if (is_int($payloadUser)) {
                $userId = $payloadUser;
            } elseif (is_string($payloadUser) && $payloadUser !== '' && ctype_digit($payloadUser)) {
                $userId = (int) $payloadUser;
            }
        }

        // 4) Payload: anon_id / anonId
        $payloadAnon = $payload['anon_id'] ?? ($payload['anonId'] ?? null);
        if ($anonId === null && is_string($payloadAnon) && $payloadAnon !== '') {
            $anonId = $payloadAnon;
        }

        // 5) order_no 反查 orders 表（支持 webhook 不带 X-Org-Id 场景）
        $orderNo = $payload['order_no'] ?? ($payload['orderNo'] ?? null);
        if (is_string($orderNo) && $orderNo !== '') {
            $orderRow = DB::table('orders')
                ->select(['org_id', 'user_id'])
                ->where('order_no', $orderNo)
                ->first();

            if ($orderRow) {
                $orderOrg = (int) ($orderRow->org_id ?? 0);
                if ((int) ($orgId ?? 0) === 0 && $orderOrg > 0) {
                    $orgId = $orderOrg;
                }

                if ($userId === null && isset($orderRow->user_id) && is_numeric($orderRow->user_id)) {
                    $userId = (int) $orderRow->user_id;
                }
            }
        }

        return [
            'org_id' => (int) ($orgId ?? 0),
            'user_id' => $userId,
            'anon_id' => $anonId,
        ];
    }

    private function verifyBillingSignature(Request $request, string $rawBody): bool
    {
        $secret = $this->resolveBillingWebhookSecret();
        if ($secret === '') {
            return false;
        }

        $requestId = $this->resolveRequestId($request);

        $provided = trim((string) $request->header('X-Billing-Signature', ''));
        if ($provided === '') {
            Log::warning('BILLING_WEBHOOK_SIG_MISSING', [
                'provider' => 'billing',
                'request_id' => $requestId,
            ]);
            return false;
        }

        $rawTs = trim((string) $request->header('X-Billing-Timestamp', ''));
        if ($rawTs === '' || !preg_match('/^\d{10,13}$/', $rawTs)) {
            Log::warning('BILLING_WEBHOOK_TS_MISSING', [
                'provider' => 'billing',
                'request_id' => $requestId,
            ]);
            return false;
        }

        $timestamp = (int) $rawTs;
        if (strlen($rawTs) === 13) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        $tolerance = (int) (config('services.billing.webhook_tolerance_seconds', 300) ?? 300);
        if ($tolerance <= 0) {
            $tolerance = 300;
        }
        $drift = abs(now()->timestamp - $timestamp);
        if ($drift > $tolerance) {
            Log::warning('BILLING_WEBHOOK_TS_OUT_OF_WINDOW', [
                'provider' => 'billing',
                'request_id' => $requestId,
                'drift_seconds' => $drift,
                'tolerance_seconds' => $tolerance,
            ]);
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        if (!hash_equals($expected, $provided)) {
            Log::warning('BILLING_WEBHOOK_SIG_MISMATCH', [
                'provider' => 'billing',
                'request_id' => $requestId,
            ]);
            return false;
        }

        return true;
    }

    private function verifyStripeSignature(Request $request, ?string $rawBody = null): bool
    {
        $secret = (string) (config('services.stripe.webhook_secret') ?? env('STRIPE_WEBHOOK_SECRET', ''));
        if ($secret === '') {
            return app()->environment(['local', 'testing', 'ci']);
        }

        if ($rawBody === null || $rawBody === '') {
            $rawBody = (string) $request->getContent();
        }

        $header = (string) $request->header('Stripe-Signature', '');
        if ($header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $chunk, 2));
            if ($key === 't') {
                if ($value === '' || !ctype_digit($value)) {
                    return false;
                }
                $timestamp = (int) $value;
                continue;
            }
            if ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || count($signatures) === 0) {
            return false;
        }

        $tolerance = (int) (config('services.stripe.webhook_tolerance_seconds', config('services.stripe.webhook_tolerance', 300)) ?? 300);
        if ($tolerance <= 0) {
            $tolerance = 300;
        }
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function extractWebhookTimestampFromHeaders(Request $request, array $headerNames): ?int
    {
        foreach ($headerNames as $headerName) {
            $raw = trim((string) $request->header($headerName, ''));
            if ($raw === '') {
                continue;
            }

            if (!preg_match('/^\d{10,13}$/', $raw)) {
                return null;
            }

            $timestamp = (int) $raw;
            if (strlen($raw) === 13) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return $timestamp > 0 ? $timestamp : null;
        }

        return null;
    }

    private function billingSecretMisconfiguredResponse(Request $request, string $provider): ?JsonResponse
    {
        $secret = $this->resolveBillingWebhookSecret();
        if ($secret !== '') {
            return null;
        }

        $requestId = $this->resolveRequestId($request);
        Log::error('CRITICAL: BILLING_WEBHOOK_SECRET_MISSING', [
            'request_id' => $requestId,
            'provider' => $provider,
            'environment' => strtolower((string) config('app.env', app()->environment())),
        ]);

        return response()->json([
            'ok' => false,
            'error' => 'INTERNAL_SERVER_ERROR',
            'message' => 'internal server error.',
            'request_id' => $requestId,
        ], 500);
    }

    private function resolveBillingWebhookSecret(): string
    {
        $secret = trim((string) config('services.billing.webhook_secret', ''));
        if ($secret === '') {
            $secret = trim((string) env('BILLING_WEBHOOK_SECRET', ''));
        }

        $normalized = strtolower($secret);
        if ($normalized === '(production_value_required)' || $normalized === 'production_value_required') {
            return '';
        }

        return $secret;
    }

    private function guardStubProvider(Request $request, string $provider): void
    {
        if ($provider !== 'stub' || config('payments.allow_stub') === true) {
            return;
        }

        Log::warning('SECURITY_STUB_PROVIDER_BLOCKED', [
            'request_id' => $this->resolveRequestId($request),
            'provider' => $provider,
            'ip' => $request->ip(),
        ]);

        abort(404);
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

    private function notFoundResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'NOT_FOUND',
            'message' => 'not found.',
        ], 404);
    }
}
