<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitIntegrationsWebhookPayloadSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) $request->getContent();
        $len = strlen($raw);
        $max = (int) config('integrations.webhook_max_payload_bytes', 262144);

        if ($len > $max) {
            return response()->json([
                'ok' => false,
                'error_code' => 'PAYLOAD_TOO_LARGE',
                'message' => 'payload too large',
                'details' => [
                    'max_bytes' => $max,
                    'len_bytes' => $len,
                ],
            ], 413);
        }

        return $next($request);
    }
}
