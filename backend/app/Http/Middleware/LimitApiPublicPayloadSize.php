<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class LimitApiPublicPayloadSize
{
    public function handle(Request $request, Closure $next)
    {
        $raw = (string) $request->getContent();
        $len = strlen($raw);

        $max = (int) config('security_limits.public_event_max_payload_bytes', 16384);

        // max=0 表示关闭限制；本项目安全策略采用默认开启
        if ($max > 0 && $len > $max) {
            return response()->json([
                'ok' => false,
                'error' => 'payload_too_large',
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
