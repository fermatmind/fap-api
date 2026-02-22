<?php

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attempt_id' => ['required', 'string', 'max:64'],
            'answers' => ['nullable', 'array'],
            'answers.*.question_id' => ['required_with:answers', 'string', 'max:128'],
            'answers.*.code' => ['nullable'],
            'answers.*.question_type' => ['nullable', 'string', 'max:32'],
            'answers.*.question_index' => ['nullable', 'integer', 'min:0'],
            'validity_items' => ['nullable', 'array'],
            'validity_items.*.item_id' => ['required_with:validity_items', 'string', 'max:64'],
            'validity_items.*.code' => ['required_with:validity_items'],
            'duration_ms' => ['required', 'integer', 'min:0'],
            'invite_token' => ['nullable', 'string', 'max:64'],
        ];
    }
}
