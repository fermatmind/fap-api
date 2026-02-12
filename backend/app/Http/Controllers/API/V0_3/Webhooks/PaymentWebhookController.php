<?php

namespace App\Http\Controllers\API\V0_3\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\PaymentGateway\PaymentGatewayInterface;
use App\Services\Commerce\PaymentGateway\StripeGateway;
use App\Services\Commerce\PaymentWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PaymentWebhookController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 262144;

    public function __construct(
        private PaymentWebhookProcessor $processor,
    ) {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));
        $raw = (string) $request->getContent();
        $sizeBytes = strlen($raw);

        if ($sizeBytes > self::MAX_PAYLOAD_BYTES) {
            return $this->errorResponse(413, 'PAYLOAD_TOO_LARGE', 'payload too large');
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $this->errorResponse(400, 'INVALID_JSON', 'invalid json payload');
        }

        $gateway = $this->resolveGateway($provider);
        if (!$gateway) {
            return $this->errorResponse(404, 'NOT_FOUND', 'not found.');
        }

        if ($gateway->verifySignature($request) !== true) {
            return $this->errorResponse(400, 'INVALID_SIGNATURE', 'invalid signature');
        }

        $normalized = $gateway->normalizePayload($payload);
        $orderNo = trim((string) ($normalized['order_no'] ?? ''));
        $eventId = trim((string) ($normalized['provider_event_id'] ?? $normalized['event_id'] ?? ''));
        if ($eventId === '') {
            return $this->errorResponse(400, 'MISSING_EVENT_ID', 'provider event id missing');
        }

        [$orgId, $userId, $anonId] = $this->resolveOrderContext($orderNo);

        $sha256 = hash('sha256', $raw);
        $payloadMeta = [
            'size_bytes' => $sizeBytes,
            'sha256' => $sha256,
            'raw_sha256' => $sha256,
            'request_id' => $this->resolveRequestId($request),
            'ip' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ];

        try {
            $result = $this->processor->handle(
                $provider,
                $payload,
                $orgId,
                $userId,
                $anonId,
                true,
                $payloadMeta
            );
        } catch (\Throwable $e) {
            Log::error('PAYMENT_WEBHOOK_INTERNAL_ERROR', [
                'request_id' => $payloadMeta['request_id'],
                'provider' => $provider,
                'event_id' => $eventId,
                'exception' => $e->getMessage(),
            ]);

            return $this->errorResponse(500, 'WEBHOOK_INTERNAL_ERROR', 'webhook internal error');
        }

        $status = 200;
        if (is_array($result) && array_key_exists('status', $result)) {
            $candidate = (int) $result['status'];
            if ($candidate >= 100 && $candidate <= 599) {
                $status = $candidate;
            }
        }

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

    private function resolveOrderContext(string $orderNo): array
    {
        if ($orderNo === '') {
            return [0, null, null];
        }

        $order = DB::table('orders')->where('order_no', $orderNo)->first();
        if (!$order) {
            return [0, null, null];
        }

        $orgId = (int) ($order->org_id ?? 0);
        $userId = isset($order->user_id) && $order->user_id !== null ? trim((string) $order->user_id) : '';
        $anonId = isset($order->anon_id) && $order->anon_id !== null ? trim((string) $order->anon_id) : '';

        return [
            $orgId,
            $userId !== '' ? $userId : null,
            $anonId !== '' ? $anonId : null,
        ];
    }

    private function resolveRequestId(Request $request): string
    {
        $candidates = [
            (string) $request->attributes->get('request_id', ''),
            (string) $request->header('X-Request-Id', ''),
            (string) $request->header('X-Request-ID', ''),
            (string) $request->input('request_id', ''),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
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
