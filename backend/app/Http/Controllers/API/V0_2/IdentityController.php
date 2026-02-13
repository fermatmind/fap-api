<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\BindIdentityRequest;
use App\Services\Abuse\RateLimiter;
use App\Services\Auth\IdentityService;
use App\Services\Audit\LookupEventLogger;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    /**
     * POST /api/v0.2/me/identities/bind
     */
    public function bind(BindIdentityRequest $request)
    {
        $ip = (string) ($request->ip() ?? '');
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        $limitIp = $limiter->limit('FAP_RATE_IDENTITIES_BIND_IP', 60);
        if ($ip !== '' && !$limiter->hit("identities_bind:ip:{$ip}", $limitIp, 60)) {
            $logger->log('identities_bind', false, $request, null, [
                'error_code' => 'RATE_LIMITED',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        $userId = (string) $request->attributes->get('fm_user_id', '');
        if ($userId === '') {
            $logger->log('identities_bind', false, $request, null, [
                'error_code' => 'UNAUTHORIZED',
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        /** @var IdentityService $svc */
        $svc = app(IdentityService::class);
        $res = $svc->bind(
            $userId,
            $request->provider(),
            $request->providerUid(),
            $request->meta()
        );

        if (!($res['ok'] ?? false)) {
            $status = (int) ($res['status'] ?? 422);
            $logger->log('identities_bind', false, $request, $userId, [
                'error_code' => $res['error'] ?? 'IDENTITY_BIND_FAILED',
                'provider' => $request->provider(),
                'provider_uid_hash' => hash('sha256', $request->providerUid()),
            ]);
            return response()->json([
                'ok' => false,
                'error_code' => $res['error'] ?? 'IDENTITY_BIND_FAILED',
                'message' => $res['message'] ?? 'identity bind failed.',
            ], $status);
        }

        $logger->log('identities_bind', true, $request, $userId, [
            'provider' => $request->provider(),
            'provider_uid_hash' => hash('sha256', $request->providerUid()),
        ]);

        return response()->json([
            'ok' => true,
            'identity' => $res['identity'] ?? null,
        ], (int) ($res['status'] ?? 200));
    }

    /**
     * GET /api/v0.2/me/identities
     */
    public function index(Request $request)
    {
        $userId = (string) $request->attributes->get('fm_user_id', '');
        if ($userId === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Missing or invalid fm_token.',
            ], 401);
        }

        /** @var IdentityService $svc */
        $svc = app(IdentityService::class);
        $items = $svc->listByUserId($userId);

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }
}
