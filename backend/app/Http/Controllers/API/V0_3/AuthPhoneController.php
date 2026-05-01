<?php

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\Controller;
use App\Services\Abuse\RateLimiter;
use App\Services\Account\AssetCollector;
use App\Services\Audit\LookupEventLogger;
use App\Services\Auth\FmTokenService;
use App\Services\Auth\PhoneOtpService;
use App\Support\PiiCipher;
use App\Support\PiiReadFallbackMonitor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthPhoneController extends Controller
{
    /**
     * POST /api/v0.3/auth/phone/send_code
     */
    public function sendCode(Request $request)
    {
        $this->mergeConsent($request);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'scene' => ['nullable', 'string', 'max:32'],
            'anon_id' => ['nullable', 'string', 'max:128'],
            'device_key' => ['nullable', 'string', 'max:256'],
            'consent' => ['accepted'],
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
        if ($phone !== '' && ! $limiter->hit("phone_send_code:phone:{$phone}", $limitPhone, 60)) {
            $logger->log('phone_send_code', false, $request, null, [
                'error_code' => 'RATE_LIMITED',
                'scope' => 'phone',
                'scene' => $scene,
                'phone_hash' => hash('sha256', $phone),
            ]);

            throw new ApiProblemException(429, 'RATE_LIMITED', 'Too many requests for this phone.');
        }

        $limitIp = $limiter->limit('FAP_RATE_SEND_CODE_IP', 20);
        if ($ip !== '' && ! $limiter->hit("phone_send_code:ip:{$ip}", $limitIp, 60)) {
            $logger->log('phone_send_code', false, $request, null, [
                'error_code' => 'RATE_LIMITED',
                'scope' => 'ip',
                'scene' => $scene,
                'phone_hash' => hash('sha256', $phone),
            ]);

            throw new ApiProblemException(429, 'RATE_LIMITED', 'Too many requests from this IP.');
        }

        try {
            $res = $otp->send(
                $phone,
                $scene,
                $ip,
                $deviceKey,
                isset($data['anon_id']) ? $this->sanitizeAnonId((string) $data['anon_id']) : null
            );
        } catch (ApiProblemException $e) {
            $logger->log('phone_send_code', false, $request, null, [
                'error_code' => $e->errorCode(),
                'scene' => $scene,
                'phone_hash' => hash('sha256', $phone),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $logger->log('phone_send_code', false, $request, null, [
                'error_code' => 'OTP_SEND_FAILED',
                'scene' => $scene,
                'phone_hash' => hash('sha256', $phone),
            ]);

            throw new ApiProblemException(429, 'OTP_SEND_FAILED', 'otp send failed.', [], $e);
        }

        $out = [
            'ok' => true,
            'phone' => $phone,
            'scene' => $scene,
            'ttl_seconds' => (int) ($res['ttl_seconds'] ?? 300),
        ];

        if (! empty($res['dev_code'])) {
            $out['dev_code'] = (string) $res['dev_code'];
        }

        $logger->log('phone_send_code', true, $request, null, [
            'scene' => $scene,
            'phone_hash' => hash('sha256', $phone),
        ]);

        return response()->json($out);
    }

    /**
     * POST /api/v0.3/auth/phone/verify
     */
    public function verify(Request $request)
    {
        $this->mergeConsent($request);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string', 'max:16'],
            'scene' => ['nullable', 'string', 'max:32'],
            'anon_id' => ['nullable', 'string', 'max:128'],
            'resume_token' => ['nullable', 'string', 'max:128'],
            'device_key' => ['nullable', 'string', 'max:256'],
            'consent' => ['accepted'],
        ]);

        $phone = $this->normalizePhone((string) $data['phone']);
        $code = trim((string) $data['code']);
        $scene = (string) ($data['scene'] ?? 'login');
        $logger = app(LookupEventLogger::class);

        /** @var PhoneOtpService $otp */
        $otp = app(PhoneOtpService::class);

        try {
            $res = $otp->verify($phone, $code, $scene);
        } catch (ApiProblemException $e) {
            $logger->log('phone_verify', false, $request, null, [
                'error_code' => $e->errorCode(),
                'scene' => $scene,
                'phone_hash' => hash('sha256', $phone),
            ]);

            throw $e;
        }

        $requestedAnonId = isset($data['anon_id']) ? $this->sanitizeAnonId((string) $data['anon_id']) : null;
        $resumeToken = isset($data['resume_token']) ? trim((string) $data['resume_token']) : '';
        $otpBoundAnonId = is_array($res) ? $this->sanitizeAnonId((string) ($res['bound_anon_id'] ?? '')) : null;
        $claimedAnonId = null;

        [$userId, $userPayload] = $this->findOrCreateUserByPhone($phone, null);

        try {
            if (is_string($requestedAnonId) && $requestedAnonId !== '' && $resumeToken !== '') {
                /** @var AssetCollector $collector */
                $collector = app(AssetCollector::class);
                $claim = $collector->appendByAnonIdWithResumeToken((string) $userId, $requestedAnonId, $resumeToken);
                if ((int) ($claim['updated'] ?? 0) > 0) {
                    $claimedAnonId = $requestedAnonId;
                    $userPayload['anon_id'] = $claimedAnonId;
                }
            } elseif (
                $this->allowOtpBoundAnonClaimFallback()
                && is_string($requestedAnonId)
                && $requestedAnonId !== ''
                && (
                    $otpBoundAnonId === null
                    || (is_string($otpBoundAnonId) && hash_equals($otpBoundAnonId, $requestedAnonId))
                )
            ) {
                /** @var AssetCollector $collector */
                $collector = app(AssetCollector::class);
                $claim = $collector->appendByAnonId((string) $userId, $requestedAnonId);
                if ((int) ($claim['updated'] ?? 0) > 0) {
                    $claimedAnonId = $requestedAnonId;
                    $userPayload['anon_id'] = $claimedAnonId;
                }
            }
        } catch (\Throwable $e) {
            $requestId = trim((string) $request->header('X-Request-Id', $request->header('X-Request-ID', '')));

            Log::warning('AUTH_PHONE_ASSET_COLLECTOR_APPEND_FAILED', [
                'user_id' => (string) $userId,
                'anon_id' => $requestedAnonId,
                'scene' => $scene,
                'request_id' => $requestId !== '' ? $requestId : null,
                'exception' => $e,
            ]);
        }

        /** @var FmTokenService $tokenSvc */
        $tokenSvc = app(FmTokenService::class);
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        $issued = $tokenSvc->issueForUser((string) $userId, [
            'provider' => 'phone',
            'phone_hash' => $pii->phoneHash($phone),
            'anon_id' => $claimedAnonId,
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

    private function normalizePhone(string $raw): string
    {
        $s = trim($raw);
        $s = str_replace([' ', '-', '(', ')'], '', $s);

        if ($s === '') {
            return $s;
        }

        if ($s[0] === '+') {
            return $s;
        }

        if (preg_match('/^1\d{10}$/', $s)) {
            return '+86'.$s;
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
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null) {
            return false;
        }
        $s = strtolower(trim((string) $v));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function sanitizeAnonId(string $anonId): ?string
    {
        $s = trim($anonId);
        if ($s === '') {
            return null;
        }

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

    private function allowOtpBoundAnonClaimFallback(): bool
    {
        $ci = filter_var((string) getenv('CI'), FILTER_VALIDATE_BOOLEAN);

        return $ci && app()->environment(['testing', 'ci']);
    }

    private function findOrCreateUserByPhone(string $phoneE164, ?string $anonId): array
    {
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);

        $hasUid = \App\Support\SchemaBaseline::hasColumn('users', 'uid');
        $pk = $hasUid ? 'uid' : 'id';

        $hasPhoneE164 = \App\Support\SchemaBaseline::hasColumn('users', 'phone_e164');
        $hasPhone = \App\Support\SchemaBaseline::hasColumn('users', 'phone');
        $hasPhoneHash = \App\Support\SchemaBaseline::hasColumn('users', 'phone_e164_hash');
        $hasPhoneEnc = \App\Support\SchemaBaseline::hasColumn('users', 'phone_e164_enc');

        $phoneCol = $hasPhoneE164 ? 'phone_e164' : ($hasPhone ? 'phone' : null);
        $phoneHash = $pii->phoneHash($phoneE164);
        $phoneEnc = $pii->encrypt($phoneE164);
        $keyVersion = $pii->currentKeyVersion();

        $query = DB::table('users');
        if ($hasPhoneHash) {
            $query->where(function ($q) use ($phoneHash, $phoneCol, $phoneE164): void {
                $q->where('phone_e164_hash', $phoneHash);
                if ($phoneCol !== null) {
                    $q->orWhere($phoneCol, $phoneE164);
                }
            });
        } elseif ($phoneCol) {
            $query->where($phoneCol, $phoneE164);
        } else {
            $query->where($pk, '__no_match__');
        }

        $row = $query->first();

        if ($row) {
            $userId = (string) ($row->{$pk} ?? '');

            return [$userId, $this->buildUserPayloadFromRow($row, $pk)];
        }

        $insert = [];

        if ($hasUid) {
            $insert['uid'] = 'u_'.bin2hex(random_bytes(5));
        }

        if ($hasPhoneHash) {
            $insert['phone_e164_hash'] = $phoneHash;
        }
        if ($hasPhoneEnc) {
            $insert['phone_e164_enc'] = $phoneEnc;
        }
        if (\App\Support\SchemaBaseline::hasColumn('users', 'key_version')) {
            $insert['key_version'] = $keyVersion;
        }

        if ($anonId && \App\Support\SchemaBaseline::hasColumn('users', 'anon_id')) {
            $insert['anon_id'] = $anonId;
        }

        if (\App\Support\SchemaBaseline::hasColumn('users', 'phone_verified_at')) {
            $insert['phone_verified_at'] = now();
        } elseif (\App\Support\SchemaBaseline::hasColumn('users', 'verified_at')) {
            $insert['verified_at'] = now();
        }

        if (\App\Support\SchemaBaseline::hasColumn('users', 'created_at')) {
            $insert['created_at'] = now();
        }
        if (\App\Support\SchemaBaseline::hasColumn('users', 'updated_at')) {
            $insert['updated_at'] = now();
        }

        if (\App\Support\SchemaBaseline::hasColumn('users', 'name') && ! array_key_exists('name', $insert)) {
            $insert['name'] = 'user';
        }
        if (\App\Support\SchemaBaseline::hasColumn('users', 'email') && ! array_key_exists('email', $insert)) {
            $insert['email'] = $pii->legacyEmailPlaceholder($pii->emailHash('phone_'.md5($phoneE164).'@example.local'));
        }
        if (\App\Support\SchemaBaseline::hasColumn('users', 'email_hash') && ! array_key_exists('email_hash', $insert)) {
            $insert['email_hash'] = $pii->emailHash((string) $insert['email']);
        }
        if (\App\Support\SchemaBaseline::hasColumn('users', 'email_enc') && ! array_key_exists('email_enc', $insert)) {
            $insert['email_enc'] = $pii->encrypt((string) $insert['email']);
        }
        if (\App\Support\SchemaBaseline::hasColumn('users', 'password') && ! array_key_exists('password', $insert)) {
            $insert['password'] = bcrypt(bin2hex(random_bytes(8)));
        }

        $insertedId = null;
        if (\App\Support\SchemaBaseline::hasColumn('users', 'id')) {
            $insertedId = DB::table('users')->insertGetId($insert);
        } else {
            DB::table('users')->insert($insert);
        }

        $row2 = DB::table('users')
            ->when($insertedId !== null, fn ($q) => $q->where('id', (int) $insertedId))
            ->when($insertedId === null && $hasPhoneHash, fn ($q) => $q->where('phone_e164_hash', $phoneHash))
            ->when($insertedId === null && ! $hasPhoneHash && $phoneCol !== null, fn ($q) => $q->where($phoneCol, $phoneE164))
            ->first();

        $userId = $row2 ? (string) ($row2->{$pk} ?? '') : (string) ($insert[$pk] ?? '');

        return [$userId, $this->buildUserPayloadFromRow($row2 ?? (object) $insert, $pk)];
    }

    private function buildUserPayloadFromRow(object $row, string $pk): array
    {
        $uid = (string) ($row->{$pk} ?? '');
        /** @var PiiCipher $pii */
        $pii = app(PiiCipher::class);
        /** @var PiiReadFallbackMonitor $fallbackMonitor */
        $fallbackMonitor = app(PiiReadFallbackMonitor::class);

        $phone = null;
        if (\App\Support\SchemaBaseline::hasColumn('users', 'phone_e164_enc')) {
            $phone = $pii->decrypt((string) ($row->phone_e164_enc ?? ''));
        }

        $blockedPlaintextFallback = false;
        if ($phone === null || $phone === '') {
            $plainE164 = property_exists($row, 'phone_e164') ? trim((string) ($row->phone_e164 ?? '')) : '';
            $plainLegacy = property_exists($row, 'phone') ? trim((string) ($row->phone ?? '')) : '';
            $blockedPlaintextFallback = ($plainE164 !== '' || $plainLegacy !== '');
        }

        if ($blockedPlaintextFallback) {
            Log::warning('AUTH_PHONE_PLAINTEXT_READ_FALLBACK_BLOCKED', [
                'user_id' => $uid !== '' ? $uid : null,
                'has_phone_e164' => property_exists($row, 'phone_e164') ? trim((string) ($row->phone_e164 ?? '')) !== '' : false,
                'has_phone_legacy' => property_exists($row, 'phone') ? trim((string) ($row->phone ?? '')) !== '' : false,
            ]);
        }
        $fallbackMonitor->record('users.phone_read', false);

        $anonId = property_exists($row, 'anon_id') ? (string) ($row->anon_id ?? '') : null;
        if ($anonId === '') {
            $anonId = null;
        }

        return [
            'uid' => $uid,
            'bound' => true,
            'phone' => ($phone !== '' ? $phone : null),
            'anon_id' => $anonId,
        ];
    }
}
