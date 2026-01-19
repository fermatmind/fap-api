<?php

namespace App\Http\Requests\V0_2;

use Illuminate\Foundation\Http\FormRequest;

class BindIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $provider = trim((string) $this->input('provider', ''));
        $providerUid = trim((string) $this->input('provider_uid', ''));

        $this->merge([
            'provider' => strtolower($provider),
            'provider_uid' => $providerUid,
            'consent' => $this->boolish(
                $this->input('consent', $this->input('agree', null))
            ),
            'meta' => $this->input('meta', null),
        ]);
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:wechat,douyin,baidu,web,app'],
            'provider_uid' => ['required', 'string', 'max:128'],
            'consent' => ['required', 'accepted'],
            'meta' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required' => 'provider is required.',
            'provider.in' => 'provider not supported.',
            'provider_uid.required' => 'provider_uid is required.',
            'consent.required' => '请先阅读并同意隐私协议与用户服务协议。',
            'consent.accepted' => '请先阅读并同意隐私协议与用户服务协议。',
        ];
    }

    public function provider(): string
    {
        return (string) $this->validated()['provider'];
    }

    public function providerUid(): string
    {
        return (string) $this->validated()['provider_uid'];
    }

    public function meta(): array
    {
        $m = $this->validated()['meta'] ?? [];
        return is_array($m) ? $m : [];
    }

    private function boolish($v): bool
    {
        if (is_bool($v)) return $v;
        if ($v === null) return false;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }
}
