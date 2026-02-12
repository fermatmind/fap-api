<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LimitWebhookPayloadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) $request->getContent();
        $len = strlen($raw);
        $max = max(0, (int) config('payments.webhook_max_payload_bytes', 262144));

        if ($max > 0 && $len > $max) {
            Log::warning('SECURITY_PAYMENT_WEBHOOK_PAYLOAD_TOO_LARGE', [
                'provider' => (string) $request->route('provider', ''),
                'ip' => $request->ip(),
                'len' => $len,
                'request_id' => $this->resolveRequestId($request),
                'max' => $max,
            ]);

            return response()->json([
                'ok' => false,
                'error_code' => 'PAYLOAD_TOO_LARGE',
                'message' => 'payload too large',
                'details' => (object) [],
            ], 413);
        }

        return $next($request);
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
        if ($requestId !== '') {
            return $requestId;
        }

        return (string) Str::uuid();
    }
}
