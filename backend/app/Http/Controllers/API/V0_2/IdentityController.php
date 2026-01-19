<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\BindIdentityRequest;
use App\Services\Auth\IdentityService;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    /**
     * POST /api/v0.2/me/identities/bind
     */
    public function bind(BindIdentityRequest $request)
    {
        $userId = (string) $request->attributes->get('fm_user_id', '');
        if ($userId === '') {
            return response()->json([
                'ok' => false,
                'error' => 'UNAUTHORIZED',
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
            return response()->json([
                'ok' => false,
                'error' => $res['error'] ?? 'IDENTITY_BIND_FAILED',
                'message' => $res['message'] ?? 'identity bind failed.',
            ], $status);
        }

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
                'error' => 'UNAUTHORIZED',
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
