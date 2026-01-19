<?php

namespace App\Http\Requests\V0_2;

use Illuminate\Foundation\Http\FormRequest;

class BindEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = trim((string) $this->input('email', ''));

        $this->merge([
            'email' => $email !== '' ? strtolower($email) : $email,
            'consent' => $this->boolish(
                $this->input('consent', $this->input('agree', null))
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255', 'email'],
            'consent' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => '请输入邮箱。',
            'email.email' => '邮箱格式不正确。',
            'consent.required' => '请先阅读并同意隐私协议与用户服务协议。',
            'consent.accepted' => '请先阅读并同意隐私协议与用户服务协议。',
        ];
    }

    public function emailValue(): string
    {
        return (string) $this->validated()['email'];
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
}
