<?php

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;

class SubmitAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalizedUtm = $this->normalizeUtm($this->input('utm'));
        $flatUtm = $this->normalizeFlatUtm();
        if ($normalizedUtm !== null || $flatUtm !== null) {
            $existingUtm = $this->normalizeUtm($this->input('utm')) ?? [];
            $this->merge([
                'utm' => array_replace($existingUtm, $normalizedUtm ?? [], $flatUtm ?? []),
            ]);
        }

        foreach ([
            'share_id' => 128,
            'compare_invite_id' => 128,
            'share_click_id' => 128,
            'entrypoint' => 128,
            'referrer' => 2048,
            'landing_path' => 2048,
        ] as $field => $maxLength) {
            $value = $this->normalizeString($this->input($field), $maxLength);
            if ($value !== null) {
                $this->merge([$field => $value]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'attempt_id' => ['required', 'string', 'max:64'],
            'anon_id' => ['nullable', 'string', 'max:191'],
            'answers' => ['nullable', 'array'],
            'answers.*.question_id' => ['required_with:answers', 'string', 'max:128'],
            // Keep submit contract scale-agnostic; scale-specific answer validation
            // belongs to the scorer layer to avoid breaking legacy answer formats.
            'answers.*.code' => ['nullable', 'string', 'max:128'],
            'answers.*.question_type' => ['nullable', 'string', 'max:32'],
            'answers.*.question_index' => ['nullable', 'integer', 'min:0'],
            'validity_items' => ['nullable', 'array'],
            'validity_items.*.item_id' => ['required_with:validity_items', 'string', 'max:64'],
            'validity_items.*.code' => ['required_with:validity_items'],
            'duration_ms' => ['required', 'integer', 'min:0'],
            'consent' => ['sometimes', 'array'],
            'consent.accepted' => ['sometimes', 'boolean'],
            'consent.version' => ['sometimes', 'string', 'max:128'],
            'consent.hash' => ['sometimes', 'string', 'size:64'],
            'invite_token' => ['nullable', 'string', 'max:64'],
            'share_id' => ['nullable', 'string', 'max:128'],
            'compare_invite_id' => ['nullable', 'string', 'max:128'],
            'share_click_id' => ['nullable', 'string', 'max:128'],
            'entrypoint' => ['nullable', 'string', 'max:128'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'landing_path' => ['nullable', 'string', 'max:2048'],
            'utm' => ['nullable'],
            'utm_source' => ['nullable', 'string', 'max:512'],
            'utm_medium' => ['nullable', 'string', 'max:512'],
            'utm_campaign' => ['nullable', 'string', 'max:512'],
            'utm_term' => ['nullable', 'string', 'max:512'],
            'utm_content' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * @return array<string, string>|null
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

    /**
     * @return array<string, string>|null
     */
    private function normalizeFlatUtm(): ?array
    {
        $normalized = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $candidate = $this->normalizeString($this->input('utm_'.$key), 512);
            if ($candidate !== null) {
                $normalized[$key] = $candidate;
            }
        }

        return $normalized === [] ? null : $normalized;
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
