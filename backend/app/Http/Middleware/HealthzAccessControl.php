<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

final class HealthzAccessControl
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment(['local', 'testing'])) {
            return $next($request);
        }

        $ip = (string) $request->ip();
        $allowed = (array) config('healthz.allowed_ips', []);

        if ($ip !== '' && !empty($allowed) && IpUtils::checkIp($ip, $allowed)) {
            return $next($request);
        }

        abort(404);
    }
}
