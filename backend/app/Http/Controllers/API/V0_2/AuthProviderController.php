<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\AuthProviderRequest;
use App\Services\Auth\FmTokenService;
use App\Services\Auth\IdentityService;

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

        $providerUid = $this->resolveProviderUid($provider, $providerCode, $anonId);

        /** @var IdentityService $identitySvc */
        $identitySvc = app(IdentityService::class);
        $userId = $identitySvc->resolveUserId($provider, $providerUid);

        if (!$userId) {
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
