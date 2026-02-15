<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminTotpVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();
        if (! $user) {
            return $next($request);
        }

        if (! (bool) config('admin.totp.enabled', true)) {
            $request->session()->put('ops_admin_totp_verified_user_id', (int) $user->id);

            return $next($request);
        }

        $routeName = (string) optional($request->route())->getName();
        if (in_array($routeName, [
            'filament.ops.auth.login',
            'filament.ops.auth.logout',
            'filament.ops.pages.two-factor-challenge',
        ], true)) {
            return $next($request);
        }

        $enabledAt = $user->totp_enabled_at ?? null;
        if ($enabledAt === null) {
            $request->session()->put('ops_admin_totp_verified_user_id', (int) $user->id);

            return $next($request);
        }

        $verifiedUserId = (int) $request->session()->get('ops_admin_totp_verified_user_id', 0);
        if ($verifiedUserId === (int) $user->id) {
            return $next($request);
        }

        return redirect()->route('filament.ops.pages.two-factor-challenge');
    }
}
