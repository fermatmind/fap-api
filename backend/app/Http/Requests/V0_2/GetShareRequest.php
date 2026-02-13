<?php

namespace App\Http\Requests\V0_2;

use Illuminate\Foundation\Http\FormRequest;

class GetShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id' => (string) $this->route('id', ''),
            'show_score' => $this->boolish($this->input('show_score', null)),
            'show_breakdown' => $this->boolish($this->input('show_breakdown', null)),
        ]);
    }

    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:64'],
            'show_score' => ['nullable', 'boolean'],
            'show_breakdown' => ['nullable', 'boolean'],
            'ref' => ['nullable', 'string', 'max:1024'],
            'v' => ['nullable', 'string', 'max:64'],
        ];
    }

    private function boolish(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $value;
    }
}
