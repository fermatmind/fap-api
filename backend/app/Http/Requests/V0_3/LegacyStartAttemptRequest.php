<?php

declare(strict_types=1);

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;

class LegacyStartAttemptRequest extends FormRequest
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
            'question_count' => ['required', 'integer', 'in:24,93,144'],
            'client_platform' => ['required', 'string', 'max:32'],
            'client_version' => ['nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'string', 'max:32'],
            'referrer' => ['nullable', 'string', 'max:255'],
            'meta_json' => ['sometimes', 'array'],
        ];
    }
}
