<?php

namespace App\Http\Controllers\API\V0_3\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Commerce\PaymentGateway\BillingGateway;
use App\Services\Commerce\PaymentGateway\LemonSqueezyGateway;
use App\Services\Commerce\PaymentGateway\PaymentGatewayInterface;
use App\Services\Commerce\PaymentGateway\StripeGateway;
use App\Services\Commerce\PaymentWebhookProcessor;
use App\Services\Payments\PaymentProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;
use Symfony\Component\HttpFoundation\Response;
use Yansongda\Pay\Pay;

final class PaymentWebhookController extends Controller
{
    private const MAX_PAYLOAD_BYTES = 262144;

    private const TRANSIENT_DB_RETRY_MAX_ATTEMPTS = 3;

    private const TRANSIENT_DB_RETRY_BASE_USLEEP = 100000;

    public function __construct(
        private PaymentWebhookProcessor $processor,
        private PaymentProviderRegistry $paymentProviders,
    ) {}

    public function handle(Request $request, string $provider): Response
    {
        $provider = strtolower(trim($provider));
        if (! $this->isProviderEnabled($provider)) {
            return $this->errorResponse(404, 'PROVIDER_DISABLED', 'provider not enabled');
        }

        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_PAYLOAD_BYTES) {
            return $this->errorResponse(413, 'PAYLOAD_TOO_LARGE', 'payload too large');
        }

        $requestId = $this->resolveRequestId($request);

        if ($provider === 'wechatpay') {
            return $this->handleWechatpay($request, $provider, $requestId);
        }

        if ($provider === 'alipay') {
            return $this->handleAlipay($request, $provider, $requestId);
        }

        $decoded = json_decode($raw, true);
        $payload = is_array($decoded) ? $decoded : [];
        $signatureOk = $this->resolveSignatureOk($request, $provider);

        $result = $this->processWithRetry($provider, $payload, $signatureOk, $requestId);
        if ($result instanceof Response) {
            return $result;
        }

        return $this->jsonResult($result);
    }

    private function handleWechatpay(Request $request, string $provider, string $requestId): Response
    {
        if (! class_exists(Pay::class)) {
            return $this->errorResponse(503, 'PAYMENT_PROVIDER_NOT_INSTALLED', 'wechatpay sdk is not installed');
        }

        try {
            Pay::config(config('pay'));
            $payload = $this->normalizeSdkPayload(Pay::wechat()->callback([
                'body' => (string) $request->getContent(),
                'headers' => $request->headers->all(),
            ]));
        } catch (\Throwable $e) {
            Log::warning('PAYMENT_WEBHOOK_INVALID_SIGNATURE', [
                'request_id' => $requestId,
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);

            return $this->errorResponse(400, 'INVALID_SIGNATURE', 'invalid signature.');
        }

        $result = $this->processWithRetry($provider, $payload, true, $requestId);
        if ($result instanceof Response) {
            return $result;
        }

        if (($result['ok'] ?? false) !== true) {
            return $this->jsonResult($result);
        }

        try {
            Pay::config(config('pay'));
            $success = Pay::wechat()->success();

            return $this->normalizeSdkSuccessResponse($success);
        } catch (\Throwable $e) {
            Log::warning('PAYMENT_WEBHOOK_ACK_FAILED', [
                'request_id' => $requestId,
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResult($result);
        }
    }

    private function handleAlipay(Request $request, string $provider, string $requestId): Response
    {
        if (! class_exists(Pay::class)) {
            return $this->errorResponse(503, 'PAYMENT_PROVIDER_NOT_INSTALLED', 'alipay sdk is not installed');
        }

        try {
            Pay::config(config('pay'));
            $payload = $this->normalizeSdkPayload(Pay::alipay()->callback($request->all()));
        } catch (\Throwable $e) {
            Log::warning('PAYMENT_WEBHOOK_INVALID_SIGNATURE', [
                'request_id' => $requestId,
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);

            return $this->errorResponse(400, 'INVALID_SIGNATURE', 'invalid signature.');
        }

        $result = $this->processWithRetry($provider, $payload, true, $requestId);
        if ($result instanceof Response) {
            return $result;
        }

        if (($result['ok'] ?? false) !== true) {
            return $this->jsonResult($result);
        }

        try {
            Pay::config(config('pay'));
            $success = Pay::alipay()->success();

            return $this->normalizeSdkSuccessResponse($success);
        } catch (\Throwable $e) {
            Log::warning('PAYMENT_WEBHOOK_ACK_FAILED', [
                'request_id' => $requestId,
                'provider' => $provider,
                'exception' => $e->getMessage(),
            ]);

            return $this->jsonResult($result);
        }
    }

    private function resolveSignatureOk(Request $request, string $provider): bool
    {
        return match ($provider) {
            'stripe' => $this->verifyStripeSignature($request),
            'billing' => $this->verifyBillingSignature($request),
            'lemonsqueezy' => $this->verifyLemonSqueezySignature($request),
            default => false,
        };
    }

    private function verifyStripeSignature(Request $request): bool
    {
        $secret = $this->resolveStripeSecret();
        $legacySecret = $this->resolveStripeLegacySecret();
        $gateway = new StripeGateway;
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
        $gateway = new BillingGateway;
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

    private function verifyLemonSqueezySignature(Request $request): bool
    {
        $secret = $this->resolveLemonSqueezySecret();
        if ($secret === '') {
            return false;
        }

        return $this->verifyWithScopedConfig($request, new LemonSqueezyGateway, [
            'services.lemonsqueezy.webhook_secret' => $secret,
            'payments.lemonsqueezy.webhook_secret' => $secret,
        ]);
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

    /**
     * @return array<string,mixed>|Response
     */
    private function processWithRetry(string $provider, array $payload, bool $signatureOk, string $requestId): array|Response
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->processor->process($provider, $payload, $signatureOk);
            } catch (\Throwable $e) {
                $attempt++;

                if ($attempt < self::TRANSIENT_DB_RETRY_MAX_ATTEMPTS && $this->isTransientDatabaseFailure($e)) {
                    usleep(self::TRANSIENT_DB_RETRY_BASE_USLEEP * $attempt);

                    continue;
                }

                Log::error('PAYMENT_WEBHOOK_INTERNAL_ERROR', [
                    'request_id' => $requestId,
                    'provider' => $provider,
                    'exception' => $e->getMessage(),
                ]);

                return $this->errorResponse(500, 'WEBHOOK_INTERNAL_ERROR', 'webhook internal error');
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

    private function resolveLemonSqueezySecret(): string
    {
        return $this->firstNonEmpty([
            (string) config('payments.lemonsqueezy.webhook_secret', ''),
            (string) config('services.lemonsqueezy.webhook_secret', ''),
        ]);
    }

    private function resolveTolerance(): int
    {
        $tolerance = (int) config('payments.signature_tolerance_seconds', 300);

        return $tolerance > 0 ? $tolerance : 300;
    }

    /**
     * @param  array<int, string>  $candidates
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

    private function jsonResult(array $result): JsonResponse
    {
        $status = (int) ($result['status'] ?? 200);
        if ($status < 100 || $status > 599) {
            $status = 200;
        }

        unset($result['status']);

        return response()->json($result, $status);
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

    private function isTransientDatabaseFailure(\Throwable $e): bool
    {
        $message = strtolower(trim($e->getMessage()));
        if ($message === '') {
            return false;
        }

        foreach ([
            'database is locked',
            'database table is locked',
            'deadlock found',
            'lock wait timeout exceeded',
            'try restarting transaction',
            'sqlstate[40001]',
            'sqlstate[40p01]',
            'sqlstate[hy000]',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isProviderEnabled(string $provider): bool
    {
        return $this->paymentProviders->isEnabled($provider);
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeSdkPayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if ($payload instanceof \JsonSerializable) {
            $serialized = $payload->jsonSerialize();
            if (is_array($serialized)) {
                return $serialized;
            }
        }

        if (is_object($payload)) {
            if (method_exists($payload, 'toArray')) {
                $array = $payload->toArray();
                if (is_array($array)) {
                    return $array;
                }
            }

            if (method_exists($payload, 'all')) {
                $array = $payload->all();
                if (is_array($array)) {
                    return $array;
                }
            }
        }

        return [];
    }

    private function normalizeSdkSuccessResponse(mixed $response): Response
    {
        if ($response instanceof Response) {
            return $response;
        }

        if ($response instanceof PsrResponseInterface) {
            $headers = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }

            return response((string) $response->getBody(), $response->getStatusCode(), $headers);
        }

        if (is_string($response) && trim($response) !== '') {
            return response($response, 200);
        }

        if (is_array($response)) {
            return response()->json($response, 200);
        }

        if (is_object($response) && method_exists($response, 'getContent')) {
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;

            return response((string) $response->getContent(), $status);
        }

        return response('success', 200);
    }
}
