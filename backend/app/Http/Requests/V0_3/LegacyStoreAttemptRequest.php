<?php

declare(strict_types=1);

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;

class LegacyStoreAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'anon_id' => ['required', 'string', 'max:64'],
            'scale_code' => ['required', 'string', 'in:MBTI'],
            'scale_version' => ['required', 'string', 'in:v0.3'],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'string'],
            'answers.*.code' => ['required', 'string', 'in:A,B,C,D,E'],
            'client_platform' => ['nullable', 'string', 'max:32'],
            'client_version' => ['nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'string', 'max:32'],
            'referrer' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:16'],
            'attempt_id' => ['nullable', 'string', 'max:64'],
            'demographics' => ['sometimes', 'array'],
        ];
    }
}
