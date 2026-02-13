<?php

namespace App\Http\Requests\V0_2;

use App\Exceptions\Api\ApiProblemException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ShareClickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'anon_id' => ['nullable', 'string', 'max:128'],
            'occurred_at' => ['nullable', 'date'],
            'meta_json' => ['nullable', 'array'],
            'ref' => ['nullable', 'string', 'max:1024'],
            'ua' => ['nullable', 'string', 'max:1024'],
            'ip' => ['nullable', 'string', 'max:64'],
            'utm' => ['nullable'],
            'experiment' => ['nullable', 'string', 'max:64'],
            'version' => ['nullable', 'string', 'max:64'],
            'url' => ['nullable', 'string', 'max:2048'],
            'trace_id' => ['nullable', 'string', 'max:128'],
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
}
