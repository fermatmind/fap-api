<?php

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;

class ShareViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => (string) $this->route('id', ''),
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:64'],
        ];
    }
}
