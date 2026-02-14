<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Auth\FmTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthWxPhoneController extends Controller
{
    public function __invoke(Request $request)
    {
        if (!app()->environment(['local', 'testing', 'ci'])) {
            abort(404);
        }

        // 最小校验：小程序一定会传 wx_code；anon_id 建议必传（你现在的小程序已经有）
        $data = $request->validate([
            'wx_code'        => ['required', 'string'],
            'phone_code'     => ['nullable', 'string'],
            'encryptedData'  => ['nullable', 'string'],
            'iv'             => ['nullable', 'string'],
            'anon_id'        => ['nullable', 'string', 'max:64'],
        ]);

        // ✅ Phase A：先把“登录->回跳”跑通（不真正去微信侧换手机号）
        $anonId = trim((string)($data['anon_id'] ?? ''));

        // 兜底：如果没传 anon_id，就生成一个（避免后续 me/attempts 无身份）
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
            'ok'    => true,
            'token' => (string) ($issued['token'] ?? ''),
            'user'  => [
                // Phase A：先给一个伪 uid（之后你接 phone SSOT 再换成真实 user_id）
                'uid'    => $userId,
                'bound'  => true,
                'anon_id'=> $anonId,
            ],
        ], 200);
    }
}
