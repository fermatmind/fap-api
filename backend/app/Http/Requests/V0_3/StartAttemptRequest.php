<?php

namespace App\Http\Requests\V0_3;

use App\Services\Analytics\AnalyticsTrafficExclusionPolicy;
use Illuminate\Foundation\Http\FormRequest;

class StartAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $meta = $this->input('meta');
        $meta = is_array($meta) ? $meta : [];
        $formCode = $this->normalizeString(
            $this->input('form_code', $this->input('form')),
            64
        );
        $anonId = $this->normalizePublicAnonId($this->input('anon_id'), 64);

        $normalizedUtm = $this->normalizeUtm($this->input('utm'));
        $flatUtm = $this->normalizeFlatUtm();
        if ($normalizedUtm !== null || $flatUtm !== null) {
            $existingUtm = $this->normalizeUtm($meta['utm'] ?? null) ?? [];
            $meta['utm'] = array_replace($existingUtm, $normalizedUtm ?? [], $flatUtm ?? []);
        }

        foreach ([
            'share_id' => 128,
            'compare_invite_id' => 128,
            'invite_unlock_code' => 64,
            'share_click_id' => 128,
            'entrypoint' => 128,
            'entry_surface' => 128,
            'source_page_type' => 64,
            'source_slug' => 128,
            'target_action' => 128,
            'test_slug' => 128,
            'landing_path' => 2048,
        ] as $field => $maxLength) {
            $value = $this->normalizeString($this->input($field), $maxLength);
            if ($value !== null) {
                $meta[$field] = $value;
            }
        }

        $contentId = filter_var($this->input('content_id'), FILTER_VALIDATE_INT);
        if (is_int($contentId) && $contentId > 0) {
            $meta['content_id'] = $contentId;
        }

        $referrer = $this->normalizeString($this->input('referrer'), 255);
        if ($referrer !== null) {
            $this->merge([
                'referrer' => $referrer,
            ]);
            $meta['referrer'] = $referrer;
        }

        $merged = [
            'meta' => $this->filterMeta($meta),
        ];
        if ($formCode !== null) {
            $merged['form_code'] = $formCode;
        }
        if ($anonId !== null || $this->has('anon_id')) {
            $merged['anon_id'] = $anonId;
        }

        $this->merge($merged);
    }

    public function rules(): array
    {
        return [
            'scale_code' => ['required', 'string', 'max:64'],
            'region' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', 'max:16'],
            'anon_id' => ['nullable', 'string', 'max:64'],
            'client_platform' => ['nullable', 'string', 'max:32'],
            'client_version' => ['nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'string', 'max:32'],
            'referrer' => ['nullable', 'string', 'max:255'],
            'form_code' => ['nullable', 'string', 'max:64'],
            'resume_token' => ['nullable', 'string', 'max:128'],
            'meta' => ['sometimes', 'array'],
            'share_id' => ['nullable', 'string', 'max:128'],
            'compare_invite_id' => ['nullable', 'string', 'max:128'],
            'invite_unlock_code' => ['nullable', 'string', 'max:64'],
            'share_click_id' => ['nullable', 'string', 'max:128'],
            'entrypoint' => ['nullable', 'string', 'max:128'],
            'entry_surface' => ['nullable', 'string', 'max:128'],
            'source_page_type' => ['nullable', 'string', 'max:64'],
            'source_slug' => ['nullable', 'string', 'max:128', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'content_id' => ['nullable', 'integer', 'min:1'],
            'target_action' => ['nullable', 'string', 'max:128'],
            'test_slug' => ['nullable', 'string', 'max:128'],
            'landing_path' => ['nullable', 'string', 'max:2048'],
            'utm' => ['nullable'],
            'utm_source' => ['nullable', 'string', 'max:512'],
            'utm_medium' => ['nullable', 'string', 'max:512'],
            'utm_campaign' => ['nullable', 'string', 'max:512'],
            'utm_term' => ['nullable', 'string', 'max:512'],
            'utm_content' => ['nullable', 'string', 'max:512'],
            'consent' => ['sometimes', 'array'],
            'consent.accepted' => ['sometimes', 'boolean'],
            'consent.version' => ['sometimes', 'string', 'max:128'],
            'consent.hash' => ['sometimes', 'string', 'size:64'],
            'consent.locale' => ['nullable', 'string', 'max:16'],
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

    private function normalizePublicAnonId(mixed $value, int $maxLength): ?string
    {
        $normalized = $this->normalizeString($value, $maxLength);
        if ($normalized === null) {
            return null;
        }

        $exclusionPolicy = app(AnalyticsTrafficExclusionPolicy::class);
        if ($exclusionPolicy->hasExcludedProbePrefix($normalized)
            && ! $exclusionPolicy->allowsTrustedStagingProbe($this, $normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function filterMeta(array $meta): array
    {
        $filtered = [];

        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $value = $this->filterMeta($value);
                if ($value === []) {
                    continue;
                }

                $filtered[$key] = $value;

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }
}
