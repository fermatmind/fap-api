<?php

namespace App\Http\Controllers\API\V0_3\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Commerce\PaymentWebhookProcessor;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        if ($provider === 'stub' && !app()->environment(['local', 'testing'])) {
            return $this->notFoundResponse();
        }

        if ($provider === 'billing' && !$this->verifyBillingSignature($request)) {
            return $this->notFoundResponse();
        }

        $payload = $request->all();
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->orgContext->anonId();

        $result = $this->processor->handle(
            $provider,
            is_array($payload) ? $payload : [],
            $orgId,
            $userId !== null ? (string) $userId : null,
            $anonId !== null ? (string) $anonId : null,
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

    private function verifyBillingSignature(Request $request): bool
    {
        $secret = (string) (config('services.billing.webhook_secret') ?? env('BILLING_WEBHOOK_SECRET', ''));
        if ($secret === '') {
            return app()->environment(['local', 'testing']);
        }

        $signature = (string) $request->header('X-Billing-Signature', '');
        if ($signature === '') {
            $signature = (string) $request->header('X-Webhook-Signature', '');
        }
        if ($signature === '') {
            return false;
        }

        $payload = (string) $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
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
