<?php

declare(strict_types=1);

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AttemptEmailBindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->normalizeEmail($this->input('email')),
            'locale' => $this->normalizeString($this->input('locale'), 16),
            'surface' => $this->normalizeString($this->input('surface'), 64) ?? 'result_gate',
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:320'],
            'locale' => ['nullable', 'string', 'max:16'],
            'surface' => ['nullable', Rule::in(['result_gate', 'result', 'report'])],
        ];
    }

    private function normalizeEmail(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim((string) $value), 'UTF-8');

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }
}
