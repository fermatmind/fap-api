<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyIntegrationSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $provider = strtolower(trim((string) $request->route('provider', '')));
        if (!$this->isAllowedProvider($provider)) {
            return $this->unauthorizedResponse();
        }

        $authUserId = $this->resolveAuthenticatedUserId();
        if ($authUserId !== null) {
            $request->attributes->set('integration_auth_mode', 'sanctum');
            $request->attributes->set('integration_signature_ok', false);
            $request->attributes->set('integration_actor_user_id', $authUserId);
            return $next($request);
        }

        $timestampRaw = trim((string) $request->header('X-Integration-Timestamp', ''));
        $signature = strtolower(trim((string) $request->header('X-Integration-Signature', '')));
        if ($timestampRaw === '' || $signature === '' || preg_match('/^\d+$/', $timestampRaw) !== 1) {
            return $this->unauthorizedResponse();
        }

        if (preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return $this->unauthorizedResponse();
        }

        $timestamp = (int) $timestampRaw;
        $tolerance = max(1, (int) config('integrations.signature_tolerance_seconds', 300));
        if (abs(time() - $timestamp) > $tolerance) {
            return $this->unauthorizedResponse();
        }

        $secret = trim((string) config("integrations.providers.{$provider}.secret", ''));
        if ($secret === '') {
            return $this->unauthorizedResponse();
        }

        $rawBody = (string) $request->getContent();
        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        if (!hash_equals($expected, $signature)) {
            return $this->unauthorizedResponse();
        }

        $request->attributes->set('integration_auth_mode', 'signature');
        $request->attributes->set('integration_signature_ok', true);
        $request->attributes->set('integration_actor_user_id', null);

        return $next($request);
    }

    private function resolveAuthenticatedUserId(): ?int
    {
        $id = auth()->id();
        if (is_numeric($id)) {
            return (int) $id;
        }

        try {
            $sanctumGuard = (array) config('auth.guards.sanctum', []);
            if ($sanctumGuard !== []) {
                $sanctumId = Auth::guard('sanctum')->id();
                if (is_numeric($sanctumId)) {
                    Auth::shouldUse('sanctum');
                    return (int) $sanctumId;
                }
            }
        } catch (\Throwable $e) {
            $request = request();
            $requestId = trim((string) $request->header('X-Request-Id', $request->header('X-Request-ID', '')));

            Log::debug('VERIFY_INTEGRATION_SIGNATURE_GUARD_RESOLVE_FAILED', [
                'request_id' => $requestId !== '' ? $requestId : null,
                'exception' => $e,
            ]);
        }

        return null;
    }

    private function isAllowedProvider(string $provider): bool
    {
        if ($provider === '') {
            return false;
        }

        $allowed = (array) config('integrations.allowed_providers', []);
        if ($allowed === []) {
            $allowed = array_keys((array) config('integrations.providers', []));
        }

        $allowed = array_values(array_filter(array_map(
            static fn ($v) => strtolower(trim((string) $v)),
            $allowed
        )));

        return in_array($provider, $allowed, true);
    }

    private function unauthorizedResponse(): Response
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'UNAUTHORIZED',
            'error_code' => 'UNAUTHORIZED',
            'message' => 'Missing authentication or invalid integration signature.',
        ], 401);
    }
}
