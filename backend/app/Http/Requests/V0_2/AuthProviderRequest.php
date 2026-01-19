<?php

namespace App\Http\Requests\V0_2;

use Illuminate\Foundation\Http\FormRequest;

class AuthProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $provider = trim((string) $this->input('provider', ''));
        $providerCode = trim((string) $this->input('provider_code', ''));
        $anonId = trim((string) $this->input('anon_id', ''));

        $this->merge([
            'provider' => strtolower($provider),
            'provider_code' => $providerCode,
            'anon_id' => $anonId !== '' ? $anonId : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:wechat,douyin,baidu,web,app'],
            'provider_code' => ['required', 'string', 'max:128'],
            'anon_id' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function provider(): string
    {
        return (string) $this->validated()['provider'];
    }

    public function providerCode(): string
    {
        return (string) $this->validated()['provider_code'];
    }

    public function anonId(): ?string
    {
        $v = $this->validated()['anon_id'] ?? null;
        $v = is_string($v) ? trim($v) : null;
        return ($v !== '') ? $v : null;
    }
}
