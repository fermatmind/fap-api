<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;

class PhoneOtpService
{
    /**
     * MVP defaults (可后续移到 config/fap.php)
     */
    private int $ttlSeconds = 300;      // 5 min
    private int $maxSendPerIp = 20;     // per hour
    private int $maxSendPerPhone = 10;  // per hour
    private int $maxFail = 8;           // per ttl window

    /**
     * 发送验证码：生成 code -> 写 cache -> 返回 (MVP: local/dev 可返回 dev_code)
     */
    public function send(string $phone, string $scene, string $ip = '', ?string $deviceKey = null): array
    {
        $scene = $this->normalizeScene($scene);
        $p = $this->normalizePhone($phone);

        // rate limit (MVP)
        $this->throttleOrFail($p, $scene, $ip);

        $code = $this->generateCode();

        Cache::put($this->otpKey($p, $scene), $code, $this->ttlSeconds);
        Cache::put($this->failKey($p, $scene), 0, $this->ttlSeconds);

        $res = [
            'ok'          => true,
            'phone'       => $p,
            'scene'       => $scene,
            'ttl_seconds' => $this->ttlSeconds,
        ];

        // local/dev: 允许返回 dev_code 便于验收（不影响线上）
        if ($this->allowDevCode()) {
            $res['dev_code'] = $this->devCode() ?? $code;
            // 如果配置了固定 dev code，就覆盖 cache，保证 verify 用同一个
            if ($this->devCode()) {
                Cache::put($this->otpKey($p, $scene), $this->devCode(), $this->ttlSeconds);
            }
        }

        return $res;
    }

    /**
     * 校验验证码：支持 local/dev 固定码；成功后清理 otp + fail
     */
    public function verify(string $phone, string $code, string $scene): array
    {
        $scene = $this->normalizeScene($scene);
        $p = $this->normalizePhone($phone);

        $code = trim((string) $code);

        // local/dev: 固定码直通（MVP 验收用）
        if ($this->allowDevCode()) {
            $dev = $this->devCode();
            if (is_string($dev) && $dev !== '' && hash_equals($dev, $code)) {
                Cache::forget($this->otpKey($p, $scene));
                Cache::forget($this->failKey($p, $scene));
                return ['ok' => true, 'phone' => $p, 'scene' => $scene, 'via' => 'dev_code'];
            }
        }

        $expected = Cache::get($this->otpKey($p, $scene));
        if (!is_string($expected) || $expected === '') {
            return ['ok' => false, 'error' => 'OTP_MISSING', 'message' => 'OTP expired or not found.'];
        }

        if (!hash_equals($expected, $code)) {
            $fails = (int) Cache::get($this->failKey($p, $scene), 0);
            $fails++;
            Cache::put($this->failKey($p, $scene), $fails, $this->ttlSeconds);

            if ($fails >= $this->maxFail) {
                Cache::forget($this->otpKey($p, $scene));
                return ['ok' => false, 'error' => 'OTP_LOCKED', 'message' => 'Too many failed attempts.'];
            }

            return ['ok' => false, 'error' => 'OTP_INVALID', 'message' => 'Invalid code.'];
        }

        Cache::forget($this->otpKey($p, $scene));
        Cache::forget($this->failKey($p, $scene));

        return ['ok' => true, 'phone' => $p, 'scene' => $scene, 'via' => 'cache'];
    }

    // ----------------------------
    // Internal helpers
    // ----------------------------

    private function normalizeScene(string $scene): string
    {
        $s = strtolower(trim($scene));
        return $s !== '' ? $s : 'login';
    }

    /**
     * 关键：phone 归一化必须在 send/verify 两端一致
     * - 去空格
     * - 保留 + 和数字
     * - 若没有 +，默认 +86（你 CN 主站 MVP）
     */
    private function normalizePhone(string $phone): string
    {
        $raw = trim($phone);
        // keep digits and leading +
        $raw = preg_replace('/[^\d\+]/', '', $raw) ?? '';
        $raw = trim($raw);

        if ($raw === '') return '';

        if ($raw[0] !== '+') {
            // MVP：默认中国区
            $raw = '+86' . ltrim($raw, '0');
        }

        return $raw;
    }

    private function otpKey(string $phone, string $scene): string
    {
        return "otp:{$scene}:{$phone}";
    }

    private function failKey(string $phone, string $scene): string
    {
        return "otp:{$scene}:{$phone}:fail";
    }

    private function rateKeyIp(string $ip): string
    {
        return "otp:rate:ip:{$ip}";
    }

    private function rateKeyPhone(string $phone): string
    {
        return "otp:rate:phone:{$phone}";
    }

    private function throttleOrFail(string $phone, string $scene, string $ip): void
    {
        // ip limit (1h)
        if ($ip !== '') {
            $k = $this->rateKeyIp($ip);
            $n = (int) Cache::get($k, 0);
            if ($n >= $this->maxSendPerIp) {
                abort(response()->json([
                    'ok' => false,
                    'error' => 'RATE_LIMITED',
                    'message' => 'Too many requests from this IP.',
                ], 429));
            }
            Cache::put($k, $n + 1, 3600);
        }

        // phone limit (1h)
        if ($phone !== '') {
            $k = $this->rateKeyPhone($phone);
            $n = (int) Cache::get($k, 0);
            if ($n >= $this->maxSendPerPhone) {
                abort(response()->json([
                    'ok' => false,
                    'error' => 'RATE_LIMITED',
                    'message' => 'Too many OTP requests for this phone.',
                ], 429));
            }
            Cache::put($k, $n + 1, 3600);
        }
    }

    private function generateCode(): string
    {
        $n = random_int(0, 999999);
        return str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private function allowDevCode(): bool
    {
        return app()->environment(['local', 'development']);
    }

    /**
     * 固定 dev code（可选）：env FAP_OTP_DEV_CODE=123456
     */
    private function devCode(): ?string
    {
        $v = (string) env('FAP_OTP_DEV_CODE', '');
        $v = trim($v);
        if ($v === '') return null;
        return preg_match('/^\d{6}$/', $v) ? $v : null;
    }
}