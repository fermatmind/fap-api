<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Auth\FmTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthWxPhoneController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (!app()->environment(['local', 'testing', 'ci'])) {
            abort(404);
        }

        $data = $request->validate([
            'wx_code' => ['required', 'string'],
            'phone_code' => ['nullable', 'string'],
            'encryptedData' => ['nullable', 'string'],
            'iv' => ['nullable', 'string'],
            'anon_id' => ['nullable', 'string', 'max:64'],
        ]);

        $anonId = trim((string) ($data['anon_id'] ?? ''));
        if ($anonId === '') {
            $anonId = 'anon_' . now()->timestamp . '_' . substr(sha1(Str::uuid()->toString()), 0, 10);
        }

        $userId = 'u_' . substr(sha1($anonId), 0, 10);

        /** @var FmTokenService $tokenSvc */
        $tokenSvc = app(FmTokenService::class);
        $issued = $tokenSvc->issueForUser($userId, [
            'provider' => 'wx_phone',
            'anon_id' => $anonId,
        ]);

        return response()->json([
            'ok' => true,
            'token' => (string) ($issued['token'] ?? ''),
            'user' => [
                'uid' => $userId,
                'bound' => true,
                'anon_id' => $anonId,
            ],
        ], 200);
    }
}

