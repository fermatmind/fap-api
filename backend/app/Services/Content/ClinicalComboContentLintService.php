<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Services\Template\TemplateEngine;

final class ClinicalComboContentLintService
{
    private const REQUIRED_QUESTION_COUNT = 68;

    /**
     * @var list<string>
     */
    private const REQUIRED_LAYOUT_SECTIONS = [
        'disclaimer_top',
        'crisis_banner',
        'quick_overview',
        'symptoms_depression',
        'symptoms_anxiety',
        'symptoms_ocd',
        'stress_resilience',
        'perfectionism_overview',
        'paid_deep_dive',
        'action_plan',
        'resources_footer',
        'scoring_notes',
    ];

    public function __construct(
        private readonly ClinicalComboPackLoader $loader,
        private readonly TemplateEngine $templateEngine,
    ) {
    }

    /**
     * @return array{ok:bool,pack_id:string,version:string,errors:list<array{file:string,line:int,message:string}>}
     */
    public function lint(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $errors = [];

        $this->lintQuestions($version, $errors);
        $this->lintOptionsSets($version, $errors);
        $this->lintPolicy($version, $errors);
        $this->lintVariablesAllowlist($version, $errors);
        $this->lintReportLayoutSchema($version, $errors);
        $this->lintLayoutSatisfiability($version, $errors);
        $this->lintBlocksCoverageByDimension($version, $errors);
        $this->lintExclusiveGroupConflicts($version, $errors);
        $this->lintCrisisResourcesCompleteness($version, $errors);
        $this->lintConsentAndPrivacyDocs($version, $errors);
        $this->lintTemplateVariables($version, $errors);
        $this->lintGoldenCases($version, $errors);

        return [
            'ok' => $errors === [],
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'version' => $version,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintQuestions(string $version, array &$errors): void
    {
        $fileZh = $this->loader->rawPath('questions_zh.csv', $version);
        $fileEn = $this->loader->rawPath('questions_en.csv', $version);

        $rowsZh = $this->loader->readCsvWithLines($fileZh);
        $rowsEn = $this->loader->readCsvWithLines($fileEn);

        $this->lintQuestionRows($rowsZh, $fileZh, $errors);
        $this->lintQuestionRows($rowsEn, $fileEn, $errors);

        $idsZh = array_map(static fn (array $e): int => (int) (($e['row']['question_id'] ?? 0)), $rowsZh);
        $idsEn = array_map(static fn (array $e): int => (int) (($e['row']['question_id'] ?? 0)), $rowsEn);
        sort($idsZh);
        sort($idsEn);
        if ($idsZh !== $idsEn) {
            $errors[] = $this->error($fileEn, 1, 'questions_en.csv question ids must match questions_zh.csv.');
        }

        $policy = $this->loader->readJson($this->loader->rawPath('policy.json', $version)) ?? [];
        $reversePolicy = array_map('intval', (array) ($policy['reverse_questions'] ?? []));
        sort($reversePolicy);

        $reverseFound = [];
        foreach ($rowsZh as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $qid = (int) ($row['question_id'] ?? 0);
            $isReverse = (int) ($row['is_reverse'] ?? 0) === 1;
            if ($isReverse) {
                $reverseFound[] = $qid;
            }

            $setCode = trim((string) ($row['options_set_code'] ?? ''));
            if ($setCode === '') {
                $errors[] = $this->error($fileZh, $line, 'options_set_code required.');
            }
        }

        sort($reverseFound);
        if ($reverseFound !== $reversePolicy) {
            $errors[] = $this->error($fileZh, 1, 'is_reverse flags must exactly match policy.reverse_questions.');
        }
    }

    /**
     * @param list<array{line:int,row:array<string,string>}> $rows
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintQuestionRows(array $rows, string $file, array &$errors): void
    {
        if (count($rows) !== self::REQUIRED_QUESTION_COUNT) {
            $errors[] = $this->error($file, 1, 'questions must contain exactly 68 rows.');
        }

        $seen = [];
        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                $errors[] = $this->error($file, $line, 'question_id must be positive integer.');
                continue;
            }
            $seen[$qid] = true;

            $module = trim((string) ($row['module_code'] ?? ''));
            if (!$this->moduleMatchesQuestionRange($module, $qid)) {
                $errors[] = $this->error($file, $line, 'module_code does not match question id range.');
            }

            $isReverse = (int) ($row['is_reverse'] ?? 0);
            if (!in_array($isReverse, [0, 1], true)) {
                $errors[] = $this->error($file, $line, 'is_reverse must be 0 or 1.');
            }
            if ($isReverse === 1 && !in_array($qid, [18, 19], true)) {
                $errors[] = $this->error($file, $line, 'only question 18/19 can be reverse.');
            }
        }

        for ($i = 1; $i <= self::REQUIRED_QUESTION_COUNT; $i++) {
            if (!isset($seen[$i])) {
                $errors[] = $this->error($file, 1, 'missing question_id='.$i);
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintOptionsSets(string $version, array &$errors): void
    {
        $fileZh = $this->loader->rawPath('options_sets_zh.json', $version);
        $fileEn = $this->loader->rawPath('options_sets_en.json', $version);

        $zh = $this->loader->readJson($fileZh);
        $en = $this->loader->readJson($fileEn);
        if (!is_array($zh) || !is_array($en)) {
            $errors[] = $this->error($fileZh, 1, 'options sets json invalid.');
            return;
        }

        $setsZh = is_array($zh['sets'] ?? null) ? $zh['sets'] : [];
        $setsEn = is_array($en['sets'] ?? null) ? $en['sets'] : [];

        foreach ($setsZh as $code => $set) {
            if (!is_array($set)) {
                $errors[] = $this->error($fileZh, 1, 'set '.$code.' must be object.');
                continue;
            }

            $scoring = is_array($set['scoring'] ?? null) ? $set['scoring'] : [];
            $labels = is_array($set['labels_zh'] ?? null) ? $set['labels_zh'] : [];

            foreach (['A', 'B', 'C', 'D', 'E'] as $opt) {
                if (!array_key_exists($opt, $scoring)) {
                    $errors[] = $this->error($fileZh, 1, 'set '.$code.' scoring missing option '.$opt);
                }
                if (!array_key_exists($opt, $labels)) {
                    $errors[] = $this->error($fileZh, 1, 'set '.$code.' labels_zh missing option '.$opt);
                }
            }
        }

        foreach ($setsEn as $code => $set) {
            if (!is_array($set)) {
                continue;
            }
            $labels = is_array($set['labels_en'] ?? null) ? $set['labels_en'] : [];
            foreach (['A', 'B', 'C', 'D', 'E'] as $opt) {
                if (!array_key_exists($opt, $labels)) {
                    $errors[] = $this->error($fileEn, 1, 'set '.$code.' labels_en missing option '.$opt);
                }
            }
        }

        $questionRows = $this->loader->readCsvWithLines($this->loader->rawPath('questions_zh.csv', $version));
        foreach ($questionRows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);
            $setCode = trim((string) ($row['options_set_code'] ?? ''));
            if ($setCode === '' || !is_array($setsZh[$setCode] ?? null)) {
                $errors[] = $this->error($this->loader->rawPath('questions_zh.csv', $version), $line, 'options_set_code not found in options_sets_zh.json.');
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintPolicy(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('policy.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'policy.json invalid.');
            return;
        }

        $muSigma = is_array(data_get($doc, 'scoring_rules.mu_sigma')) ? data_get($doc, 'scoring_rules.mu_sigma') : [];
        foreach (['depression', 'anxiety', 'stress', 'resilience', 'perfectionism', 'ocd'] as $dim) {
            $node = is_array($muSigma[$dim] ?? null) ? $muSigma[$dim] : null;
            if ($node === null) {
                $errors[] = $this->error($file, 1, 'scoring_rules.mu_sigma.'.$dim.' missing.');
                continue;
            }
            $sigma = (float) ($node['sigma'] ?? 0.0);
            if ($sigma <= 0.0) {
                $errors[] = $this->error($file, 1, 'scoring_rules.mu_sigma.'.$dim.'.sigma must > 0.');
            }
        }

        $buckets = is_array(data_get($doc, 'scoring_rules.buckets')) ? data_get($doc, 'scoring_rules.buckets') : [];
        foreach (['depression', 'anxiety', 'stress', 'resilience', 'perfectionism', 'ocd'] as $dim) {
            $rules = is_array($buckets[$dim] ?? null) ? $buckets[$dim] : [];
            if ($rules === []) {
                $errors[] = $this->error($file, 1, 'scoring_rules.buckets.'.$dim.' missing.');
                continue;
            }
            $this->lintLevelRulesMonotonic($file, $rules, $dim, $errors);
        }

        $reverse = array_map('intval', (array) ($doc['reverse_questions'] ?? []));
        sort($reverse);
        if ($reverse !== [18, 19]) {
            $errors[] = $this->error($file, 1, 'reverse_questions must be [18,19].');
        }

        $consentVersion = trim((string) data_get($doc, 'consent_policy.version', ''));
        if ($consentVersion === '') {
            $errors[] = $this->error($file, 1, 'consent_policy.version is required.');
        }

        $allowedOps = array_values(array_unique(array_map(static fn ($v): string => strtolower(trim((string) $v)), (array) data_get($doc, 'content_condition_rules.allowed_ops', []))));
        foreach (['eq', 'in', 'contains', 'gte', 'lte'] as $op) {
            if (!in_array($op, $allowedOps, true)) {
                $errors[] = $this->error($file, 1, 'content_condition_rules.allowed_ops missing '.$op.'.');
            }
        }

        $allowedPaths = array_values(array_unique(array_map(static fn ($v): string => trim((string) $v), (array) data_get($doc, 'content_condition_rules.allowed_paths', []))));
        foreach ([
            'quality.crisis_alert',
            'quality.flags',
            'scores.depression.level',
            'scores.anxiety.level',
            'scores.ocd.level',
            'scores.stress.level',
            'scores.resilience.level',
            'scores.perfectionism.level',
            'facts.function_impairment_level',
            'report_tags',
        ] as $requiredPath) {
            if (!in_array($requiredPath, $allowedPaths, true)) {
                $errors[] = $this->error($file, 1, 'content_condition_rules.allowed_paths missing '.$requiredPath.'.');
            }
        }

        $this->lintCrisisRules($version, $file, $doc, $errors);
    }

    /**
     * @param list<array<string,mixed>> $rules
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintLevelRulesMonotonic(string $file, array $rules, string $dim, array &$errors): void
    {
        $expectedStart = 20;
        $expectedEnd = 80;

        usort($rules, static function (array $a, array $b): int {
            return ((int) ($a['min_t'] ?? 0)) <=> ((int) ($b['min_t'] ?? 0));
        });

        $cursor = $expectedStart;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                $errors[] = $this->error($file, 1, 'bucket rule must be object for '.$dim.'.');
                continue;
            }

            $min = (int) ($rule['min_t'] ?? -9999);
            $max = (int) ($rule['max_t'] ?? -9999);
            $level = trim((string) ($rule['level'] ?? ''));
            if ($level === '') {
                $errors[] = $this->error($file, 1, 'bucket rule level missing for '.$dim.'.');
            }
            if ($min > $max) {
                $errors[] = $this->error($file, 1, 'bucket min_t > max_t for '.$dim.'.');
            }
            if ($min !== $cursor) {
                $errors[] = $this->error($file, 1, 'bucket rules must be contiguous from 20..80 for '.$dim.'.');
            }
            $cursor = $max + 1;
        }

        if ($cursor - 1 !== $expectedEnd) {
            $errors[] = $this->error($file, 1, 'bucket rules must cover t-score 20..80 for '.$dim.'.');
        }
    }

    /**
     * @param array<string,mixed> $doc
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintCrisisRules(string $version, string $file, array $doc, array &$errors): void
    {
        $q9Min = (int) data_get($doc, 'crisis_rules.q9_min', -1);
        $q68Min = (int) data_get($doc, 'crisis_rules.q68_min', -1);
        if ($q9Min < 0 || $q9Min > 4 || $q68Min < 0 || $q68Min > 4) {
            $errors[] = $this->error($file, 1, 'crisis rules thresholds must be within 0..4.');
        }

        $rows = $this->loader->readCsvWithLines($this->loader->rawPath('questions_zh.csv', $version));
        $q9 = null;
        $q68 = null;
        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid === 9) {
                $q9 = $row;
            }
            if ($qid === 68) {
                $q68 = $row;
            }
        }

        if ($q9 === null || $q68 === null) {
            $errors[] = $this->error($file, 1, 'Q9 and Q68 must exist.');
            return;
        }

        $setsDoc = $this->loader->readJson($this->loader->rawPath('options_sets_zh.json', $version)) ?? [];
        $sets = is_array($setsDoc['sets'] ?? null) ? $setsDoc['sets'] : [];
        foreach ([9 => $q9, 68 => $q68] as $qid => $row) {
            $setCode = trim((string) ($row['options_set_code'] ?? ''));
            $set = is_array($sets[$setCode] ?? null) ? $sets[$setCode] : [];
            $scoring = is_array($set['scoring'] ?? null) ? $set['scoring'] : [];
            $scores = array_values(array_map('intval', $scoring));
            sort($scores);
            if ($scores !== [0, 1, 2, 3, 4]) {
                $errors[] = $this->error($file, 1, 'Q'.$qid.' options set must score 0..4.');
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintVariablesAllowlist(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('variables_allowlist.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'variables_allowlist.json invalid.');
            return;
        }

        $allowed = is_array($doc['allowed'] ?? null) ? $doc['allowed'] : [];
        if ($allowed === []) {
            $errors[] = $this->error($file, 1, 'variables_allowlist.allowed cannot be empty.');
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintReportLayoutSchema(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('report_layout.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'report_layout.json invalid.');
            return;
        }

        $layout = is_array($doc['layout'] ?? null) ? $doc['layout'] : $doc;
        $schema = trim((string) ($layout['schema'] ?? ''));
        if ($schema === '') {
            $errors[] = $this->error($file, 1, 'layout.schema is required.');
        }

        $conflictRules = is_array($layout['conflict_rules'] ?? null) ? $layout['conflict_rules'] : [];
        $selector = strtolower(trim((string) ($conflictRules['selector'] ?? '')));
        if ($selector !== 'priority_desc_block_id_asc') {
            $errors[] = $this->error($file, 1, 'conflict_rules.selector must be priority_desc_block_id_asc.');
        }
        $exclusivePolicy = strtolower(trim((string) ($conflictRules['exclusive_group_policy'] ?? '')));
        if ($exclusivePolicy !== 'single_per_group') {
            $errors[] = $this->error($file, 1, 'conflict_rules.exclusive_group_policy must be single_per_group.');
        }

        $sections = is_array($layout['sections'] ?? null) ? $layout['sections'] : [];
        if ($sections === []) {
            $errors[] = $this->error($file, 1, 'layout.sections cannot be empty.');
            return;
        }

        $seen = [];
        foreach ($sections as $index => $section) {
            if (!is_array($section)) {
                $errors[] = $this->error($file, 1, 'sections['.$index.'] must be object.');
                continue;
            }

            $key = trim((string) ($section['key'] ?? ''));
            if ($key === '') {
                $errors[] = $this->error($file, 1, 'sections['.$index.'].key is required.');
                continue;
            }

            if (isset($seen[$key])) {
                $errors[] = $this->error($file, 1, 'duplicate section key: '.$key);
            }
            $seen[$key] = true;

            $source = strtolower(trim((string) ($section['source'] ?? '')));
            if (!in_array($source, ['copy', 'blocks'], true)) {
                $errors[] = $this->error($file, 1, 'sections['.$index.'].source must be copy|blocks.');
            }

            $accessLevel = strtolower(trim((string) ($section['access_level'] ?? '')));
            if (!in_array($accessLevel, ['free', 'paid'], true)) {
                $errors[] = $this->error($file, 1, 'sections['.$index.'].access_level must be free|paid.');
            }

            $requiredVariants = is_array($section['required_in_variant'] ?? null) ? $section['required_in_variant'] : [];
            if ($requiredVariants === []) {
                $errors[] = $this->error($file, 1, 'sections['.$index.'].required_in_variant cannot be empty.');
            }
            foreach ($requiredVariants as $variant) {
                $variant = strtolower(trim((string) $variant));
                if (!in_array($variant, ['free', 'full'], true)) {
                    $errors[] = $this->error($file, 1, 'sections['.$index.'].required_in_variant has invalid value.');
                }
            }

            $minBlocks = (int) ($section['min_blocks'] ?? -1);
            $maxBlocks = (int) ($section['max_blocks'] ?? -1);
            if ($minBlocks < 0 || $maxBlocks < 0 || $maxBlocks < $minBlocks) {
                $errors[] = $this->error($file, 1, 'sections['.$index.'] min_blocks/max_blocks invalid.');
            }
        }

        foreach (self::REQUIRED_LAYOUT_SECTIONS as $required) {
            if (!isset($seen[$required])) {
                $errors[] = $this->error($file, 1, 'required section missing: '.$required);
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintLayoutSatisfiability(string $version, array &$errors): void
    {
        $layoutFile = $this->loader->rawPath('report_layout.json', $version);
        $layoutDoc = $this->loader->readJson($layoutFile);
        if (!is_array($layoutDoc)) {
            return;
        }

        $layout = is_array($layoutDoc['layout'] ?? null) ? $layoutDoc['layout'] : $layoutDoc;
        $sections = is_array($layout['sections'] ?? null) ? $layout['sections'] : [];
        if ($sections === []) {
            return;
        }

        $blocksByFile = $this->loadRawBlocks($version);
        $counts = [];
        foreach ($blocksByFile as $blocks) {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $section = strtolower(trim((string) ($block['section'] ?? '')));
                $locale = strtolower($this->normalizeLocale((string) ($block['locale'] ?? 'zh-CN')));
                if ($section === '' || $locale === '') {
                    continue;
                }
                $counts[$section][$locale] = ($counts[$section][$locale] ?? 0) + 1;
            }
        }

        foreach ($sections as $index => $section) {
            if (!is_array($section)) {
                continue;
            }
            $source = strtolower(trim((string) ($section['source'] ?? '')));
            if ($source !== 'blocks') {
                continue;
            }
            $key = strtolower(trim((string) ($section['key'] ?? '')));
            $minBlocks = max(0, (int) ($section['min_blocks'] ?? 0));
            if ($minBlocks <= 0) {
                continue;
            }

            foreach (['zh-cn', 'en'] as $locale) {
                $available = (int) ($counts[$key][$locale] ?? 0);
                if ($available < $minBlocks) {
                    $errors[] = $this->error($layoutFile, 1, "sections[$index] ($key) unsatisfied for locale=$locale: min=$minBlocks, available=$available");
                }
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintBlocksCoverageByDimension(string $version, array &$errors): void
    {
        $blocksByFile = $this->loadRawBlocks($version);
        if ($blocksByFile === []) {
            $errors[] = $this->error($this->loader->rawPath('blocks', $version), 1, 'blocks directory is empty.');
            return;
        }

        $allBlocks = [];
        foreach ($blocksByFile as $blocks) {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $allBlocks[] = $block;
            }
        }

        $zhBlockIds = [];
        foreach ($allBlocks as $block) {
            $locale = $this->normalizeLocale((string) ($block['locale'] ?? 'zh-CN'));
            if ($locale !== 'zh-CN') {
                continue;
            }
            $id = trim((string) ($block['block_id'] ?? $block['id'] ?? ''));
            if ($id !== '') {
                $zhBlockIds[$id] = true;
            }
        }

        $requiredIds = [
            // Depression buckets + masked.
            'free_dep_normal_zh',
            'free_dep_mild_zh',
            'free_dep_moderate_zh',
            'free_dep_severe_zh',
            'free_dep_masked_zh',
            // Anxiety buckets.
            'free_anx_normal_zh',
            'free_anx_mild_zh',
            'free_anx_moderate_zh',
            'free_anx_severe_zh',
            // OCD buckets.
            'free_ocd_subclinical_zh',
            'free_ocd_mild_zh',
            'free_ocd_moderate_zh',
            'free_ocd_severe_zh',
            // Stress buckets.
            'free_stress_low_zh',
            'free_stress_medium_zh',
            'free_stress_high_zh',
            // Resilience buckets.
            'free_res_fragile_zh',
            'free_res_normal_zh',
            'free_res_strong_zh',
            // Function impairment 5 buckets.
            'free_impair_none_zh',
            'free_impair_mild_zh',
            'free_impair_moderate_zh',
            'free_impair_severe_zh',
            'free_impair_extreme_zh',
            // Paid perfectionism deep-dive.
            'paid_perf_pe_parental_zh',
            'paid_perf_org_order_zh',
            'paid_perf_ps_standards_zh',
            'paid_perf_cm_mistakes_zh',
            'paid_perf_da_doubts_zh',
            'paid_resilience_praise_zh',
            // Paid action plans.
            'paid_action_depression_14d_zh',
            'paid_action_anxiety_14d_zh',
            'paid_action_ocd_erp_start_zh',
            'paid_action_perfectionism_14d_zh',
        ];

        foreach ($requiredIds as $requiredId) {
            if (!isset($zhBlockIds[$requiredId])) {
                $errors[] = $this->error($this->loader->rawPath('blocks/free_blocks.json', $version), 1, 'required zh block missing: '.$requiredId);
            }
        }

        // EN safety core sections must exist.
        $requiredEnSections = [
            'disclaimer_top',
            'crisis_banner',
            'quick_overview',
            'symptoms_depression',
            'symptoms_anxiety',
            'symptoms_ocd',
            'stress_resilience',
            'resources_footer',
            'scoring_notes',
        ];
        foreach ($requiredEnSections as $sectionKey) {
            $has = false;
            foreach ($allBlocks as $block) {
                if ($this->normalizeLocale((string) ($block['locale'] ?? 'zh-CN')) !== 'en') {
                    continue;
                }
                if (strtolower(trim((string) ($block['section'] ?? ''))) !== strtolower($sectionKey)) {
                    continue;
                }
                $has = true;
                break;
            }
            if (!$has) {
                $errors[] = $this->error($this->loader->rawPath('blocks/free_blocks.json', $version), 1, 'EN safety core section missing block: '.$sectionKey);
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintExclusiveGroupConflicts(string $version, array &$errors): void
    {
        $blocksByFile = $this->loadRawBlocks($version);
        if ($blocksByFile === []) {
            return;
        }

        $seenBlockIds = [];

        foreach ($blocksByFile as $file => $blocks) {
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }

                $blockId = trim((string) ($block['block_id'] ?? $block['id'] ?? ''));
                if ($blockId === '') {
                    $errors[] = $this->error($file, 1, 'block_id is required for every block.');
                    continue;
                }

                if (isset($seenBlockIds[$blockId])) {
                    $errors[] = $this->error($file, 1, 'duplicate block_id detected: '.$blockId);
                }
                $seenBlockIds[$blockId] = true;
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintCrisisResourcesCompleteness(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('crisis_resources.json', $version);
        $doc = $this->loader->readJson($file);
        if (!is_array($doc)) {
            $errors[] = $this->error($file, 1, 'crisis_resources.json invalid.');
            return;
        }

        $locales = is_array($doc['locales'] ?? null) ? $doc['locales'] : [];
        foreach (['zh-CN', 'en'] as $locale) {
            $node = is_array($locales[$locale] ?? null) ? $locales[$locale] : [];
            if ($node === []) {
                $errors[] = $this->error($file, 1, 'crisis_resources missing locale: '.$locale);
                continue;
            }

            foreach (['CN_MAINLAND', 'US', 'GLOBAL'] as $region) {
                $rows = is_array($node[$region] ?? null) ? $node[$region] : [];
                if ($rows === []) {
                    $errors[] = $this->error($file, 1, 'crisis_resources missing region '.$region.' for '.$locale);
                }
            }

            $usRows = is_array($node['US'] ?? null) ? $node['US'] : [];
            $has988 = false;
            foreach ($usRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $phone = (string) ($row['phone'] ?? '');
                if (str_contains($phone, '988')) {
                    $has988 = true;
                    break;
                }
            }
            if (!$has988) {
                $errors[] = $this->error($file, 1, 'US crisis resources must include 988.');
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintConsentAndPrivacyDocs(string $version, array &$errors): void
    {
        $consentFile = $this->loader->rawPath('consent_i18n.json', $version);
        $consent = $this->loader->readJson($consentFile);
        if (!is_array($consent)) {
            $errors[] = $this->error($consentFile, 1, 'consent_i18n.json invalid.');
        } else {
            if (trim((string) ($consent['version'] ?? '')) === '') {
                $errors[] = $this->error($consentFile, 1, 'consent version required.');
            }
            foreach (['title', 'checkboxes', 'primary_button', 'secondary_button'] as $field) {
                $node = is_array($consent[$field] ?? null) ? $consent[$field] : [];
                foreach (['zh-CN', 'en'] as $locale) {
                    $value = $node[$locale] ?? null;
                    if ($field === 'checkboxes') {
                        if (!is_array($value) || $value === []) {
                            $errors[] = $this->error($consentFile, 1, 'consent.'.$field.'.'.$locale.' must be non-empty array.');
                        }
                    } elseif (trim((string) $value) === '') {
                        $errors[] = $this->error($consentFile, 1, 'consent.'.$field.'.'.$locale.' is required.');
                    }
                }
            }
        }

        $privacyFile = $this->loader->rawPath('privacy_addendum_i18n.json', $version);
        $privacy = $this->loader->readJson($privacyFile);
        if (!is_array($privacy)) {
            $errors[] = $this->error($privacyFile, 1, 'privacy_addendum_i18n.json invalid.');
            return;
        }

        if (trim((string) ($privacy['version'] ?? '')) === '') {
            $errors[] = $this->error($privacyFile, 1, 'privacy addendum version required.');
        }

        $title = is_array($privacy['title'] ?? null) ? $privacy['title'] : [];
        $bullets = is_array($privacy['bullets'] ?? null) ? $privacy['bullets'] : [];
        foreach (['zh-CN', 'en'] as $locale) {
            if (trim((string) ($title[$locale] ?? '')) === '') {
                $errors[] = $this->error($privacyFile, 1, 'privacy title missing for '.$locale);
            }
            $rows = is_array($bullets[$locale] ?? null) ? $bullets[$locale] : [];
            if ($rows === []) {
                $errors[] = $this->error($privacyFile, 1, 'privacy bullets missing for '.$locale);
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintTemplateVariables(string $version, array &$errors): void
    {
        $allowlistDoc = $this->loader->readJson($this->loader->rawPath('variables_allowlist.json', $version)) ?? [];
        $allowed = array_values(array_unique(array_filter(array_map('strval', (array) ($allowlistDoc['allowed'] ?? [])))));
        $allowedSet = array_fill_keys($allowed, true);

        $blocksByFile = $this->loadRawBlocks($version);
        foreach ($blocksByFile as $file => $blocks) {
            foreach ($blocks as $index => $block) {
                if (!is_array($block)) {
                    continue;
                }

                foreach (['title', 'body', 'body_md'] as $field) {
                    $text = trim((string) ($block[$field] ?? ''));
                    if ($text === '') {
                        continue;
                    }

                    $vars = $this->templateEngine->extractVariables($text);
                    foreach ($vars as $var) {
                        if (!isset($allowedSet[$var])) {
                            $errors[] = $this->error($file, 1, 'unknown template variable '.$var.' in block index '.$index);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param list<array{file:string,line:int,message:string}> $errors
     */
    private function lintGoldenCases(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('golden_cases.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if ($rows === []) {
            $errors[] = $this->error($file, 1, 'golden_cases.csv cannot be empty.');
            return;
        }

        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $answers = trim((string) ($row['answers'] ?? ''));
            if (strlen($answers) !== 68) {
                $errors[] = $this->error($file, $line, 'answers string must be exactly 68 chars.');
            }
            if ($answers !== '' && preg_match('/^[ABCDE]{68}$/', $answers) !== 1) {
                $errors[] = $this->error($file, $line, 'answers must contain only A-E.');
            }

            $reasonsJson = trim((string) ($row['expected_crisis_reasons_json'] ?? '[]'));
            $decoded = json_decode($reasonsJson, true);
            if (!is_array($decoded)) {
                $errors[] = $this->error($file, $line, 'expected_crisis_reasons_json must be valid json array.');
            }
        }
    }

    private function moduleMatchesQuestionRange(string $module, int $qid): bool
    {
        return match (true) {
            $qid >= 1 && $qid <= 16 => $module === 'M1',
            $qid >= 17 && $qid <= 30 => $module === 'M2',
            $qid >= 31 && $qid <= 57 => $module === 'M3',
            $qid >= 58 && $qid <= 68 => $module === 'M4',
            default => false,
        };
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function loadRawBlocks(string $version): array
    {
        $dir = $this->loader->rawPath('blocks', $version);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $unified = [
            $this->loader->rawPath('blocks/free_blocks.json', $version),
            $this->loader->rawPath('blocks/paid_blocks.json', $version),
        ];
        foreach ($unified as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }

        if ($files === []) {
            $legacy = glob($dir.DIRECTORY_SEPARATOR.'*.json');
            if (is_array($legacy)) {
                $files = $legacy;
            }
        }

        if ($files === []) {
            return [];
        }
        sort($files);

        $out = [];
        foreach ($files as $file) {
            $doc = $this->loader->readJson($file);
            if (!is_array($doc)) {
                $out[$file] = [];
                continue;
            }
            $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
            $normalized = [];
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $normalized[] = $block;
            }
            $out[$file] = $normalized;
        }

        return $out;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if (str_starts_with($locale, 'zh')) {
            return 'zh-CN';
        }
        if (str_starts_with($locale, 'en')) {
            return 'en';
        }

        return 'zh-CN';
    }

    /**
     * @return array{file:string,line:int,message:string}
     */
    private function error(string $file, int $line, string $message): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'message' => $message,
        ];
    }

    private function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : ClinicalComboPackLoader::PACK_VERSION;
    }
}
