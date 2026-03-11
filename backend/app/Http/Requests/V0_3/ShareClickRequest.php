<?php

namespace App\Http\Requests\V0_3;

use App\Exceptions\Api\ApiProblemException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShareClickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $metaJson = $this->normalizeMeta($this->input('meta_json'));
        $meta = $this->normalizeMeta($this->input('meta'));
        $mergedMeta = array_replace_recursive($metaJson, $meta);

        $normalizedUtm = $this->normalizeUtm($this->input('utm'));
        if ($normalizedUtm !== null) {
            $existingUtm = $this->normalizeUtm($mergedMeta['utm'] ?? null) ?? [];
            $mergedMeta['utm'] = array_replace($existingUtm, $normalizedUtm);
        } elseif (isset($mergedMeta['utm'])) {
            $mergedMeta['utm'] = $this->normalizeUtm($mergedMeta['utm']) ?? [];
        }

        foreach ([
            'entrypoint' => 128,
            'referrer' => 2048,
            'landing_path' => 2048,
        ] as $field => $maxLength) {
            $value = $this->normalizeString($this->input($field), $maxLength);
            if ($value !== null) {
                $mergedMeta[$field] = $value;
            }
        }

        if ($this->has('compare_intent')) {
            $mergedMeta['compare_intent'] = $this->boolean('compare_intent');
        } elseif (array_key_exists('compare_intent', $mergedMeta)) {
            $mergedMeta['compare_intent'] = filter_var(
                $mergedMeta['compare_intent'],
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }

        $mergedMeta = $this->filterMeta($mergedMeta);

        $this->merge([
            'meta_json' => $mergedMeta,
        ]);
    }

    public function rules(): array
    {
        return [
            'anon_id' => ['nullable', 'string', 'max:128'],
            'occurred_at' => ['nullable', 'date'],
            'meta_json' => ['nullable', 'array'],
            'meta' => ['nullable'],
            'ref' => ['nullable', 'string', 'max:1024'],
            'ua' => ['nullable', 'string', 'max:1024'],
            'ip' => ['nullable', 'string', 'max:64'],
            'utm' => ['nullable'],
            'experiment' => ['nullable', 'string', 'max:64'],
            'version' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:2048'],
            'trace_id' => ['nullable', 'string', 'max:128'],
            'entrypoint' => ['nullable', 'string', 'max:128'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'landing_path' => ['nullable', 'string', 'max:2048'],
            'compare_intent' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->assertPayloadSize();
            $this->assertMetaSize();
        });
    }

    private function assertPayloadSize(): void
    {
        $raw = (string) $this->getContent();
        $len = strlen($raw);
        $max = (int) config('security_limits.public_event_max_payload_bytes', 16384);

        if ($max > 0 && $len > $max) {
            throw new ApiProblemException(413, 'PAYLOAD_TOO_LARGE', 'payload too large', [
                'max_bytes' => $max,
                'len_bytes' => $len,
            ]);
        }
    }

    private function assertMetaSize(): void
    {
        $meta = $this->input('meta_json');
        $meta = is_array($meta) ? $meta : [];

        $maxKeys = (int) config('security_limits.public_event_meta_max_keys', 50);
        $maxBytes = (int) config('security_limits.public_event_meta_max_bytes', 4096);

        $keysCount = count($meta);
        $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $metaBytes = is_string($metaJson) ? strlen($metaJson) : PHP_INT_MAX;

        if (($maxKeys > 0 && $keysCount > $maxKeys) || ($maxBytes > 0 && $metaBytes > $maxBytes)) {
            throw new ApiProblemException(413, 'META_TOO_LARGE', 'meta too large');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMeta(mixed $value): array
    {
        return is_array($value) ? $value : [];
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
