<?php

declare(strict_types=1);

namespace App\Services\Enneagram\Assets;

final class EnneagramAssetMergePolicyValidator
{
    public const BATCH_A_CATEGORIES = [
        'page1_summary',
        'type_summary',
        'deep_dive_intro',
        'misread_clarification',
        'counterexample_still_valid',
        'low_resonance_response',
        'partial_resonance_response',
    ];

    public const BATCH_A_REPLACE_CATEGORIES = [
        'page1_summary',
        'type_summary',
        'deep_dive_intro',
    ];

    public const BATCH_B_DEEP_CORE_CATEGORIES = [
        'core_motivation',
        'core_fear',
        'core_desire',
        'self_image',
        'attention_pattern',
        'strength',
        'blindspot',
        'stress_pattern',
        'relationship_pattern',
        'work_pattern',
        'growth_direction',
        'daily_observation',
        'boundary',
    ];

    /**
     * @param  array<string,mixed>  $stream
     * @return list<string>
     */
    public function validateSingle(array $stream): array
    {
        $metadata = is_array($stream['metadata'] ?? null) ? $stream['metadata'] : [];
        $items = is_array($stream['items'] ?? null) ? $stream['items'] : [];
        $version = (string) ($metadata['version'] ?? $metadata['batch'] ?? $metadata['batch_name'] ?? '');
        $mode = (string) data_get($metadata, 'replacement_policy.mode', '');
        $policy = (string) ($metadata['import_policy'] ?? '');
        $errors = [];

        $normalizedPolicy = strtolower($policy);
        $policyAllowsFullReplacement = str_contains($normalizedPolicy, 'full_replacement')
            && ! str_contains($normalizedPolicy, 'do_not_import_as_full_replacement')
            && ! str_contains($normalizedPolicy, 'blocked_as_full_replacement');

        if (str_contains(strtolower($mode), 'full') || $policyAllowsFullReplacement) {
            $errors[] = 'full_replacement_blocked';
        }

        if ($this->isBatchA($version, $items)) {
            if ($mode !== 'partial_override') {
                $errors[] = 'batch_1r_a_requires_partial_override_mode';
            }
            $categories = $this->categories($items);
            foreach ($categories as $category) {
                if (! in_array($category, self::BATCH_A_CATEGORIES, true)) {
                    $errors[] = 'batch_1r_a_unknown_category:'.$category;
                }
            }
        }

        if ($this->isBatchB($version, $items)) {
            if ($mode !== 'legacy_core_rewrite') {
                $errors[] = 'batch_1r_b_requires_legacy_core_rewrite_mode';
            }
            $missing = array_values(array_diff(self::BATCH_B_DEEP_CORE_CATEGORIES, $this->categories($items)));
            foreach ($missing as $category) {
                $errors[] = 'batch_1r_b_missing_deep_core_category:'.$category;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string,mixed>  $batchA
     * @param  array<string,mixed>  $batchB
     * @return list<string>
     */
    public function validatePair(array $batchA, array $batchB): array
    {
        $errors = [];
        $errors = array_merge($errors, $this->validateSingle($batchA), $this->validateSingle($batchB));

        $aCategories = $this->categories((array) ($batchA['items'] ?? []));
        $bCategories = $this->categories((array) ($batchB['items'] ?? []));
        $overlap = array_values(array_intersect($aCategories, $bCategories));
        if ($overlap !== []) {
            $errors[] = 'batch_1r_a_1r_b_category_overlap_blocked:'.implode(',', $overlap);
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    private function categories(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['category'] ?? '')),
            $items
        ))));
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function isBatchA(string $version, array $items): bool
    {
        return str_contains($version, '1R-A') || str_contains($version, 'v6') || in_array('page1_summary', $this->categories($items), true);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function isBatchB(string $version, array $items): bool
    {
        return str_contains($version, '1R-B') || in_array('core_motivation', $this->categories($items), true);
    }
}
