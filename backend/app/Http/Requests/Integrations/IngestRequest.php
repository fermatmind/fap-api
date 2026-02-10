<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class IngestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $meta = $this->input('meta', []);
        if (!is_array($meta)) {
            $meta = [];
        }

        if (!array_key_exists('range_start', $meta) && $this->has('range_start')) {
            $meta['range_start'] = $this->input('range_start');
        }
        if (!array_key_exists('range_end', $meta) && $this->has('range_end')) {
            $meta['range_end'] = $this->input('range_end');
        }

        $this->merge([
            'meta' => $meta,
            'external_user_id' => trim((string) $this->input('external_user_id', '')),
        ]);
    }

    public function rules(): array
    {
        $maxSamples = max(1, (int) config('integrations.ingest.max_samples', 500));

        return [
            'meta' => ['required', 'array'],
            'meta.range_start' => ['required', 'date'],
            'meta.range_end' => ['nullable', 'date'],
            'external_user_id' => ['nullable', 'string', 'max:191'],

            'samples' => ['required', 'array', "max:{$maxSamples}"],
            'samples.*' => ['required', 'array'],
            'samples.*.domain' => ['required', 'string', 'max:64'],
            'samples.*.recorded_at' => ['required', 'date'],
            'samples.*.value' => ['required'],
            'samples.*.external_id' => ['nullable', 'string', 'max:128'],
            'samples.*.source' => ['nullable', 'string', 'max:64'],
            'samples.*.confidence' => ['nullable', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $authMode = (string) $this->attributes->get('integration_auth_mode', '');
            if ($authMode === 'signature') {
                $externalUserId = trim((string) $this->input('external_user_id', ''));
                if ($externalUserId === '') {
                    $validator->errors()->add('external_user_id', 'external_user_id is required for signature mode.');
                }
            }

            $maxDepth = max(1, (int) config('integrations.ingest.max_value_depth', 8));
            $maxBytes = max(1, (int) config('integrations.ingest.max_value_bytes', 8192));
            $samples = $this->input('samples', []);
            if (!is_array($samples)) {
                return;
            }

            foreach ($samples as $i => $sample) {
                if (!is_array($sample) || !array_key_exists('value', $sample)) {
                    continue;
                }

                $value = $sample['value'];
                $depth = $this->depth($value);
                if ($depth > $maxDepth) {
                    $validator->errors()->add("samples.{$i}.value", "value depth must be <= {$maxDepth}.");
                }

                $bytes = $this->jsonBytes($value);
                if ($bytes > $maxBytes) {
                    $validator->errors()->add("samples.{$i}.value", "value bytes must be <= {$maxBytes}.");
                }
            }
        });
    }

    private function depth($value, int $depth = 0): int
    {
        if (!is_array($value)) {
            return $depth;
        }

        $max = $depth;
        foreach ($value as $child) {
            $max = max($max, $this->depth($child, $depth + 1));
        }

        return $max;
    }

    private function jsonBytes($value): int
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            return 0;
        }

        return strlen($encoded);
    }
}
