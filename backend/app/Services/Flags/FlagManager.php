<?php

namespace App\Services\Flags;

use App\Models\FeatureFlag;
use App\Support\StableBucket;
use Illuminate\Support\Facades\Schema;

class FlagManager
{
    private string $salt;

    public function __construct()
    {
        $this->salt = (string) config('fap_experiments.salt', '');
    }

    public function resolve(int $orgId, ?int $userId, ?string $anonId): array
    {
        if (!\App\Support\SchemaBaseline::hasTable('feature_flags')) {
            return [];
        }

        $flags = [];
        $rows = FeatureFlag::query()->where('is_active', true)->get();
        foreach ($rows as $row) {
            $key = trim((string) ($row->key ?? ''));
            if ($key === '') {
                continue;
            }

            $rules = $row->rules_json ?? null;
            if (is_string($rules)) {
                $decoded = json_decode($rules, true);
                $rules = is_array($decoded) ? $decoded : null;
            }

            $flags[$key] = $this->evaluateRules($rules, $key, $orgId, $userId, $anonId);
        }

        return $flags;
    }

    private function evaluateRules($rules, string $flagKey, int $orgId, ?int $userId, ?string $anonId)
    {
        if (!is_array($rules) || $rules === []) {
            return false;
        }

        $default = $rules['default'] ?? false;
        $rulesList = $rules['rules'] ?? [];
        if (!is_array($rulesList)) {
            $rulesList = [];
        }

        $subjectKey = $this->subjectKey($userId, $anonId);

        foreach ($rulesList as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $when = $rule['when'] ?? [];
            if (!is_array($when)) {
                $when = [];
            }

            if (!$this->matchesWhen($when, $flagKey, $subjectKey, $orgId, $userId, $anonId)) {
                continue;
            }

            if (array_key_exists('value', $rule)) {
                return $rule['value'];
            }

            return true;
        }

        return $default;
    }

    private function matchesWhen(array $when, string $flagKey, string $subjectKey, int $orgId, ?int $userId, ?string $anonId): bool
    {
        if (array_key_exists('org_id', $when) && !$this->matchesId($when['org_id'], $orgId)) {
            return false;
        }

        if (array_key_exists('user_id', $when) && !$this->matchesId($when['user_id'], $userId)) {
            return false;
        }

        if (array_key_exists('anon_id', $when) && !$this->matchesString($when['anon_id'], $anonId)) {
            return false;
        }

        if (array_key_exists('percent', $when)) {
            $percent = $this->normalizePercent($when['percent']);
            if ($percent <= 0) {
                return false;
            }
            if ($percent < 100) {
                $bucket = StableBucket::bucket($subjectKey . '|' . $flagKey . '|' . $this->salt, 100);
                if ($bucket >= $percent) {
                    return false;
                }
            }
        }

        return true;
    }

    private function subjectKey(?int $userId, ?string $anonId): string
    {
        if ($userId !== null) {
            return 'user:' . $userId;
        }

        $anonId = trim((string) ($anonId ?? ''));
        if ($anonId !== '') {
            return 'anon:' . $anonId;
        }

        return 'anon:missing';
    }

    private function matchesId($ruleValue, $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        if (is_array($ruleValue)) {
            foreach ($ruleValue as $item) {
                if ($this->matchesId($item, $candidate)) {
                    return true;
                }
            }
            return false;
        }

        if (!is_numeric($ruleValue)) {
            return false;
        }

        return (int) $ruleValue === (int) $candidate;
    }

    private function matchesString($ruleValue, ?string $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        $candidate = trim($candidate);
        if ($candidate === '') {
            return false;
        }

        if (is_array($ruleValue)) {
            foreach ($ruleValue as $item) {
                if ($this->matchesString($item, $candidate)) {
                    return true;
                }
            }
            return false;
        }

        if (!is_string($ruleValue) && !is_numeric($ruleValue)) {
            return false;
        }

        return trim((string) $ruleValue) === $candidate;
    }

    private function normalizePercent($value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $percent = (int) round((float) $value);
        if ($percent < 0) {
            return 0;
        }
        if ($percent > 100) {
            return 100;
        }

        return $percent;
    }
}
