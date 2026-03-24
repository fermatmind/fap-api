<?php

declare(strict_types=1);

namespace App\Http\Requests\V0_3;

use Illuminate\Foundation\Http\FormRequest;

final class BigFiveOpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'region' => ['sometimes', 'string', 'max:64', 'regex:/\A(?!\.\.)[A-Za-z0-9_-]+\z/'],
            'locale' => ['sometimes', 'string', 'max:32', 'regex:/\A(?!\.\.)[A-Za-z0-9_-]+\z/'],
            'action' => ['sometimes', 'string', 'max:64'],
            'release_action' => ['sometimes', 'string', 'max:64'],
            'result' => ['sometimes', 'string', 'max:32'],
            'release_id' => ['sometimes', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],

            'dir_alias' => ['sometimes', 'string', 'max:128', 'regex:/\A(?!\.\.)[A-Za-z0-9_-]+\z/'],
            'pack' => ['sometimes', 'string', 'max:128', 'regex:/\A(?!\.\.)[A-Za-z0-9_-]+\z/'],
            'pack_version' => ['sometimes', 'string', 'max:128', 'regex:/\A(?!\.\.)[A-Za-z0-9._-]+\z/'],
            'probe' => ['sometimes', 'boolean'],
            'skip_drift' => ['sometimes', 'boolean'],
            'base_url' => ['sometimes', 'string', 'max:512'],
            'to_release_id' => ['sometimes', 'string', 'max:128'],

            'drift_from' => ['sometimes', 'string', 'max:128'],
            'drift_to' => ['sometimes', 'string', 'max:128'],
            'drift_group_id' => ['sometimes', 'string', 'max:128'],
            'drift_threshold_mean' => ['sometimes', 'string', 'max:32'],
            'drift_threshold_sd' => ['sometimes', 'string', 'max:32'],

            'group' => ['sometimes', 'string', 'max:128'],
            'group_id' => ['sometimes', 'string', 'max:128'],
            'gender' => ['sometimes', 'string', 'max:16'],
            'age_min' => ['sometimes', 'integer', 'min:1'],
            'age_max' => ['sometimes', 'integer', 'min:1'],
            'window_days' => ['sometimes', 'integer', 'min:1'],
            'min_samples' => ['sometimes', 'integer', 'min:1'],
            'only_quality' => ['sometimes', 'string', 'max:16'],
            'norms_version' => ['sometimes', 'string', 'max:128'],
            'activate' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],

            'from' => ['sometimes', 'string', 'max:128'],
            'to' => ['sometimes', 'string', 'max:128'],
            'threshold_mean' => ['sometimes', 'numeric'],
            'threshold_sd' => ['sometimes', 'numeric'],
        ];
    }
}
