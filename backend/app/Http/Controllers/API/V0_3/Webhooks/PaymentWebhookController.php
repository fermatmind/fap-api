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
        $rawBody = (string) $request->getContent();

        // ✅ 关键修复：CI 跑 stub webhook；production 才隐藏 stub
        if ($provider === 'stub' && !app()->environment(['local', 'testing', 'ci'])) {
            return $this->notFoundResponse();
        }

        $signatureOk = true;
        if ($provider === 'billing') {
            if (!$this->ensureBillingSecretConfigured($request, $provider)) {
                return $this->notFoundResponse();
            }

            $signatureOk = $this->verifyBillingSignature($request, $rawBody);
            if (!$signatureOk) {
                return $this->notFoundResponse();
            }
        }

        if ($provider === 'stripe') {
            $signatureOk = $this->verifyStripeSignature($request, $rawBody);
            if (!$signatureOk) {
                return $this->notFoundResponse();
            }
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $payload = $request->all();
        }
        if (!is_array($payload)) {
            $payload = [];
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
        $secret = (string) (config('services.billing.webhook_secret') ?? env('BILLING_WEBHOOK_SECRET', ''));
        if ($secret === '') {
            return false;
        }

        $signature = (string) $request->header('X-Billing-Signature', '');
        if ($signature === '') {
            $signature = (string) $request->header('X-Webhook-Signature', '');
        }
        if ($signature === '') {
            return false;
        }

        $timestamp = $this->extractWebhookTimestampFromHeaders($request, [
            'X-Billing-Timestamp',
            'X-Webhook-Timestamp',
            'X-Timestamp',
        ]);
        if ($timestamp === null) {
            return false;
        }

        $tolerance = (int) (config('services.billing.webhook_tolerance_seconds', config('services.billing.webhook_tolerance', 300)) ?? 300);
        if ($tolerance <= 0) {
            $tolerance = 300;
        }
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        if (hash_equals($expected, $signature)) {
            return true;
        }

        $allowLegacy = (bool) config('services.billing.allow_legacy_signature', false);
        if ($allowLegacy) {
            $legacyExpected = hash_hmac('sha256', $rawBody, $secret);
            if (hash_equals($legacyExpected, $signature)) {
                return true;
            }
        }

        return false;
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

    private function ensureBillingSecretConfigured(Request $request, string $provider): bool
    {
        $secret = trim((string) config('services.billing.webhook_secret', ''));
        if ($secret !== '') {
            return true;
        }

        Log::error('CRITICAL: BILLING_WEBHOOK_SECRET_MISSING', [
            'request_id' => $this->resolveRequestId($request),
            'provider' => $provider,
        ]);

        return false;
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
