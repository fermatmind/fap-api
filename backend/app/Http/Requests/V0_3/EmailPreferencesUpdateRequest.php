<?php

declare(strict_types=1);

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;

final class EmailPreferencesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'token' => $this->normalizeString($this->input('token'), 4096),
            'marketing_updates' => $this->boolean('marketing_updates'),
            'report_recovery' => $this->boolean('report_recovery'),
            'product_updates' => $this->boolean('product_updates'),
        ]);
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:4096'],
            'marketing_updates' => ['required', 'boolean'],
            'report_recovery' => ['required', 'boolean'],
            'product_updates' => ['required', 'boolean'],
        ];
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
