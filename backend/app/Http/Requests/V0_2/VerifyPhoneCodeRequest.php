<?php

namespace App\Http\Requests\V0_2;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPhoneCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawPhone = trim((string) $this->input('phone', ''));
        $normalized = $this->normalizeToE164($rawPhone);

        $this->merge([
            'phone' => $normalized ?? $rawPhone,
            'code'  => trim((string) $this->input('code', '')),
            'scene' => trim((string) $this->input('scene', 'login')),
            'consent' => $this->boolish(
                $this->input('consent', $this->input('agree', null))
            ),
            'anon_id' => $this->input('anon_id', null),         // ✅ 归集用（appendByAnonId）
            'device_key' => $this->input('device_key', null),   // ✅ 可选
        ]);
    }

    public function rules(): array
    {
        return [
            // ✅ PIPL：verify 也必须同意（避免绕过）
            'consent' => ['required', 'accepted'],

            'phone' => ['required', 'string', 'max:32', 'regex:/^\+[1-9]\d{6,20}$/'],
            'code'  => ['required', 'string', 'regex:/^\d{4,8}$/'], // MVP：4~8 位都行；你服务里一般是 6 位

            'scene' => ['nullable', 'string', 'in:login,bind,lookup'],

            // ✅ 归集（MVP：anon_id）
            'anon_id' => ['nullable', 'string', 'max:128'],

            // 可选：设备 key
            'device_key' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'consent.required' => '请先阅读并同意隐私协议与用户服务协议。',
            'consent.accepted' => '请先阅读并同意隐私协议与用户服务协议。',
            'phone.required' => '请输入手机号。',
            'phone.regex'    => '手机号格式不正确（需为 +86xxxxxxxxxxx 或其它 E.164）。',
            'code.required'  => '请输入验证码。',
            'code.regex'     => '验证码格式不正确。',
            'scene.in'       => 'scene 不合法。',
        ];
    }

    public function phoneE164(): string
    {
        return (string) $this->validated()['phone'];
    }

    public function code(): string
    {
        return (string) $this->validated()['code'];
    }

    public function scene(): string
    {
        $v = $this->validated()['scene'] ?? 'login';
        return is_string($v) && $v !== '' ? $v : 'login';
    }

    public function anonId(): ?string
    {
        $v = $this->validated()['anon_id'] ?? null;
        $v = is_string($v) ? trim($v) : null;
        return ($v !== '') ? $v : null;
    }

    public function deviceKey(): ?string
    {
        $v = $this->validated()['device_key'] ?? null;
        $v = is_string($v) ? trim($v) : null;
        return ($v !== '') ? $v : null;
    }

    // --------------------
    // helpers
    // --------------------

    private function boolish($v): bool
    {
        if (is_bool($v)) return $v;
        if ($v === null) return false;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeToE164(string $raw): ?string
    {
        if ($raw === '') return null;

        $s = preg_replace('/[\s\-\(\)]/', '', $raw);
        $s = trim((string) $s);
        if ($s === '') return null;

        if (str_starts_with($s, '0086')) {
            $s = '+86' . substr($s, 4);
        } elseif (str_starts_with($s, '086')) {
            $s = '+86' . substr($s, 3);
        }

        if (str_starts_with($s, '+')) {
            if (preg_match('/^\+[1-9]\d{6,20}$/', $s)) return $s;
            return null;
        }

        if (preg_match('/^1\d{10}$/', $s)) {
            return '+86' . $s;
        }

        return null;
    }
}
