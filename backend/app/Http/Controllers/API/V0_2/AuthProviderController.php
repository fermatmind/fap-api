<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\AuthProviderRequest;
use App\Services\Abuse\RateLimiter;
use App\Services\Auth\FmTokenService;
use App\Services\Auth\IdentityService;
use App\Services\Audit\LookupEventLogger;

class AuthProviderController extends Controller
{
    /**
     * POST /api/v0.2/auth/provider
     */
    public function login(AuthProviderRequest $request)
    {
        $provider = $request->provider();
        $providerCode = $request->providerCode();
        $anonId = $request->anonId();

        $ip = (string) ($request->ip() ?? '');
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        if ($providerCode === 'dev' && !app()->environment(['local', 'testing'])) {
            $logger->log('provider_login', false, $request, null, [
                'error_code' => 'INVALID_PROVIDER_CODE',
                'provider' => $provider,
            ]);

            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_PROVIDER_CODE',
                'message' => 'provider_code invalid.',
            ], 422);
        }

        $limitIp = $limiter->limit('FAP_RATE_PROVIDER_LOGIN_IP', 60);
        if ($ip !== '' && !$limiter->hit("provider_login:ip:{$ip}", $limitIp, 60)) {
            $logger->log('provider_login', false, $request, null, [
                'error_code' => 'RATE_LIMITED',
                'provider' => $provider,
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        $providerUid = $this->resolveProviderUid($provider, $providerCode, $anonId);

        /** @var IdentityService $identitySvc */
        $identitySvc = app(IdentityService::class);
        $userId = $identitySvc->resolveUserId($provider, $providerUid);

        if (!$userId) {
            $logger->log('provider_login', true, $request, null, [
                'provider' => $provider,
                'provider_uid' => $providerUid,
                'bound' => false,
            ]);
            return response()->json([
                'ok' => true,
                'bound' => false,
                'provider' => $provider,
                'provider_uid' => $providerUid,
            ]);
        }

        /** @var FmTokenService $tokenSvc */
        $tokenSvc = app(FmTokenService::class);
        $issued = $tokenSvc->issueForUser($userId, [
            'provider' => $provider,
            'provider_uid' => $providerUid,
            'anon_id' => $anonId,
        ]);

        $logger->log('provider_login', true, $request, $userId, [
            'provider' => $provider,
            'provider_uid' => $providerUid,
            'bound' => true,
        ]);

        return response()->json([
            'ok' => true,
            'bound' => true,
            'token' => (string) ($issued['token'] ?? ''),
            'expires_at' => $issued['expires_at'] ?? null,
            'user_id' => $userId,
        ]);
    }

    private function resolveProviderUid(string $provider, string $providerCode, ?string $anonId): string
    {
        $provider = strtolower(trim($provider));
        $providerCode = trim($providerCode);

        if ($providerCode === 'dev') {
            $seed = $anonId !== null && $anonId !== '' ? $anonId : 'dev';
            return $provider . '_dev_' . substr(sha1($seed), 0, 16);
        }

        return $provider . '_' . substr(sha1($providerCode), 0, 16);
    }
}
