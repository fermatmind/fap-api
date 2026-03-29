<?php

declare(strict_types=1);

namespace App\Services\Ops;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OpsLoginTracer
{
    /**
     * @return array<string, mixed>
     */
    public static function context(Request $request, ?string $email = null): array
    {
        $requestId = trim((string) $request->headers->get('X-Request-Id', ''));

        return [
            'trace_id' => (string) Str::uuid(),
            'request_id' => $requestId !== '' ? $requestId : (string) Str::uuid(),
            'ip' => (string) ($request->ip() ?? 'unknown'),
            'ua' => trim((string) ($request->userAgent() ?? '')),
            'email' => self::maskEmail($email),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    public static function start(array $context, array $extra = []): void
    {
        Log::channel('stack')->info('OPS_LOGIN_TRACE_START', [
            ...$context,
            ...$extra,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    public static function success(array $context, array $extra = []): void
    {
        Log::channel('stack')->info('OPS_LOGIN_SUCCESS', [
            ...$context,
            ...$extra,
        ]);
    }

    private static function maskEmail(?string $email): ?string
    {
        $value = strtolower(trim((string) $email));

        if ($value === '' || ! str_contains($value, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $value, 2);
        $maskedLocal = strlen($local) <= 2
            ? substr($local, 0, 1).'*'
            : substr($local, 0, 2).str_repeat('*', max(1, strlen($local) - 2));

        return $maskedLocal.'@'.$domain;
    }
}
