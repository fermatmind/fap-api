<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = (string) config('admin.guard', 'admin');

        $user = auth($guard)->user();
        if ($user !== null) {
            if (property_exists($user, 'is_active') && (int) $user->is_active !== 1) {
                return $this->forbiddenResponse('admin_disabled');
            }

            $request->attributes->set('admin_auth_mode', 'session');
            return $next($request);
        }

        $expect = (string) config('admin.token', '');
        $token = (string) $request->header('X-FAP-Admin-Token', '');
        if ($token === '') {
            $token = (string) $request->header('X-Admin-Token', '');
        }

        if ($token === '') {
            return $this->unauthorizedResponse('admin_token_missing');
        }

        if ($expect === '' || !hash_equals($expect, $token)) {
            return $this->forbiddenResponse('admin_token_invalid');
        }

        $request->attributes->set('admin_auth_mode', 'token');

        return $next($request);
    }

    private function unauthorizedResponse(string $reason): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
            'message' => $reason,
        ], 401);
    }

    private function forbiddenResponse(string $reason): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'FORBIDDEN',
            'message' => $reason,
        ], 403);
    }
}
