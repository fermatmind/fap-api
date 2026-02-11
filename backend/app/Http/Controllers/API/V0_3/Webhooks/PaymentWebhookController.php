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

class PaymentWebhookController extends Controller
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
            return response()->json([
                'ok' => false,
                'error' => 'PAYLOAD_TOO_LARGE',
                'message' => 'payload too large',
            ], 413);
        }

        $rawSha = hash('sha256', $raw);
        $payloadMeta = [
            'size_bytes' => $sizeBytes,
            'raw_sha256' => $rawSha,
            'sha256' => $rawSha,
            'request_id' => (string) $request->attributes->get('request_id', ''),
            'ip' => (string) $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ];

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_JSON',
                'message' => 'invalid json payload',
            ], 400);
        }

        $gateway = $this->resolveGateway($provider);
        if (!$gateway) {
            return response()->json([
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'not found.',
            ], 404);
        }

        if ($gateway->verifySignature($request) !== true) {
            return response()->json([
                'ok' => false,
                'error' => 'INVALID_SIGNATURE',
                'message' => 'invalid signature',
            ], 400);
        }

        $normalized = $gateway->normalizePayload($payload);
        $orderNo = trim((string) ($normalized['order_no'] ?? ''));
        [$orgId, $userId, $anonId] = $this->resolveOrderContext($orderNo);

        try {
            $res = $this->processor->handle(
                $provider,
                $payload,
                $orgId,
                $userId,
                $anonId,
                true,
                $payloadMeta
            );

            return response()->json($res, 200);
        } catch (\Throwable $e) {
            Log::error('PAYMENT_WEBHOOK_INTERNAL_ERROR', [
                'provider' => $provider,
                'order_no' => $orderNo !== '' ? $orderNo : null,
                'request_id' => $payloadMeta['request_id'],
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'WEBHOOK_INTERNAL_ERROR',
                'message' => 'webhook internal error',
            ], 500);
        }
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

        $row = DB::table('orders')
            ->select(['org_id', 'user_id', 'anon_id'])
            ->where('order_no', $orderNo)
            ->first();

        if (!$row) {
            return [0, null, null];
        }

        $orgId = (int) ($row->org_id ?? 0);
        $userId = $this->stringOrNull($row->user_id ?? null);
        $anonId = $this->stringOrNull($row->anon_id ?? null);

        return [$orgId, $userId, $anonId];
    }

    private function stringOrNull(mixed $value): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v !== '' ? $v : null;
    }
}
