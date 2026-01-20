<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Services\Account\AssetCollector;
use App\Services\Abuse\RateLimiter;
use App\Services\Auth\FmTokenService;
use App\Services\Auth\PhoneOtpService;
use App\Services\Audit\LookupEventLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AuthPhoneController extends Controller
{
    /**
     * POST /api/v0.2/auth/phone/send_code
     * body:
     *  - phone (string)  必填
     *  - scene (string)  可选，默认 "login"
     *  - anon_id (string) 可选（用于归集）
     *  - device_key (string) 可选（预留）
     *  - consent (bool/accepted) 必填（PIPL 强制勾选）
     */
    public function sendCode(Request $request)
    {
        $this->mergeConsent($request);

        $data = $request->validate([
            'phone'    => ['required', 'string', 'max:32'],
            'scene'    => ['nullable', 'string', 'max:32'],
            'anon_id'  => ['nullable', 'string', 'max:128'],
            'device_key' => ['nullable', 'string', 'max:256'],
            'consent'  => ['accepted'], // ✅ 强制勾选
        ]);

        $phone = $this->normalizePhone((string) $data['phone']);
        $scene = (string) ($data['scene'] ?? 'login');

        /** @var PhoneOtpService $otp */
        $otp = app(PhoneOtpService::class);

        $ip = (string) ($request->ip() ?? '');
        $deviceKey = isset($data['device_key']) ? (string) $data['device_key'] : null;
        $limiter = app(RateLimiter::class);
        $logger = app(LookupEventLogger::class);

        $limitPhone = $limiter->limit('FAP_RATE_SEND_CODE_PHONE', 5);
        if ($phone !== '' && !$limiter->hit("phone_send_code:phone:{$phone}", $limitPhone, 60)) {
            $logger->log('phone_send_code', false, $request, null, [
                'error' => 'RATE_LIMITED',
                'scope' => 'phone',
                'scene' => $scene,
                'phone_hash' => hash('sha256', $phone),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'RATE_LIMITED',
                'message' => 'Too many requests for this phone.',
            ], 429);
        }

        $limitIp = $limiter->limit('FAP_RATE_SEND_CODE_IP', 20);
        if ($ip !== '' && !$limiter->hit("phone_send_code:ip:{$ip}", $limitIp, 60)) {
            $logger->log('phone_send_code', false, $request, null, [
                'error' => 'RATE_LIMITED',
                'scope' => 'ip',
                'scene' => $scene,
                'phone_hash' => hash('sha256', $phone),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'RATE_LIMITED',
                'message' => 'Too many requests from this IP.',
            ], 429);
        }

        try {
            $res = $otp->send($phone, $scene, $ip, $deviceKey);
            // 约定：$res 至少包含 ttl_seconds；dev_mode 可包含 dev_code
        } catch (\Throwable $e) {
            // 这里统一按“限频/风控”处理
            $logger->log('phone_send_code', false, $request, null, [
                'error' => 'OTP_SEND_FAILED',
                'scene' => $scene,
                'phone_hash' => hash('sha256', $phone),
            ]);
            return response()->json([
                'ok' => false,
                'error' => 'OTP_SEND_FAILED',
                'message' => $e->getMessage(),
            ], 429);
        }

        $out = [
            'ok' => true,
            'phone' => $phone,
            'scene' => $scene,
            'ttl_seconds' => (int) ($res['ttl_seconds'] ?? 300),
        ];

        // ✅ DEV 可回传验证码（生产不要回传）
        if (!empty($res['dev_code'])) {
            $out['dev_code'] = (string) $res['dev_code'];
        }

        $logger->log('phone_send_code', true, $request, null, [
            'scene' => $scene,
            'phone_hash' => hash('sha256', $phone),
        ]);

        return response()->json($out);
    }

    /**
     * POST /api/v0.2/auth/phone/verify
     * body:
     *  - phone (string) 必填
     *  - code  (string) 必填
     *  - scene (string) 可选，默认 "login"
     *  - anon_id (string) 可选（用于归集）
     *  - device_key (string) 可选（预留）
     *  - consent (bool/accepted) 必填（PIPL 强制勾选）
     *
     * 成功：
     *  - 签发 fm_token（统一口径）
     *  - 触发最小归集：appendByAnonId(userId, anonId)
     */
    public function verify(Request $request)
    {
        $this->mergeConsent($request);

        $data = $request->validate([
            'phone'     => ['required', 'string', 'max:32'],
            'code'      => ['required', 'string', 'max:16'],
            'scene'     => ['nullable', 'string', 'max:32'],
            'anon_id'   => ['nullable', 'string', 'max:128'],
            'device_key'=> ['nullable', 'string', 'max:256'],
            'consent'   => ['accepted'], // ✅ 强制勾选
        ]);

        $phone = $this->normalizePhone((string) $data['phone']);
        $code  = trim((string) $data['code']);
        $scene = (string) ($data['scene'] ?? 'login');
        $logger = app(LookupEventLogger::class);

        /** @var PhoneOtpService $otp */
        $otp = app(PhoneOtpService::class);

        $res = $otp->verify($phone, $code, $scene);

$isOk = is_array($res) && (($res['ok'] ?? false) === true);
if (!$isOk) {
    $error = is_array($res) ? (string)($res['error'] ?? 'OTP_INVALID') : 'OTP_INVALID';
    $msg   = is_array($res) ? (string)($res['message'] ?? 'Invalid code.') : 'Invalid code.';

    // MVP：状态码简单映射
    $status = 422;
    if ($error === 'OTP_MISSING') $status = 410;   // expired/not found
    if ($error === 'OTP_LOCKED')  $status = 429;   // too many fails

    $logger->log('phone_verify', false, $request, null, [
        'error' => $error,
        'scene' => $scene,
        'phone_hash' => hash('sha256', $phone),
    ]);

    return response()->json([
        'ok' => false,
        'error' => $error,
        'message' => $msg,
    ], $status);
}

        $anonId = isset($data['anon_id']) ? $this->sanitizeAnonId((string) $data['anon_id']) : null;

        // ✅ 找/建用户（MVP：按 phone 唯一）
        [$userId, $userPayload] = $this->findOrCreateUserByPhone($phone, $anonId);

        // ✅ 归集（MVP：只做 anon_id -> user 的 APPEND）
        try {
            if (is_string($anonId) && $anonId !== '') {
                /** @var AssetCollector $collector */
                $collector = app(AssetCollector::class);
                $collector->appendByAnonId((string) $userId, (string) $anonId);
            }
        } catch (\Throwable $e) {
            // 归集失败不挡登录，但要可观测
            // 你可以在 AssetCollector 里打日志，这里不强行 Log 依赖
        }

        // ✅ 统一签发 token
        /** @var FmTokenService $tokenSvc */
        $tokenSvc = app(FmTokenService::class);
        $issued = $tokenSvc->issueForUser((string) $userId, [
    'provider' => 'phone',
    'phone' => $phone,
    'anon_id' => $anonId,
]);

        $via = is_array($res) ? (string) ($res['via'] ?? '') : '';
        $meta = [
            'scene' => $scene,
            'phone_hash' => hash('sha256', $phone),
        ];
        if ($via !== '') {
            $meta['via'] = $via;
        }
        $logger->log('phone_verify', true, $request, (string) $userId, $meta);

        return response()->json([
            'ok' => true,
            'token' => (string) ($issued['token'] ?? ''),
            'expires_at' => $issued['expires_at'] ?? null,
            'user' => $userPayload,
        ]);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /**
     * 简单 phone 归一化：
     * - 11 位大陆手机号 => +86XXXXXXXXXXX
     * - 已带 + 的保持
     * - 其它保持原样（MVP）
     */
    private function normalizePhone(string $raw): string
    {
        $s = trim($raw);
        $s = str_replace([' ', '-', '(', ')'], '', $s);

        if ($s === '') return $s;

        if ($s[0] === '+') {
            return $s;
        }

        // CN 11-digit
        if (preg_match('/^1\d{10}$/', $s)) {
            return '+86' . $s;
        }

        return $s;
    }

    private function mergeConsent(Request $request): void
    {
        $raw = $request->input('consent', null);
        if ($raw === null) {
            $raw = $request->input('agree', null);
        }

        $request->merge([
            'consent' => $this->boolish($raw),
        ]);
    }

    private function boolish($v): bool
    {
        if (is_bool($v)) return $v;
        if ($v === null) return false;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * anon_id 防污染（沿用你事件那套黑名单思路，MVP 简化版）
     */
    private function sanitizeAnonId(string $anonId): ?string
    {
        $s = trim($anonId);
        if ($s === '') return null;

        $lower = mb_strtolower($s, 'UTF-8');
        $badWords = [
            'todo',
            'placeholder',
            'fixme',
            'tbd',
            '把你查到的anon_id填这里',
            '把你查到的 anon_id 填这里',
            '填这里',
        ];

        foreach ($badWords as $bad) {
            $b = mb_strtolower(trim((string) $bad), 'UTF-8');
            if ($b !== '' && mb_strpos($lower, $b) !== false) {
                return null;
            }
        }

        return $s;
    }

    /**
     * 兼容不同 users 表结构的“最小可跑”写法：
     * - 优先用 users.uid 作为 userId（如果存在）
     * - 否则用 users.id
     * - phone 字段优先写 phone_e164，其次 phone
     * - verified 字段优先写 phone_verified_at，其次 verified_at（如果存在）
     */
    private function findOrCreateUserByPhone(string $phoneE164, ?string $anonId): array
    {
        $hasUid = Schema::hasColumn('users', 'uid');
        $pk     = $hasUid ? 'uid' : 'id';

        $hasPhoneE164 = Schema::hasColumn('users', 'phone_e164');
        $hasPhone     = Schema::hasColumn('users', 'phone');

        $phoneCol = $hasPhoneE164 ? 'phone_e164' : ($hasPhone ? 'phone' : null);

        $query = DB::table('users');
        if ($phoneCol) {
            $query->where($phoneCol, $phoneE164);
        } else {
            // 实在没有 phone 字段：MVP 只能按 anon_id 找（很弱），这里直接创建一个“孤儿账号”
            $query->where($pk, '__no_match__');
        }

        $row = $query->first();

        if ($row) {
            $userId = (string) ($row->{$pk} ?? '');
            return [$userId, $this->buildUserPayloadFromRow($row, $pk, $phoneCol)];
        }

        // create
        $insert = [];

        if ($hasUid) {
            $insert['uid'] = 'u_' . bin2hex(random_bytes(5));
        }

        if ($phoneCol) {
            $insert[$phoneCol] = $phoneE164;
        }

        if ($anonId && Schema::hasColumn('users', 'anon_id')) {
            $insert['anon_id'] = $anonId;
        }

        if (Schema::hasColumn('users', 'phone_verified_at')) {
            $insert['phone_verified_at'] = now();
        } elseif (Schema::hasColumn('users', 'verified_at')) {
            $insert['verified_at'] = now();
        }

        if (Schema::hasColumn('users', 'created_at')) $insert['created_at'] = now();
        if (Schema::hasColumn('users', 'updated_at')) $insert['updated_at'] = now();

        // 如果是默认 Laravel users 表，可能有 name/email/password NOT NULL
        // 这里尽量做兜底（不会覆盖你自定义结构）
        if (Schema::hasColumn('users', 'name') && !array_key_exists('name', $insert)) {
            $insert['name'] = 'user';
        }
        if (Schema::hasColumn('users', 'email') && !array_key_exists('email', $insert)) {
            // 避免 unique 冲突
            $insert['email'] = 'phone_' . md5($phoneE164) . '@example.local';
        }
        if (Schema::hasColumn('users', 'password') && !array_key_exists('password', $insert)) {
            $insert['password'] = bcrypt(bin2hex(random_bytes(8)));
        }

        DB::table('users')->insert($insert);

        // re-fetch
        $row2 = DB::table('users')
            ->when($phoneCol !== null, fn($q) => $q->where($phoneCol, $phoneE164))
            ->orderByDesc($hasUid ? 'created_at' : 'id')
            ->first();

        $userId = $row2 ? (string) ($row2->{$pk} ?? '') : (string) ($insert[$pk] ?? '');

        return [$userId, $this->buildUserPayloadFromRow($row2 ?? (object) $insert, $pk, $phoneCol)];
    }

    private function buildUserPayloadFromRow(object $row, string $pk, ?string $phoneCol): array
    {
        $uid = (string) ($row->{$pk} ?? '');
        $phone = $phoneCol ? (string) ($row->{$phoneCol} ?? '') : null;

        $anonId = property_exists($row, 'anon_id') ? (string) ($row->anon_id ?? '') : null;
        if ($anonId === '') $anonId = null;

        return [
            'uid' => $uid,                // ✅ 统一对外叫 uid（即使底层 pk 是 id）
            'bound' => true,
            'phone' => ($phone !== '' ? $phone : null),
            'anon_id' => $anonId,
        ];
    }
}
