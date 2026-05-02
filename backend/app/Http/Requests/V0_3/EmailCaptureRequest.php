<?php

declare(strict_types=1);

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EmailCaptureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = [
            'email' => $this->normalizeEmail($this->input('email')),
            'locale' => $this->normalizeString($this->input('locale'), 16),
            'surface' => $this->normalizeString($this->input('surface'), 64),
            'order_no' => $this->normalizeString($this->input('order_no'), 64),
            'attempt_id' => $this->normalizeString($this->input('attempt_id'), 64),
            'share_id' => $this->normalizeString($this->input('share_id'), 128),
            'compare_invite_id' => $this->normalizeString($this->input('compare_invite_id'), 128),
            'share_click_id' => $this->normalizeString($this->input('share_click_id'), 128),
            'entrypoint' => $this->normalizeString($this->input('entrypoint'), 128),
            'referrer' => $this->normalizeString($this->input('referrer'), 2048),
            'landing_path' => $this->normalizeString($this->input('landing_path'), 2048),
            'utm' => $this->normalizeUtm($this->input('utm')),
        ];

        if ($this->has('marketing_consent')) {
            $payload['marketing_consent'] = $this->boolean('marketing_consent');
        }
        $this->merge($payload);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:320'],
            'locale' => ['nullable', 'string', 'max:16'],
            'surface' => ['nullable', Rule::in(['lookup', 'checkout', 'share', 'compare', 'result', 'help'])],
            'order_no' => ['nullable', 'string', 'max:64'],
            'attempt_id' => ['nullable', 'string', 'max:64'],
            'share_id' => ['nullable', 'string', 'max:128'],
            'compare_invite_id' => ['nullable', 'string', 'max:128'],
            'share_click_id' => ['nullable', 'string', 'max:128'],
            'entrypoint' => ['nullable', 'string', 'max:128'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'landing_path' => ['nullable', 'string', 'max:2048'],
            'utm' => ['nullable', 'array'],
            'utm.source' => ['nullable', 'string', 'max:512'],
            'utm.medium' => ['nullable', 'string', 'max:512'],
            'utm.campaign' => ['nullable', 'string', 'max:512'],
            'utm.term' => ['nullable', 'string', 'max:512'],
            'utm.content' => ['nullable', 'string', 'max:512'],
            'marketing_consent' => ['nullable', 'boolean'],
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

    /**
     * @return array<string,string>|null
     */
    private function normalizeUtm(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $normalized = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $candidate = $this->normalizeString($value[$key] ?? null, 512);
            if ($candidate !== null) {
                $normalized[$key] = $candidate;
            }
        }

        return $normalized === [] ? null : $normalized;
    }
}
