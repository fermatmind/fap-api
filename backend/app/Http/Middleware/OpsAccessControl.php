<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class OpsAccessControl
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = (string) optional($request->route())->getName();
        if ($routeName === '' || ! str_starts_with($routeName, 'filament.ops.')) {
            return $next($request);
        }

        if (str_starts_with($routeName, 'filament.ops.auth.')) {
            return $next($request);
        }

        if (
            $routeName === 'filament.ops.auth.login'
            && $request->isMethod('post')
            && ! app()->environment(['local', 'testing', 'ci'])
        ) {
            $key = 'ops:admin-login:'.($request->ip() ?? 'unknown');
            $maxAttempts = max(1, (int) config('ops.security.admin_login_max_attempts', 5));

            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return response()->json([
                    'ok' => false,
                    'error_code' => 'RATE_LIMITED',
                    'message' => 'Too many failed login attempts.',
                ], 429);
            }
        }

        if (! app()->environment(['local', 'testing', 'ci'])) {
            $allowedHost = trim((string) config('ops.allowed_host', ''));
            if ($allowedHost !== '') {
                $requestHost = trim((string) $request->getHost());
                if ($allowedHost !== '' && ! str_contains($requestHost, $allowedHost)) {
                    if (app()->environment('production')) {
                        Log::warning('OPS_ACCESS_DEBUG', [
                            'route' => $routeName,
                            'host' => $request->getHost(),
                            'ip' => $request->ip(),
                        ]);
                    }

                    Log::warning('OPS_ACCESS_HOST_BLOCKED', [
                        'request_host' => $requestHost,
                        'allowed_host' => $allowedHost,
                        'path' => $request->path(),
                    ]);

                    abort(403, 'Ops console is not available on this host.');
                }
            }

            $allowlist = array_values(array_filter(array_map(
                static fn ($value): string => trim((string) $value),
                (array) config('ops.ip_allowlist', [])
            )));

            if (! empty($allowlist)) {
                $ip = (string) $request->ip();
                if (! in_array($ip, $allowlist, true)) {
                    if (app()->environment('production')) {
                        Log::warning('OPS_ACCESS_DEBUG', [
                            'route' => $routeName,
                            'host' => $request->getHost(),
                            'ip' => $request->ip(),
                        ]);
                    }

                    Log::warning('OPS_ACCESS_IP_BLOCKED', [
                        'ip' => $ip,
                        'path' => $request->path(),
                    ]);

                    abort(403, 'Ops console IP is not allowlisted.');
                }
            }
        }

        return $next($request);
    }
}
