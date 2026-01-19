<?php

namespace App\Http\Requests\V0_2;

use Illuminate\Foundation\Http\FormRequest;

class SendPhoneCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 统一把 phone 规范化成 E.164 并写回 request
     */
    protected function prepareForValidation(): void
    {
        $raw = trim((string) $this->input('phone', ''));

        $normalized = $this->normalizeToE164($raw);

        $this->merge([
            'phone' => $normalized ?? $raw,
            'scene' => trim((string) $this->input('scene', 'login')),
            'consent' => $this->boolish(
                $this->input('consent', $this->input('agree', null))
            ),
            'device_key' => $this->input('device_key', null),
        ]);
    }

    public function rules(): array
    {
        return [
            // ✅ PIPL：必须显式同意
            'consent' => ['required', 'accepted'],

            // phone：要求 E.164（+xx...）或 +86...
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+[1-9]\d{6,20}$/'],

            // scene：避免被滥用做其它业务场景
            'scene' => ['nullable', 'string', 'in:login,bind,lookup'],

            // 可选：设备 key（以后做 device 归集/限频更精确）
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
            'scene.in'       => 'scene 不合法。',
        ];
    }

    public function attributes(): array
    {
        return [
            'phone' => '手机号',
            'consent' => '协议勾选',
            'scene' => '场景',
        ];
    }

    /**
     * 规范化后给 controller 使用
     */
    public function phoneE164(): string
    {
        return (string) $this->validated()['phone'];
    }

    public function scene(): string
    {
        $v = $this->validated()['scene'] ?? 'login';
        return is_string($v) && $v !== '' ? $v : 'login';
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

    /**
     * 支持：
     * - +8613812345678 -> 原样
     * - 13812345678 -> +8613812345678
     * - 008613812345678 / 08613812345678 -> +8613812345678（简单处理）
     * - 其他国家号：要求用户直接传 E.164（+xx...）
     */
    private function normalizeToE164(string $raw): ?string
    {
        if ($raw === '') return null;

        // 去掉常见分隔符
        $s = preg_replace('/[\s\-\(\)]/', '', $raw);
        $s = trim((string) $s);
        if ($s === '') return null;

        // 0086 / 086 前缀转 +
        if (str_starts_with($s, '0086')) {
            $s = '+86' . substr($s, 4);
        } elseif (str_starts_with($s, '086')) {
            $s = '+86' . substr($s, 3);
        }

        // 已经是 + 开头：直接校验大致格式
        if (str_starts_with($s, '+')) {
            if (preg_match('/^\+[1-9]\d{6,20}$/', $s)) return $s;
            return null;
        }

        // 纯数字：按中国大陆 11 位做 +86
        if (preg_match('/^1\d{10}$/', $s)) {
            return '+86' . $s;
        }

        // 其他情况不做猜测
        return null;
    }
}
