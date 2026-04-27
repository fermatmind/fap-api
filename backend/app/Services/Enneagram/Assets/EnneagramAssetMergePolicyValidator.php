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

    public const BATCH_C_CATEGORIES = [
        'low_resonance_response',
    ];

    public const BATCH_D_CATEGORIES = [
        'partial_resonance_response',
    ];

    public const BATCH_E_CATEGORIES = [
        'diffuse_convergence_response',
    ];

    public const BATCH_F_CATEGORIES = [
        'close_call_pair',
    ];

    public const BATCH_G_CATEGORIES = [
        'scene_localization_response',
    ];

    public const BATCH_H_CATEGORIES = [
        'fc144_recommendation_response',
    ];

    public const BATCH_G_SCENE_AXES = [
        'student_group_project',
        'student_exam_pressure',
        'student_dorm_relationship',
        'student_club_collaboration',
        'student_thesis_paper',
        'student_job_search',
        'early_career_internship',
        'early_career_probation',
        'early_career_reporting',
        'early_career_cross_team',
        'early_career_kpi_feedback',
        'work_leader_changes_requirements',
        'work_colleague_blame_shift',
        'relationship_intimacy',
        'relationship_family_expectation',
        'relationship_no_reply',
        'relationship_cold_war',
        'relationship_conflict_repair',
    ];

    public const BATCH_H_RECOMMENDATION_CONTEXTS = [
        'clear_high_resonance',
        'close_call_top2',
        'diffuse_top3',
        'low_quality',
        'low_resonance',
        'partial_resonance',
        'after_pair_comparison',
        'after_scene_localization',
        'high_engagement_deep_reader',
        'paid_preview_teaser',
    ];

    private const BATCH_H_BANNED_COPY_PHRASES = [
        'FC144 更准确',
        '更准确',
        '最终判型',
        '终极判型',
        '第二套结果页',
        '第二套产品',
        'E105 和 FC144 分数可比较',
        '分数可比较',
        '直接比较分数',
        '重新判型',
        '确认最终类型',
        '诊断',
        '招聘',
        '准确率',
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

        if ($this->isBatchC($version, $items)) {
            if ($mode !== 'additive_branch_expansion') {
                $errors[] = 'batch_1r_c_requires_additive_branch_expansion_mode';
            }
            $categories = $this->categories($items);
            foreach ($categories as $category) {
                if (! in_array($category, self::BATCH_C_CATEGORIES, true)) {
                    $errors[] = 'batch_1r_c_unknown_category:'.$category;
                }
            }
        }

        if ($this->isBatchD($version, $items)) {
            if ($mode !== 'additive_branch_expansion') {
                $errors[] = 'batch_1r_d_requires_additive_branch_expansion_mode';
            }
            $categories = $this->categories($items);
            foreach ($categories as $category) {
                if (! in_array($category, self::BATCH_D_CATEGORIES, true)) {
                    $errors[] = 'batch_1r_d_unknown_category:'.$category;
                }
            }
        }

        if ($this->isBatchE($version, $items)) {
            if ($mode !== 'additive_branch_expansion') {
                $errors[] = 'batch_1r_e_requires_additive_branch_expansion_mode';
            }
            $categories = $this->categories($items);
            foreach ($categories as $category) {
                if (! in_array($category, self::BATCH_E_CATEGORIES, true)) {
                    $errors[] = 'batch_1r_e_unknown_category:'.$category;
                }
            }
        }

        if ($this->isBatchF($version, $items)) {
            if ($mode !== 'pair_library_completion') {
                $errors[] = 'batch_1r_f_requires_pair_library_completion_mode';
            }
            $categories = $this->categories($items);
            foreach ($categories as $category) {
                if (! in_array($category, self::BATCH_F_CATEGORIES, true)) {
                    $errors[] = 'batch_1r_f_unknown_category:'.$category;
                }
            }

            $errors = array_merge($errors, $this->validatePairLibrary($items));
        }

        if ($this->isBatchG($version, $items)) {
            if ($mode !== 'additive_branch_expansion') {
                $errors[] = 'batch_1r_g_requires_additive_branch_expansion_mode';
            }
            $categories = $this->categories($items);
            foreach ($categories as $category) {
                if (! in_array($category, self::BATCH_G_CATEGORIES, true)) {
                    $errors[] = 'batch_1r_g_unknown_category:'.$category;
                }
            }

            $errors = array_merge($errors, $this->validateSceneLocalization($items));
        }

        if ($this->isBatchH($version, $items)) {
            if ($mode !== 'additive_branch_expansion') {
                $errors[] = 'batch_1r_h_requires_additive_branch_expansion_mode';
            }
            $categories = $this->categories($items);
            foreach ($categories as $category) {
                if (! in_array($category, self::BATCH_H_CATEGORIES, true)) {
                    $errors[] = 'batch_1r_h_unknown_category:'.$category;
                }
            }

            $errors = array_merge($errors, $this->validateFc144Recommendation($items));
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
        return $this->validateStreams($batchA, $batchB);
    }

    /**
     * @param  array<int,array{metadata?:array<string,mixed>,items?:list<array<string,mixed>>}>  $streams
     * @return list<string>
     */
    public function validateStreams(array ...$streams): array
    {
        $errors = [];
        foreach ($streams as $stream) {
            $errors = array_merge($errors, $this->validateSingle($stream));
        }

        $categoriesByBatch = [];
        foreach ($streams as $stream) {
            $items = (array) ($stream['items'] ?? []);
            $batchKey = $this->detectBatchKey((array) data_get($stream, 'metadata', []), $items);
            if ($batchKey === '') {
                continue;
            }
            $categoriesByBatch[$batchKey] = $this->categories($items);
        }

        $overlapAB = array_values(array_intersect(
            $categoriesByBatch['1R-A'] ?? [],
            $categoriesByBatch['1R-B'] ?? []
        ));
        if ($overlapAB !== []) {
            $errors[] = 'batch_1r_a_1r_b_category_overlap_blocked:'.implode(',', $overlapAB);
        }

        $unexpectedAC = array_values(array_diff(
            array_intersect($categoriesByBatch['1R-A'] ?? [], $categoriesByBatch['1R-C'] ?? []),
            self::BATCH_C_CATEGORIES
        ));
        if ($unexpectedAC !== []) {
            $errors[] = 'batch_1r_a_1r_c_category_overlap_blocked:'.implode(',', $unexpectedAC);
        }

        $unexpectedBC = array_values(array_diff(
            array_intersect($categoriesByBatch['1R-B'] ?? [], $categoriesByBatch['1R-C'] ?? []),
            self::BATCH_C_CATEGORIES
        ));
        if ($unexpectedBC !== []) {
            $errors[] = 'batch_1r_b_1r_c_category_overlap_blocked:'.implode(',', $unexpectedBC);
        }

        $unexpectedAD = array_values(array_diff(
            array_intersect($categoriesByBatch['1R-A'] ?? [], $categoriesByBatch['1R-D'] ?? []),
            self::BATCH_D_CATEGORIES
        ));
        if ($unexpectedAD !== []) {
            $errors[] = 'batch_1r_a_1r_d_category_overlap_blocked:'.implode(',', $unexpectedAD);
        }

        $unexpectedBD = array_values(array_diff(
            array_intersect($categoriesByBatch['1R-B'] ?? [], $categoriesByBatch['1R-D'] ?? []),
            self::BATCH_D_CATEGORIES
        ));
        if ($unexpectedBD !== []) {
            $errors[] = 'batch_1r_b_1r_d_category_overlap_blocked:'.implode(',', $unexpectedBD);
        }

        $unexpectedCD = array_values(array_intersect(
            $categoriesByBatch['1R-C'] ?? [],
            $categoriesByBatch['1R-D'] ?? []
        ));
        if ($unexpectedCD !== []) {
            $errors[] = 'batch_1r_c_1r_d_category_overlap_blocked:'.implode(',', $unexpectedCD);
        }

        $unexpectedAE = array_values(array_diff(
            array_intersect($categoriesByBatch['1R-A'] ?? [], $categoriesByBatch['1R-E'] ?? []),
            self::BATCH_E_CATEGORIES
        ));
        if ($unexpectedAE !== []) {
            $errors[] = 'batch_1r_a_1r_e_category_overlap_blocked:'.implode(',', $unexpectedAE);
        }

        $unexpectedBE = array_values(array_diff(
            array_intersect($categoriesByBatch['1R-B'] ?? [], $categoriesByBatch['1R-E'] ?? []),
            self::BATCH_E_CATEGORIES
        ));
        if ($unexpectedBE !== []) {
            $errors[] = 'batch_1r_b_1r_e_category_overlap_blocked:'.implode(',', $unexpectedBE);
        }

        $unexpectedCE = array_values(array_intersect(
            $categoriesByBatch['1R-C'] ?? [],
            $categoriesByBatch['1R-E'] ?? []
        ));
        if ($unexpectedCE !== []) {
            $errors[] = 'batch_1r_c_1r_e_category_overlap_blocked:'.implode(',', $unexpectedCE);
        }

        $unexpectedDE = array_values(array_intersect(
            $categoriesByBatch['1R-D'] ?? [],
            $categoriesByBatch['1R-E'] ?? []
        ));
        if ($unexpectedDE !== []) {
            $errors[] = 'batch_1r_d_1r_e_category_overlap_blocked:'.implode(',', $unexpectedDE);
        }

        $unexpectedAF = array_values(array_intersect(
            $categoriesByBatch['1R-A'] ?? [],
            $categoriesByBatch['1R-F'] ?? []
        ));
        if ($unexpectedAF !== []) {
            $errors[] = 'batch_1r_a_1r_f_category_overlap_blocked:'.implode(',', $unexpectedAF);
        }

        $unexpectedBF = array_values(array_intersect(
            $categoriesByBatch['1R-B'] ?? [],
            $categoriesByBatch['1R-F'] ?? []
        ));
        if ($unexpectedBF !== []) {
            $errors[] = 'batch_1r_b_1r_f_category_overlap_blocked:'.implode(',', $unexpectedBF);
        }

        $unexpectedCF = array_values(array_intersect(
            $categoriesByBatch['1R-C'] ?? [],
            $categoriesByBatch['1R-F'] ?? []
        ));
        if ($unexpectedCF !== []) {
            $errors[] = 'batch_1r_c_1r_f_category_overlap_blocked:'.implode(',', $unexpectedCF);
        }

        $unexpectedDF = array_values(array_intersect(
            $categoriesByBatch['1R-D'] ?? [],
            $categoriesByBatch['1R-F'] ?? []
        ));
        if ($unexpectedDF !== []) {
            $errors[] = 'batch_1r_d_1r_f_category_overlap_blocked:'.implode(',', $unexpectedDF);
        }

        $unexpectedEF = array_values(array_intersect(
            $categoriesByBatch['1R-E'] ?? [],
            $categoriesByBatch['1R-F'] ?? []
        ));
        if ($unexpectedEF !== []) {
            $errors[] = 'batch_1r_e_1r_f_category_overlap_blocked:'.implode(',', $unexpectedEF);
        }

        $unexpectedAG = array_values(array_intersect(
            $categoriesByBatch['1R-A'] ?? [],
            $categoriesByBatch['1R-G'] ?? []
        ));
        if ($unexpectedAG !== []) {
            $errors[] = 'batch_1r_a_1r_g_category_overlap_blocked:'.implode(',', $unexpectedAG);
        }

        $unexpectedBG = array_values(array_intersect(
            $categoriesByBatch['1R-B'] ?? [],
            $categoriesByBatch['1R-G'] ?? []
        ));
        if ($unexpectedBG !== []) {
            $errors[] = 'batch_1r_b_1r_g_category_overlap_blocked:'.implode(',', $unexpectedBG);
        }

        $unexpectedCG = array_values(array_intersect(
            $categoriesByBatch['1R-C'] ?? [],
            $categoriesByBatch['1R-G'] ?? []
        ));
        if ($unexpectedCG !== []) {
            $errors[] = 'batch_1r_c_1r_g_category_overlap_blocked:'.implode(',', $unexpectedCG);
        }

        $unexpectedDG = array_values(array_intersect(
            $categoriesByBatch['1R-D'] ?? [],
            $categoriesByBatch['1R-G'] ?? []
        ));
        if ($unexpectedDG !== []) {
            $errors[] = 'batch_1r_d_1r_g_category_overlap_blocked:'.implode(',', $unexpectedDG);
        }

        $unexpectedEG = array_values(array_intersect(
            $categoriesByBatch['1R-E'] ?? [],
            $categoriesByBatch['1R-G'] ?? []
        ));
        if ($unexpectedEG !== []) {
            $errors[] = 'batch_1r_e_1r_g_category_overlap_blocked:'.implode(',', $unexpectedEG);
        }

        $unexpectedFG = array_values(array_intersect(
            $categoriesByBatch['1R-F'] ?? [],
            $categoriesByBatch['1R-G'] ?? []
        ));
        if ($unexpectedFG !== []) {
            $errors[] = 'batch_1r_f_1r_g_category_overlap_blocked:'.implode(',', $unexpectedFG);
        }

        $unexpectedAH = array_values(array_intersect(
            $categoriesByBatch['1R-A'] ?? [],
            $categoriesByBatch['1R-H'] ?? []
        ));
        if ($unexpectedAH !== []) {
            $errors[] = 'batch_1r_a_1r_h_category_overlap_blocked:'.implode(',', $unexpectedAH);
        }

        $unexpectedBH = array_values(array_intersect(
            $categoriesByBatch['1R-B'] ?? [],
            $categoriesByBatch['1R-H'] ?? []
        ));
        if ($unexpectedBH !== []) {
            $errors[] = 'batch_1r_b_1r_h_category_overlap_blocked:'.implode(',', $unexpectedBH);
        }

        $unexpectedCH = array_values(array_intersect(
            $categoriesByBatch['1R-C'] ?? [],
            $categoriesByBatch['1R-H'] ?? []
        ));
        if ($unexpectedCH !== []) {
            $errors[] = 'batch_1r_c_1r_h_category_overlap_blocked:'.implode(',', $unexpectedCH);
        }

        $unexpectedDH = array_values(array_intersect(
            $categoriesByBatch['1R-D'] ?? [],
            $categoriesByBatch['1R-H'] ?? []
        ));
        if ($unexpectedDH !== []) {
            $errors[] = 'batch_1r_d_1r_h_category_overlap_blocked:'.implode(',', $unexpectedDH);
        }

        $unexpectedEH = array_values(array_intersect(
            $categoriesByBatch['1R-E'] ?? [],
            $categoriesByBatch['1R-H'] ?? []
        ));
        if ($unexpectedEH !== []) {
            $errors[] = 'batch_1r_e_1r_h_category_overlap_blocked:'.implode(',', $unexpectedEH);
        }

        $unexpectedFH = array_values(array_intersect(
            $categoriesByBatch['1R-F'] ?? [],
            $categoriesByBatch['1R-H'] ?? []
        ));
        if ($unexpectedFH !== []) {
            $errors[] = 'batch_1r_f_1r_h_category_overlap_blocked:'.implode(',', $unexpectedFH);
        }

        $unexpectedGH = array_values(array_intersect(
            $categoriesByBatch['1R-G'] ?? [],
            $categoriesByBatch['1R-H'] ?? []
        ));
        if ($unexpectedGH !== []) {
            $errors[] = 'batch_1r_g_1r_h_category_overlap_blocked:'.implode(',', $unexpectedGH);
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  list<array<string,mixed>>  $items
     */
    public function detectBatchKey(array $metadata, array $items): string
    {
        $version = (string) ($metadata['version'] ?? $metadata['batch'] ?? $metadata['batch_name'] ?? '');

        return $this->batchKey($version, $items);
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

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function isBatchC(string $version, array $items): bool
    {
        if (str_contains($version, '1R-C')) {
            return true;
        }

        foreach ($items as $item) {
            if (trim((string) ($item['objection_axis'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function isBatchD(string $version, array $items): bool
    {
        if (str_contains($version, '1R-D')) {
            return true;
        }

        foreach ($items as $item) {
            if (trim((string) ($item['partial_axis'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function isBatchE(string $version, array $items): bool
    {
        if (str_contains($version, '1R-E')) {
            return true;
        }

        foreach ($items as $item) {
            if (trim((string) ($item['diffuse_axis'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function isBatchF(string $version, array $items): bool
    {
        if (str_contains($version, '1R-F')) {
            return true;
        }

        foreach ($items as $item) {
            $pairKey = trim((string) ($item['pair_key'] ?? ''));
            $canonicalPairKey = trim((string) ($item['canonical_pair_key'] ?? ''));
            $typeA = trim((string) ($item['type_a'] ?? ''));
            $typeB = trim((string) ($item['type_b'] ?? ''));
            if (($pairKey !== '' || $canonicalPairKey !== '') && $typeA !== '' && $typeB !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function isBatchG(string $version, array $items): bool
    {
        if (str_contains($version, '1R-G')) {
            return true;
        }

        foreach ($items as $item) {
            if (trim((string) ($item['scene_axis'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function isBatchH(string $version, array $items): bool
    {
        if (str_contains($version, '1R-H')) {
            return true;
        }

        foreach ($items as $item) {
            $context = trim((string) ($item['fc144_recommendation_context'] ?? ''));
            if ($context !== '' && trim((string) ($item['category'] ?? '')) === 'fc144_recommendation_response') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     */
    private function batchKey(string $version, array $items): string
    {
        if ($this->isBatchA($version, $items)) {
            return '1R-A';
        }
        if ($this->isBatchB($version, $items)) {
            return '1R-B';
        }
        if ($this->isBatchC($version, $items)) {
            return '1R-C';
        }
        if ($this->isBatchD($version, $items)) {
            return '1R-D';
        }
        if ($this->isBatchE($version, $items)) {
            return '1R-E';
        }
        if ($this->isBatchF($version, $items)) {
            return '1R-F';
        }
        if ($this->isBatchG($version, $items)) {
            return '1R-G';
        }
        if ($this->isBatchH($version, $items)) {
            return '1R-H';
        }

        return '';
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    private function validatePairLibrary(array $items): array
    {
        $errors = [];
        $canonicalKeys = [];
        $expected = [];
        for ($left = 1; $left <= 9; $left++) {
            for ($right = $left + 1; $right <= 9; $right++) {
                $expected[] = $left.'_'.$right;
            }
        }

        foreach ($items as $index => $item) {
            $typeA = trim((string) ($item['type_a'] ?? ''));
            $typeB = trim((string) ($item['type_b'] ?? ''));
            $pairKey = trim((string) ($item['pair_key'] ?? ''));
            $canonicalPairKey = trim((string) ($item['canonical_pair_key'] ?? ''));
            $directional = (bool) ($item['directional'] ?? false);

            if (! ctype_digit($typeA) || ! ctype_digit($typeB)) {
                $errors[] = 'batch_1r_f_invalid_type_pair:item_'.$index;

                continue;
            }

            $left = (int) $typeA;
            $right = (int) $typeB;
            if ($left < 1 || $left > 9 || $right < 1 || $right > 9 || $left === $right) {
                $errors[] = 'batch_1r_f_invalid_type_pair:item_'.$index;

                continue;
            }

            $normalized = $this->canonicalPairKey($left, $right, $directional);
            if (! $this->isValidPairKey($pairKey)) {
                $errors[] = 'batch_1r_f_invalid_pair_key:item_'.$index;
            }
            if ($canonicalPairKey === '') {
                $errors[] = 'batch_1r_f_missing_canonical_pair_key:item_'.$index;
            } elseif ($canonicalPairKey !== $normalized) {
                $errors[] = 'batch_1r_f_canonical_pair_key_mismatch:'.$canonicalPairKey.'!= '.$normalized;
            }

            if (! $directional && $pairKey !== '' && $pairKey !== $normalized) {
                $errors[] = 'batch_1r_f_non_directional_pair_key_must_be_canonical:'.$pairKey;
            }

            if ($canonicalPairKey !== '') {
                if (isset($canonicalKeys[$canonicalPairKey])) {
                    $errors[] = 'batch_1r_f_duplicate_canonical_pair_key:'.$canonicalPairKey;
                }
                $canonicalKeys[$canonicalPairKey] = true;
            }
        }

        $actual = array_keys($canonicalKeys);
        sort($actual);
        sort($expected);

        foreach (array_values(array_diff($expected, $actual)) as $missing) {
            $errors[] = 'batch_1r_f_missing_canonical_pair_key:'.$missing;
        }

        foreach (array_values(array_diff($actual, $expected)) as $unexpected) {
            $errors[] = 'batch_1r_f_unexpected_canonical_pair_key:'.$unexpected;
        }

        return $errors;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    private function validateSceneLocalization(array $items): array
    {
        $errors = [];
        $expectedKeys = [];
        for ($type = 1; $type <= 9; $type++) {
            foreach (self::BATCH_G_SCENE_AXES as $axis) {
                $expectedKeys[] = $type.':'.$axis;
            }
        }

        $seen = [];
        foreach ($items as $index => $item) {
            $typeId = trim((string) ($item['type_id'] ?? ''));
            $sceneAxis = trim((string) ($item['scene_axis'] ?? ''));

            if (! ctype_digit($typeId) || (int) $typeId < 1 || (int) $typeId > 9) {
                $errors[] = 'batch_1r_g_invalid_type_id:item_'.$index;

                continue;
            }

            if (! in_array($sceneAxis, self::BATCH_G_SCENE_AXES, true)) {
                $errors[] = 'batch_1r_g_invalid_scene_axis:item_'.$index;

                continue;
            }

            $key = $typeId.':'.$sceneAxis;
            if (isset($seen[$key])) {
                $errors[] = 'batch_1r_g_duplicate_type_scene_axis:'.$key;
            }
            $seen[$key] = true;
        }

        $actualKeys = array_keys($seen);
        sort($actualKeys);
        sort($expectedKeys);

        foreach (array_values(array_diff($expectedKeys, $actualKeys)) as $missing) {
            $errors[] = 'batch_1r_g_missing_type_scene_axis:'.$missing;
        }

        foreach (array_values(array_diff($actualKeys, $expectedKeys)) as $unexpected) {
            $errors[] = 'batch_1r_g_unexpected_type_scene_axis:'.$unexpected;
        }

        return $errors;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    private function validateFc144Recommendation(array $items): array
    {
        $errors = [];
        $expectedKeys = [];
        for ($type = 1; $type <= 9; $type++) {
            foreach (self::BATCH_H_RECOMMENDATION_CONTEXTS as $context) {
                $expectedKeys[] = $type.':'.$context;
            }
        }

        $seen = [];
        $assetKeys = [];
        foreach ($items as $index => $item) {
            $typeId = trim((string) ($item['type_id'] ?? ''));
            $context = trim((string) ($item['fc144_recommendation_context'] ?? ''));
            $assetKey = trim((string) ($item['asset_key'] ?? ''));

            if (! ctype_digit($typeId) || (int) $typeId < 1 || (int) $typeId > 9) {
                $errors[] = 'batch_1r_h_invalid_type_id:item_'.$index;

                continue;
            }

            if (! in_array($context, self::BATCH_H_RECOMMENDATION_CONTEXTS, true)) {
                $errors[] = 'batch_1r_h_invalid_fc144_recommendation_context:item_'.$index;

                continue;
            }

            if ($assetKey !== '') {
                if (isset($assetKeys[$assetKey])) {
                    $errors[] = 'batch_1r_h_duplicate_asset_key:'.$assetKey;
                }
                $assetKeys[$assetKey] = true;
            }

            $key = $typeId.':'.$context;
            if (isset($seen[$key])) {
                $errors[] = 'batch_1r_h_duplicate_type_context:'.$key;
            }
            $seen[$key] = true;

            foreach (['body_zh', 'short_body_zh', 'cta_zh'] as $field) {
                $text = trim((string) ($item[$field] ?? ''));
                foreach (self::BATCH_H_BANNED_COPY_PHRASES as $phrase) {
                    if ($phrase !== '' && $text !== '' && str_contains($text, $phrase)) {
                        $errors[] = 'batch_1r_h_banned_phrase:'.$field.':'.($assetKey !== '' ? $assetKey : 'item_'.$index).':'.$phrase;
                    }
                }
            }
        }

        $actualKeys = array_keys($seen);
        sort($actualKeys);
        sort($expectedKeys);

        foreach (array_values(array_diff($expectedKeys, $actualKeys)) as $missing) {
            $errors[] = 'batch_1r_h_missing_type_context:'.$missing;
        }

        foreach (array_values(array_diff($actualKeys, $expectedKeys)) as $unexpected) {
            $errors[] = 'batch_1r_h_unexpected_type_context:'.$unexpected;
        }

        return $errors;
    }

    private function isValidPairKey(string $pairKey): bool
    {
        if (! preg_match('/^[1-9]_[1-9]$/', $pairKey)) {
            return false;
        }

        [$left, $right] = array_map('intval', explode('_', $pairKey, 2));

        return $left !== $right;
    }

    private function canonicalPairKey(int $left, int $right, bool $directional): string
    {
        if ($directional) {
            return $left.'_'.$right;
        }

        $values = [$left, $right];
        sort($values, SORT_NUMERIC);

        return $values[0].'_'.$values[1];
    }
}
