<?php

namespace App\Http\Controllers\API\V0_3\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\PaymentGateway\PaymentGatewayInterface;
use App\Services\Commerce\PaymentGateway\StripeGateway;
use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentWebhookProcessor $processor,
    ) {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));
        $raw = (string) $request->getContent();
        $sizeBytes = strlen($raw);

        if ($sizeBytes > 262144) {
            return $this->errorResponse(413, 'PAYLOAD_TOO_LARGE', 'payload too large');
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $this->errorResponse(400, 'INVALID_PAYLOAD', 'invalid payload');
        }

        $gateway = $this->resolveGateway($provider);
        if (!$gateway) {
            return $this->errorResponse(404, 'NOT_FOUND', 'not found.');
        }

        if ($gateway->verifySignature($request) !== true) {
            return $this->errorResponse(400, 'INVALID_SIGNATURE', 'invalid signature');
        }

        $sha256 = hash('sha256', $raw);
        $payloadMeta = [
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'raw_sha256' => $sha256,
            'headers' => $request->headers->all(),
            'request_id' => (string) $request->attributes->get('request_id', ''),
            'ip' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ];

        $orgId = $this->resolveOrgId($request);

        try {
            $result = $this->processor->handle(
                $provider,
                $payload,
                $orgId,
                null,
                null,
                true,
                $payloadMeta
            );
        } catch (\Throwable $e) {
            Log::error('PAYMENT_WEBHOOK_INTERNAL_ERROR', [
                'provider' => $provider,
                'request_id' => $payloadMeta['request_id'],
                'exception' => $e->getMessage(),
            ]);

            return $this->errorResponse(500, 'WEBHOOK_INTERNAL_ERROR', 'webhook internal error');
        }

        $status = $this->resolveProcessorResponseStatus($result);

        return response()->json($result, $status);
    }

    private function resolveGateway(string $provider): ?PaymentGatewayInterface
    {
        return match ($provider) {
            'stripe' => new StripeGateway(),
            'billing' => new BillingGateway(),
            default => null,
        };
    }

    private function resolveOrgId(Request $request): int
    {
        $raw = trim((string) $request->header('X-Org-Id', ''));
        if ($raw === '') {
            return 0;
        }

        $parsed = filter_var($raw, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            return 0;
        }

        return (int) $parsed;
    }

    private function resolveProcessorResponseStatus(array $result): int
    {
        $status = $result['status'] ?? null;
        if (is_int($status) && $status >= 100 && $status <= 599 && ($result['ok'] ?? null) === true) {
            return $status;
        }

        return 200;
    }

    private function errorResponse(int $status, string $errorCode, string $message, array $details = []): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'details' => $details === [] ? (object) [] : $details,
        ], $status);
    }
}
