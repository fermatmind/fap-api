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
    private const MAX_PAYLOAD_BYTES = 262144;

    public function __construct(
        private PaymentWebhookProcessor $processor,
    ) {
    }

    public function handle(Request $request, string $provider): JsonResponse
    {
        $provider = strtolower(trim($provider));
        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_PAYLOAD_BYTES) {
            return $this->errorResponse(413, 'PAYLOAD_TOO_LARGE', 'payload too large');
        }

        $decoded = json_decode($raw, true);
        $payload = is_array($decoded) ? $decoded : [];
        $signatureOk = $this->resolveSignatureOk($request, $provider);

        try {
            $result = $this->processor->process($provider, $payload, $signatureOk);
        } catch (\Throwable $e) {
            Log::error('PAYMENT_WEBHOOK_INTERNAL_ERROR', [
                'request_id' => $this->resolveRequestId($request),
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);

            return $this->errorResponse(500, 'WEBHOOK_INTERNAL_ERROR', 'webhook internal error');
        }

        $status = (int) ($result['status'] ?? 200);
        if ($status < 100 || $status > 599) {
            $status = 200;
        }

        unset($result['status']);

        return response()->json($result, $status);
    }

    private function resolveSignatureOk(Request $request, string $provider): bool
    {
        return match ($provider) {
            'stripe' => $this->verifyStripeSignature($request),
            'billing' => $this->verifyBillingSignature($request),
            default => false,
        };
    }

    private function verifyStripeSignature(Request $request): bool
    {
        $secret = $this->resolveStripeSecret();
        $legacySecret = $this->resolveStripeLegacySecret();
        $gateway = new StripeGateway();
        $tolerance = $this->resolveTolerance();

        if ($this->verifyWithScopedConfig($request, $gateway, [
            'services.stripe.webhook_secret' => $secret,
            'services.stripe.webhook_tolerance_seconds' => $tolerance,
            'services.stripe.webhook_tolerance' => $tolerance,
        ])) {
            return true;
        }

        if ($legacySecret !== '' && $legacySecret !== $secret) {
            return $this->verifyWithScopedConfig($request, $gateway, [
                'services.stripe.webhook_secret' => $legacySecret,
                'services.stripe.webhook_tolerance_seconds' => $tolerance,
                'services.stripe.webhook_tolerance' => $tolerance,
            ]);
        }

        return false;
    }

    private function verifyBillingSignature(Request $request): bool
    {
        $secret = $this->resolveBillingSecret();
        $legacySecret = $this->resolveBillingLegacySecret();
        $gateway = new BillingGateway();
        $tolerance = $this->resolveTolerance();

        if ($this->verifyWithScopedConfig($request, $gateway, [
            'services.billing.webhook_secret' => $secret,
            'services.billing.webhook_tolerance_seconds' => $tolerance,
            'services.billing.webhook_tolerance' => $tolerance,
        ])) {
            return true;
        }

        if ($legacySecret !== '' && $legacySecret !== $secret) {
            return $this->verifyWithScopedConfig($request, $gateway, [
                'services.billing.webhook_secret' => $legacySecret,
                'services.billing.webhook_tolerance_seconds' => $tolerance,
                'services.billing.webhook_tolerance' => $tolerance,
            ]);
        }

        return false;
    }

    private function verifyWithScopedConfig(Request $request, PaymentGatewayInterface $gateway, array $overrides): bool
    {
        $originals = [];
        foreach ($overrides as $key => $value) {
            $originals[$key] = config($key);
            config([$key => $value]);
        }

        try {
            return $gateway->verifySignature($request) === true;
        } finally {
            foreach ($originals as $key => $value) {
                config([$key => $value]);
            }
        }
    }

    private function resolveStripeSecret(): string
    {
        return $this->firstNonEmpty([
            (string) config('payments.stripe.webhook_secret', ''),
            (string) config('services.stripe.webhook_secret', ''),
        ]);
    }

    private function resolveStripeLegacySecret(): string
    {
        return $this->firstNonEmpty([
            (string) config('payments.stripe.legacy_webhook_secret', ''),
            (string) config('services.stripe.legacy_webhook_secret', ''),
        ]);
    }

    private function resolveBillingSecret(): string
    {
        return $this->firstNonEmpty([
            (string) config('payments.billing.webhook_secret', ''),
            (string) config('services.billing.webhook_secret', ''),
        ]);
    }

    private function resolveBillingLegacySecret(): string
    {
        return $this->firstNonEmpty([
            (string) config('payments.billing.legacy_webhook_secret', ''),
            (string) config('services.billing.legacy_webhook_secret', ''),
        ]);
    }

    private function resolveTolerance(): int
    {
        $tolerance = (int) config('payments.signature_tolerance_seconds', 300);

        return $tolerance > 0 ? $tolerance : 300;
    }

    /**
     * @param array<int, string> $candidates
     */
    private function firstNonEmpty(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim($candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
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
