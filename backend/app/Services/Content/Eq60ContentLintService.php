<?php

declare(strict_types=1);

namespace App\Services\Content;

final class Eq60ContentLintService
{
    private const REQUIRED_QUESTION_COUNT = 60;

    /**
     * @var list<string>
     */
    private const REQUIRED_DIMENSIONS = ['SA', 'ER', 'EM', 'RM'];

    /**
     * @var list<int>
     */
    private const REVERSE_QUESTION_IDS = [5, 10, 11, 15, 18, 20, 22, 23, 29, 30, 31, 42, 44, 52, 53, 57];

    /**
     * @var list<string>
     */
    private const REQUIRED_LAYOUT_SECTIONS = [
        'disclaimer_top',
        'quality_notice',
        'global_overview',
        'self_awareness',
        'emotion_regulation',
        'empathy',
        'relationship_management',
        'cross_quadrant_insight',
        'action_plan_14d',
        'methodology',
        'disclaimer_bottom',
    ];

    /**
     * @var list<string>
     */
    private const QUALITY_FLAGS = [
        'SPEEDING',
        'LONGSTRING',
        'EXTREME_RESPONSE_BIAS',
        'NEUTRAL_RESPONSE_BIAS',
        'INCONSISTENT',
    ];

    /**
     * @var list<string>
     */
    private const MATURITY_LEVELS = [
        'baseline',
        'developing',
        'competent',
        'proficient',
        'exceptional',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_TAG_PREFIXES = [
        'section',
        'bucket',
        'quality_level',
        'quality_flag',
        'profile',
        'focus',
        'strength',
    ];

    public function __construct(
        private readonly Eq60PackLoader $loader,
    ) {}

    /**
     * @return array{ok:bool,pack_id:string,version:string,errors:list<array{file:string,line:int,message:string}>}
     */
    public function lint(?string $version = null): array
    {
        $version = $this->loader->normalizeVersion($version);
        $errors = [];

        $questionIndex = $this->lintQuestions($version, $errors);
        $this->lintOptions($version, $errors);
        $this->lintPolicy($version, $questionIndex, $errors);
        $this->lintLanding($version, $errors);

        $layout = $this->lintReportLayout($version, $errors);
        $allowlist = $this->lintVariablesAllowlist($version, $errors);
        $this->lintBlocks($version, $layout, $allowlist, $errors);
        $this->lintReportAssets($version, $errors);
        $this->lintPersonalizationRoutes($version, $errors);

        $this->lintGoldenCases($version, $questionIndex, $errors);

        return [
            'ok' => $errors === [],
            'pack_id' => Eq60PackLoader::PACK_ID,
            'version' => $version,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     * @return array<int,array{dimension:string,direction:int}>
     */
    private function lintQuestions(string $version, array &$errors): array
    {
        $file = $this->loader->rawPath('questions_eq60_bilingual.csv', $version);
        $rows = $this->loader->readCsvWithLines($file);
        if (count($rows) !== self::REQUIRED_QUESTION_COUNT) {
            $errors[] = $this->error($file, 1, 'questions must contain exactly 60 rows.');
        }

        $seen = [];
        $dimensionCounts = array_fill_keys(self::REQUIRED_DIMENSIONS, 0);
        $index = [];

        foreach ($rows as $entry) {
            $line = (int) ($entry['line'] ?? 0);
            $row = (array) ($entry['row'] ?? []);

            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                $errors[] = $this->error($file, $line, 'question_id must be positive integer.');

                continue;
            }

            $seen[$qid] = ($seen[$qid] ?? 0) + 1;

            $dimension = strtoupper(trim((string) ($row['dimension'] ?? '')));
            if (! in_array($dimension, self::REQUIRED_DIMENSIONS, true)) {
                $errors[] = $this->error($file, $line, 'dimension must be one of SA/ER/EM/RM.');

                continue;
            }

            $expectedDimension = $this->expectedDimensionForQuestion($qid);
            if ($expectedDimension === null || $dimension !== $expectedDimension) {
                $errors[] = $this->error($file, $line, 'dimension does not match question_id range.');
            }

            $direction = (int) ($row['direction'] ?? 0);
            if (! in_array($direction, [1, -1], true)) {
                $errors[] = $this->error($file, $line, 'direction must be 1 or -1.');

                continue;
            }

            $isReverse = in_array($qid, self::REVERSE_QUESTION_IDS, true);
            if ($isReverse && $direction !== -1) {
                $errors[] = $this->error($file, $line, 'reverse question direction must be -1.');
            }
            if (! $isReverse && $direction !== 1) {
                $errors[] = $this->error($file, $line, 'forward question direction must be 1.');
            }

            if (trim((string) ($row['text_en'] ?? '')) === '') {
                $errors[] = $this->error($file, $line, 'text_en is required.');
            }
            if (trim((string) ($row['text_zh'] ?? '')) === '') {
                $errors[] = $this->error($file, $line, 'text_zh is required.');
            }

            $dimensionCounts[$dimension] = (int) ($dimensionCounts[$dimension] ?? 0) + 1;
            $index[$qid] = [
                'dimension' => $dimension,
                'direction' => $direction,
            ];
        }

        for ($qid = 1; $qid <= self::REQUIRED_QUESTION_COUNT; $qid++) {
            if (($seen[$qid] ?? 0) !== 1) {
                $errors[] = $this->error($file, 1, 'question_id='.$qid.' must appear exactly once.');
            }
        }

        foreach (self::REQUIRED_DIMENSIONS as $dimension) {
            if ((int) ($dimensionCounts[$dimension] ?? 0) !== 15) {
                $errors[] = $this->error($file, 1, 'dimension '.$dimension.' must contain exactly 15 questions.');
            }
        }

        ksort($index, SORT_NUMERIC);

        return $index;
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintOptions(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('options_eq60_bilingual.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'options json invalid.');

            return;
        }

        $codes = array_values(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) ($doc['codes'] ?? [])
        ));
        if ($codes !== ['A', 'B', 'C', 'D', 'E']) {
            $errors[] = $this->error($file, 1, 'codes must be [A,B,C,D,E].');
        }

        foreach (['zh-CN', 'en'] as $locale) {
            $labels = data_get($doc, 'labels.'.$locale, null);
            if (! is_array($labels) || count($labels) !== 5) {
                $errors[] = $this->error($file, 1, 'labels.'.$locale.' must contain exactly 5 options.');

                continue;
            }

            foreach ($labels as $idx => $label) {
                if (trim((string) $label) === '') {
                    $errors[] = $this->error($file, 1, 'labels.'.$locale.'['.$idx.'] is required.');
                }
            }
        }

        $scoreMap = is_array($doc['score_map'] ?? null) ? $doc['score_map'] : [];
        $expected = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        foreach ($expected as $code => $score) {
            if (! array_key_exists($code, $scoreMap)) {
                $errors[] = $this->error($file, 1, 'score_map missing code '.$code.'.');

                continue;
            }
            if ((int) $scoreMap[$code] !== $score) {
                $errors[] = $this->error($file, 1, 'score_map.'.$code.' must be '.$score.'.');
            }
        }
    }

    /**
     * @param  array<int,array{dimension:string,direction:int}>  $questionIndex
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintPolicy(string $version, array $questionIndex, array &$errors): void
    {
        $file = $this->loader->rawPath('policy.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'policy.json invalid.');

            return;
        }

        $packId = strtoupper(trim((string) ($doc['pack_id'] ?? '')));
        if ($packId !== '' && ! in_array($packId, ['EQ_GOLEMAN_60', 'EQ_60'], true)) {
            $errors[] = $this->error($file, 1, 'policy.pack_id must be EQ_GOLEMAN_60 or EQ_60.');
        }

        $scaleCode = strtoupper(trim((string) ($doc['scale_code'] ?? '')));
        if ($scaleCode !== '' && ! in_array($scaleCode, ['EQ_GOLEMAN_60', 'EQ_60'], true)) {
            $errors[] = $this->error($file, 1, 'policy.scale_code must be EQ_GOLEMAN_60 or EQ_60.');
        }

        $engineVersion = strtolower(trim((string) ($doc['engine_version'] ?? '')));
        if ($engineVersion !== '' && ! in_array($engineVersion, ['eq60_v1.0_normed_validity', 'v1.0_normed_validity'], true)) {
            $errors[] = $this->error($file, 1, 'engine_version must be eq60_v1.0_normed_validity or v1.0_normed_validity.');
        }

        $specVersion = trim((string) ($doc['spec_version'] ?? ($doc['scoring_spec_version'] ?? '')));
        if ($specVersion === '') {
            $errors[] = $this->error($file, 1, 'spec_version (or scoring_spec_version) is required.');
        }

        $dimensionNodes = is_array(data_get($doc, 'scoring.dimensions')) ? data_get($doc, 'scoring.dimensions') : [];
        if ($dimensionNodes === []) {
            $errors[] = $this->error($file, 1, 'scoring.dimensions is required.');
        }

        $dimensionKeyMap = [
            'self_awareness' => 'SA',
            'emotion_regulation' => 'ER',
            'empathy' => 'EM',
            'relationship_management' => 'RM',
        ];

        $covered = [];
        foreach ($dimensionKeyMap as $name => $expectedCode) {
            $node = is_array($dimensionNodes[$name] ?? null) ? $dimensionNodes[$name] : null;
            if (! is_array($node)) {
                $errors[] = $this->error($file, 1, 'scoring.dimensions.'.$name.' is required.');

                continue;
            }

            $code = strtoupper(trim((string) ($node['code'] ?? '')));
            if ($code !== $expectedCode) {
                $errors[] = $this->error($file, 1, 'scoring.dimensions.'.$name.'.code must be '.$expectedCode.'.');
            }

            $qids = array_map('intval', (array) ($node['qids'] ?? []));
            if (count($qids) !== 15) {
                $errors[] = $this->error($file, 1, 'scoring.dimensions.'.$name.'.qids must contain exactly 15 ids.');

                continue;
            }

            foreach ($qids as $qid) {
                if ($qid < 1 || $qid > self::REQUIRED_QUESTION_COUNT) {
                    $errors[] = $this->error($file, 1, 'scoring.dimensions.'.$name.'.qids contains invalid question id.');

                    continue;
                }

                $covered[] = $qid;
                $indexNode = $questionIndex[$qid] ?? null;
                if (! is_array($indexNode) || strtoupper((string) ($indexNode['dimension'] ?? '')) !== $expectedCode) {
                    $errors[] = $this->error($file, 1, 'scoring.dimensions.'.$name.'.qids does not match questions for id='.$qid.'.');
                }
            }
        }

        sort($covered);
        if ($covered !== range(1, self::REQUIRED_QUESTION_COUNT)) {
            $errors[] = $this->error($file, 1, 'scoring.dimensions must cover 1..60 exactly once.');
        }

        $reverse = array_map('intval', (array) data_get($doc, 'scoring.reverse_items', []));
        sort($reverse);
        $expectedReverse = self::REVERSE_QUESTION_IDS;
        sort($expectedReverse);
        if ($reverse !== $expectedReverse) {
            $errors[] = $this->error($file, 1, 'scoring.reverse_items must match fixed reverse item set.');
        }

        $validityLevels = array_values(array_map(
            static fn ($level): string => strtoupper(trim((string) $level)),
            (array) data_get($doc, 'validity.quality_levels', [])
        ));
        if ($validityLevels !== ['A', 'B', 'C', 'D']) {
            $errors[] = $this->error($file, 1, 'validity.quality_levels must be [A,B,C,D].');
        }

        $speedingC = (int) data_get($doc, 'validity.time_seconds_thresholds.C_below', 0);
        $speedingD = (int) data_get($doc, 'validity.time_seconds_thresholds.D_below', 0);
        if ($speedingC <= 0 || $speedingD <= 0 || $speedingD >= $speedingC) {
            $errors[] = $this->error($file, 1, 'validity.time_seconds_thresholds invalid.');
        }

        $longstringC = (int) data_get($doc, 'validity.longstring_thresholds.C_at_or_above', 0);
        $longstringD = (int) data_get($doc, 'validity.longstring_thresholds.D_at_or_above', 0);
        if ($longstringC <= 0 || $longstringD <= 0 || $longstringD < $longstringC) {
            $errors[] = $this->error($file, 1, 'validity.longstring_thresholds invalid.');
        }

        $extremeC = (float) data_get($doc, 'validity.response_style_thresholds.extreme_rate_C_at_or_above', -1.0);
        $neutralC = (float) data_get($doc, 'validity.response_style_thresholds.neutral_rate_C_at_or_above', -1.0);
        if ($extremeC < 0.0 || $extremeC > 1.0 || $neutralC < 0.0 || $neutralC > 1.0) {
            $errors[] = $this->error($file, 1, 'validity.response_style_thresholds invalid.');
        }

        $pairs = (array) data_get($doc, 'validity.inconsistency_pairs', []);
        if ($pairs === []) {
            $errors[] = $this->error($file, 1, 'validity.inconsistency_pairs cannot be empty.');
        }
        foreach ($pairs as $idx => $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                $errors[] = $this->error($file, 1, 'validity.inconsistency_pairs['.$idx.'] must be [a,b].');

                continue;
            }
            $a = (int) ($pair[0] ?? 0);
            $b = (int) ($pair[1] ?? 0);
            if ($a < 1 || $a > 60 || $b < 1 || $b > 60 || $a === $b) {
                $errors[] = $this->error($file, 1, 'validity.inconsistency_pairs['.$idx.'] has invalid values.');
            }
        }

        $inconsistencyC = (int) data_get($doc, 'validity.inconsistency_thresholds.C_at_or_above', 0);
        $inconsistencyD = (int) data_get($doc, 'validity.inconsistency_thresholds.D_at_or_above', 0);
        if ($inconsistencyC <= 0 || $inconsistencyD <= 0 || $inconsistencyD < $inconsistencyC) {
            $errors[] = $this->error($file, 1, 'validity.inconsistency_thresholds invalid.');
        }

        $priority = array_values(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            (array) data_get($doc, 'tags.primary_profile_priority', [])
        )));
        if ($priority === []) {
            $errors[] = $this->error($file, 1, 'tags.primary_profile_priority cannot be empty.');
        }

        $rules = (array) data_get($doc, 'tags.rules', []);
        if ($rules === []) {
            $errors[] = $this->error($file, 1, 'tags.rules cannot be empty.');
        }
        foreach ($rules as $idx => $rule) {
            if (! is_array($rule)) {
                $errors[] = $this->error($file, 1, 'tags.rules['.$idx.'] must be object.');

                continue;
            }
            $tag = trim((string) ($rule['tag'] ?? ''));
            if ($tag === '') {
                $errors[] = $this->error($file, 1, 'tags.rules['.$idx.'].tag is required.');
            }

            $all = (array) data_get($rule, 'when.all', []);
            if ($all === []) {
                $errors[] = $this->error($file, 1, 'tags.rules['.$idx.'].when.all cannot be empty.');

                continue;
            }

            foreach ($all as $cIdx => $condition) {
                if (! is_array($condition)) {
                    $errors[] = $this->error($file, 1, 'tags.rules['.$idx.'].when.all['.$cIdx.'] must be object.');

                    continue;
                }
                $metric = strtoupper(trim((string) ($condition['metric'] ?? '')));
                if (! in_array($metric, self::REQUIRED_DIMENSIONS, true)) {
                    $errors[] = $this->error($file, 1, 'tags.rules['.$idx.'].when.all['.$cIdx.'].metric must be SA/ER/EM/RM.');
                }

                $op = trim((string) ($condition['op'] ?? ''));
                if (! in_array($op, ['>', '>=', '<', '<=', '==', '!='], true)) {
                    $errors[] = $this->error($file, 1, 'tags.rules['.$idx.'].when.all['.$cIdx.'].op invalid.');
                }

                if (! is_numeric($condition['value'] ?? null)) {
                    $errors[] = $this->error($file, 1, 'tags.rules['.$idx.'].when.all['.$cIdx.'].value must be numeric.');
                }
            }
        }

        $sectionsFree = array_values(array_map('strval', (array) data_get($doc, 'report.sections_free', [])));
        $sectionsFull = array_values(array_map('strval', (array) data_get($doc, 'report.sections_full', [])));
        if ($sectionsFree === [] || $sectionsFull === []) {
            $errors[] = $this->error($file, 1, 'report.sections_free and report.sections_full are required.');
        }

        $modulesFree = array_values(array_filter(array_map('strval', (array) data_get($doc, 'report.access_modules.free', []))));
        $modulesPaid = array_values(array_filter(array_map('strval', (array) data_get($doc, 'report.access_modules.paid', []))));
        if ($modulesFree === [] || $modulesPaid === []) {
            $errors[] = $this->error($file, 1, 'report.access_modules.free/paid cannot be empty.');
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintLanding(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('landing_i18n.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'landing_i18n.json invalid.');

            return;
        }

        foreach (['zh-CN', 'en'] as $locale) {
            $node = is_array($doc[$locale] ?? null) ? $doc[$locale] : null;
            if (! is_array($node)) {
                $errors[] = $this->error($file, 1, 'landing node missing for '.$locale.'.');

                continue;
            }

            if (trim((string) ($node['title'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, 'title is required for '.$locale.'.');
            }

            $consentVersion = trim((string) data_get($node, 'consent.version', ''));
            $consentText = trim((string) data_get($node, 'consent.text', ''));
            if ($consentVersion === '' || $consentText === '') {
                $errors[] = $this->error($file, 1, 'consent version/text missing for '.$locale.'.');
            }

            $disclaimerVersion = trim((string) data_get($node, 'disclaimer.version', ''));
            $disclaimerText = trim((string) data_get($node, 'disclaimer.text', ''));
            if ($disclaimerVersion === '' || $disclaimerText === '') {
                $errors[] = $this->error($file, 1, 'disclaimer version/text missing for '.$locale.'.');
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     * @return array<string,mixed>
     */
    private function lintReportLayout(string $version, array &$errors): array
    {
        $file = $this->loader->rawPath('report_layout.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'report_layout.json invalid.');

            return [];
        }

        $sections = is_array($doc['sections'] ?? null) ? $doc['sections'] : [];
        if ($sections === []) {
            $errors[] = $this->error($file, 1, 'report_layout.sections cannot be empty.');

            return [];
        }

        $seen = [];
        foreach ($sections as $idx => $section) {
            if (! is_array($section)) {
                $errors[] = $this->error($file, 1, 'sections['.$idx.'] must be object.');

                continue;
            }

            $key = trim((string) ($section['key'] ?? ''));
            if ($key === '') {
                $errors[] = $this->error($file, 1, 'sections['.$idx.'].key is required.');

                continue;
            }
            if (isset($seen[$key])) {
                $errors[] = $this->error($file, 1, 'duplicate section key: '.$key);
            }
            $seen[$key] = true;

            $source = strtolower(trim((string) ($section['source'] ?? '')));
            if (! in_array($source, ['copy', 'blocks'], true)) {
                $errors[] = $this->error($file, 1, 'sections['.$idx.'].source must be copy|blocks.');
            }

            $accessLevel = strtolower(trim((string) ($section['access_level'] ?? '')));
            if (! in_array($accessLevel, ['free', 'paid'], true)) {
                $errors[] = $this->error($file, 1, 'sections['.$idx.'].access_level must be free|paid.');
            }

            $requiredVariants = array_values(array_map(
                static fn ($variant): string => strtolower(trim((string) $variant)),
                (array) ($section['required_in_variant'] ?? [])
            ));
            if ($requiredVariants === []) {
                $errors[] = $this->error($file, 1, 'sections['.$idx.'].required_in_variant cannot be empty.');
            }
            foreach ($requiredVariants as $variant) {
                if (! in_array($variant, ['free', 'full'], true)) {
                    $errors[] = $this->error($file, 1, 'sections['.$idx.'].required_in_variant contains invalid variant.');
                }
            }

            $minBlocks = (int) ($section['min_blocks'] ?? -1);
            $maxBlocks = (int) ($section['max_blocks'] ?? -1);
            if ($minBlocks < 0 || $maxBlocks < 0 || $maxBlocks < $minBlocks) {
                $errors[] = $this->error($file, 1, 'sections['.$idx.'].min_blocks/max_blocks invalid.');
            }
        }

        foreach (self::REQUIRED_LAYOUT_SECTIONS as $requiredSection) {
            if (! isset($seen[$requiredSection])) {
                $errors[] = $this->error($file, 1, 'required section missing: '.$requiredSection);
            }
        }

        return $doc;
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     * @return array{required:list<string>,allowed:list<string>}
     */
    private function lintVariablesAllowlist(string $version, array &$errors): array
    {
        $file = $this->loader->rawPath('variables_allowlist.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'variables_allowlist.json invalid.');

            return ['required' => [], 'allowed' => []];
        }

        $required = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            (array) ($doc['required'] ?? [])
        ))));
        $allowed = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            (array) ($doc['allowed'] ?? ($doc['variables'] ?? []))
        ))));

        if ($allowed === []) {
            $errors[] = $this->error($file, 1, 'variables_allowlist.allowed cannot be empty.');
        }

        $allowedSet = array_fill_keys($allowed, true);
        foreach ($required as $var) {
            if (! isset($allowedSet[$var])) {
                $errors[] = $this->error($file, 1, 'required variable not present in allowed: '.$var);
            }
        }

        return [
            'required' => $required,
            'allowed' => $allowed,
        ];
    }

    /**
     * @param  array<string,mixed>  $layoutDoc
     * @param  array{required:list<string>,allowed:list<string>}  $allowlist
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintBlocks(string $version, array $layoutDoc, array $allowlist, array &$errors): void
    {
        $layoutSections = [];
        foreach ((array) ($layoutDoc['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionKey = trim((string) ($section['key'] ?? ''));
            if ($sectionKey === '') {
                continue;
            }

            $layoutSections[$sectionKey] = [
                'access_level' => strtolower(trim((string) ($section['access_level'] ?? 'free'))),
                'min_blocks' => max(0, (int) ($section['min_blocks'] ?? 0)),
            ];
        }

        $allowedVars = array_fill_keys($allowlist['allowed'], true);
        $counts = [];
        $seenBlockIds = [];

        $sources = [
            ['file' => 'blocks/free_blocks.json', 'expected_access_level' => 'free'],
            // The file name is retained for old pack shape compatibility, but
            // EQ-60 v5 currently exposes every report section for free.
            ['file' => 'blocks/paid_blocks.json', 'expected_access_level' => 'free'],
        ];

        foreach ($sources as $source) {
            $file = $this->loader->rawPath((string) $source['file'], $version);
            $expectedAccessLevel = (string) $source['expected_access_level'];
            $doc = $this->loader->readJson($file);
            if (! is_array($doc)) {
                $errors[] = $this->error($file, 1, 'blocks json invalid.');

                continue;
            }

            $blocks = (array) ($doc['blocks'] ?? []);
            if ($blocks === []) {
                $errors[] = $this->error($file, 1, 'blocks array cannot be empty.');

                continue;
            }

            foreach ($blocks as $idx => $block) {
                if (! is_array($block)) {
                    $errors[] = $this->error($file, 1, 'blocks['.$idx.'] must be object.');

                    continue;
                }

                $line = $idx + 1;
                $blockId = trim((string) ($block['block_id'] ?? ''));
                if ($blockId === '') {
                    $errors[] = $this->error($file, $line, 'block_id is required.');

                    continue;
                }
                if (isset($seenBlockIds[$blockId])) {
                    $errors[] = $this->error($file, $line, 'duplicate block_id: '.$blockId);
                }
                $seenBlockIds[$blockId] = true;

                $section = trim((string) ($block['section'] ?? ''));
                if ($section === '' || ! isset($layoutSections[$section])) {
                    $errors[] = $this->error($file, $line, 'section must exist in report_layout.sections.');

                    continue;
                }

                $accessLevel = strtolower(trim((string) ($block['access_level'] ?? '')));
                if (! in_array($accessLevel, ['free', 'paid'], true)) {
                    $errors[] = $this->error($file, $line, 'access_level must be free|paid.');
                }
                if ($accessLevel !== $expectedAccessLevel) {
                    $errors[] = $this->error($file, $line, 'block access_level does not match file scope.');
                }
                if ($accessLevel !== $layoutSections[$section]['access_level']) {
                    $errors[] = $this->error($file, $line, 'block access_level must match section access_level in layout.');
                }

                $locale = $this->loader->normalizeLocale((string) ($block['locale'] ?? 'zh-CN'));
                $moduleCode = trim((string) ($block['module_code'] ?? ''));
                if ($moduleCode === '') {
                    $errors[] = $this->error($file, $line, 'module_code is required.');
                }

                $title = trim((string) ($block['title'] ?? ''));
                $body = trim((string) ($block['body'] ?? ''));
                if ($title === '') {
                    $errors[] = $this->error($file, $line, 'title is required.');
                }
                if ($body === '') {
                    $errors[] = $this->error($file, $line, 'body is required.');
                }

                $variables = array_values(array_unique(array_filter(array_map(
                    static fn ($v): string => trim((string) $v),
                    (array) ($block['variables'] ?? [])
                ))));
                foreach ($variables as $variable) {
                    if (! isset($allowedVars[$variable])) {
                        $errors[] = $this->error($file, $line, 'variables[] contains unknown key: '.$variable);
                    }
                }

                $templateVars = array_values(array_unique(array_merge(
                    $this->extractTemplateVars($title),
                    $this->extractTemplateVars($body)
                )));
                foreach ($templateVars as $templateVar) {
                    if (! isset($allowedVars[$templateVar])) {
                        $errors[] = $this->error($file, $line, 'template uses variable not in allowlist: '.$templateVar);
                    }
                }

                $tagsAny = array_values(array_filter(array_map(
                    static fn ($tag): string => trim((string) $tag),
                    (array) ($block['tags_any'] ?? [])
                )));
                $tagsAll = array_values(array_filter(array_map(
                    static fn ($tag): string => trim((string) $tag),
                    (array) ($block['tags_all'] ?? [])
                )));
                $this->validateTagSelectors($file, $line, $tagsAny, $tagsAll, $errors);

                if (! in_array('section:'.$section, $tagsAll, true)) {
                    $errors[] = $this->error($file, $line, 'tags_all must contain section:'.$section.'.');
                }

                if (! is_numeric($block['priority'] ?? null)) {
                    $errors[] = $this->error($file, $line, 'priority must be numeric.');
                }

                $counts[$section][$locale] = ($counts[$section][$locale] ?? 0) + 1;
            }
        }

        foreach ($layoutSections as $section => $meta) {
            $minBlocks = (int) ($meta['min_blocks'] ?? 0);
            if ($minBlocks <= 0) {
                continue;
            }

            foreach (['zh-CN', 'en'] as $locale) {
                $count = (int) ($counts[$section][$locale] ?? 0);
                if ($count < $minBlocks) {
                    $errors[] = $this->error(
                        $this->loader->rawPath('report_layout.json', $version),
                        1,
                        'section '.$section.' lacks enough blocks for locale='.$locale.' (min='.$minBlocks.', actual='.$count.').'
                    );
                }
            }
        }
    }

    /**
     * @param  list<string>  $tagsAny
     * @param  list<string>  $tagsAll
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function validateTagSelectors(string $file, int $line, array $tagsAny, array $tagsAll, array &$errors): void
    {
        $allTags = array_values(array_unique(array_merge($tagsAny, $tagsAll)));
        foreach ($allTags as $tag) {
            if ($tag === '' || ! str_contains($tag, ':')) {
                $errors[] = $this->error($file, $line, 'tag must be non-empty and include prefix:value format.');

                continue;
            }

            [$prefix] = explode(':', $tag, 2);
            $prefix = trim($prefix);
            if (! in_array($prefix, self::ALLOWED_TAG_PREFIXES, true)) {
                $errors[] = $this->error($file, $line, 'tag prefix not allowed: '.$prefix);
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintReportAssets(string $version, array &$errors): void
    {
        $requiredFiles = [
            'scientific_contract',
            'score_system',
            'core_formulations',
            'mechanism_map',
            'reality_translation',
            'career_environment',
            'action_prescriptions',
            'cross_assessment_context',
            'seo_geo_authority',
            'sjt_bridge',
            'result_snapshot',
            'commercial_conversion_assets',
            'quality_confidence',
            'psychometric_evidence_status',
            'result_page_depth_modules',
            'agent_knowledge_base_schema',
            'agent_dialogue_playbooks',
            'backend_integration_contract',
        ];

        $docs = [];
        foreach ($requiredFiles as $key) {
            $file = $this->loader->rawPath('report_assets/'.$key.'.json', $version);
            $doc = $this->loader->readJson($file);
            if (! is_array($doc)) {
                $errors[] = $this->error($file, 1, 'report asset json invalid or missing.');

                continue;
            }
            if (trim((string) ($doc['schema'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, 'schema is required.');
            }
            if ((string) ($doc['pack_id'] ?? '') !== Eq60PackLoader::PACK_ID) {
                $errors[] = $this->error($file, 1, 'pack_id must be EQ_60.');
            }
            $docs[$key] = $doc;
        }

        if (count($docs) !== count($requiredFiles)) {
            return;
        }

        $scientificAssets = (array) data_get($docs, 'scientific_contract.assets', []);
        $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/scientific_contract.json', $version), (array) ($scientificAssets['eq.scientific_contract.default'] ?? []), [
            'test_definition',
            'self_report_statement',
            'non_clinical_statement',
            'non_hiring_statement',
            'non_ability_statement',
            'norm_status_statement',
            'quality_rules_statement',
            'version_statement',
        ], $errors);

        foreach (['foundational', 'developing', 'stable', 'proficient', 'integrated'] as $band) {
            $this->lintLocalizedScalar($this->loader->rawPath('report_assets/score_system.json', $version), data_get($docs, 'score_system.bands.'.$band), 'bands.'.$band, $errors);
        }
        foreach (self::REQUIRED_DIMENSIONS as $dimension) {
            $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/score_system.json', $version), (array) data_get($docs, 'score_system.dimensions.'.$dimension), ['label', 'definition'], $errors);
        }

        $formulationIds = [
            'balanced_integrated',
            'high_empathy_low_recovery',
            'aware_but_unregulated',
            'calm_but_distant',
            'relationship_first_self_later',
            'self_clear_repair_weak',
            'steady_collaborator',
            'sensitive_absorber',
            'developing_foundation',
            'low_confidence_result',
        ];
        foreach ($formulationIds as $id) {
            $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/core_formulations.json', $version), (array) data_get($docs, 'core_formulations.formulations.'.$id), [
                'title',
                'one_liner',
                'core_claim',
                'evidence_basis',
                'primary_strength',
                'likely_cost',
                'development_lever',
                'do_not_overread',
            ], $errors);
        }

        foreach (['SA_ER', 'EM_ER', 'EM_RM', 'SA_RM', 'ER_RM'] as $pair) {
            foreach (['high_high', 'high_low', 'low_high', 'low_low'] as $state) {
                $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/mechanism_map.json', $version), (array) data_get($docs, 'mechanism_map.pairs.'.$pair.'.'.$state), [
                    'title',
                    'why_it_matters',
                    'what_it_feels_like',
                    'strength',
                    'cost',
                    'development_lever',
                    'micro_action',
                ], $errors);
            }
        }

        foreach (['feedback', 'conflict', 'relationship_boundary', 'team_collaboration', 'pressure_recovery', 'career_environment'] as $scene) {
            $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/reality_translation.json', $version), (array) data_get($docs, 'reality_translation.scenes.'.$scene), [
                'title',
                'typical_response',
                'strength',
                'cost',
                'better_move',
            ], $errors);
        }

        foreach (['interpersonal_density', 'emotional_labor', 'conflict_frequency', 'feedback_intensity', 'autonomy_recovery', 'collaboration_complexity'] as $variable) {
            foreach (['low', 'medium', 'high'] as $level) {
                $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/career_environment.json', $version), (array) data_get($docs, 'career_environment.variables.'.$variable.'.'.$level), [
                    'label',
                    'meaning',
                    'fit_signal',
                    'strain_signal',
                    'what_to_verify',
                ], $errors);
            }
        }

        foreach ([
            'emotion_labeling',
            'pause_recovery',
            'feedback_decompression',
            'empathy_boundary',
            'repair_after_conflict',
            'express_without_escalation',
            'support_without_rescuing',
            'cold_to_warm_response',
            'relationship_energy_management',
            'conflict_deescalation',
            'self_connection',
            'retest_reflection',
        ] as $prescription) {
            $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/action_prescriptions.json', $version), (array) data_get($docs, 'action_prescriptions.prescriptions.'.$prescription), [
                'title',
                'why_this_matters',
                'do_today',
                'script',
                'seven_day_plan',
                'watch_out',
            ], $errors);
        }

        foreach ([
            'eq.cross_context.boundary.default',
            'eq.cross_context.mbti.available',
            'eq.cross_context.big_five.available',
            'eq.cross_context.enneagram.available',
        ] as $assetId) {
            $crossContextAssets = (array) data_get($docs, 'cross_assessment_context.assets', []);
            $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/cross_assessment_context.json', $version), (array) ($crossContextAssets[$assetId] ?? []), [
                'title',
                'summary',
                'how_to_use',
                'claim_boundary',
            ], $errors);
        }

        $seoGeoAssets = (array) data_get($docs, 'seo_geo_authority.assets', []);
        $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/seo_geo_authority.json', $version), (array) ($seoGeoAssets['eq.seo_geo_authority.en_landing.default'] ?? []), [
            'meta_title',
            'meta_description',
            'h1',
            'dek',
            'entity_summary',
            'llms_summary',
            'claim_boundary',
            'source_authority',
        ], $errors);

        $sjtAssets = (array) data_get($docs, 'sjt_bridge.assets', []);
        $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/sjt_bridge.json', $version), (array) ($sjtAssets['eq.sjt_bridge.planned'] ?? []), [
            'title',
            'status',
            'description',
            'what_it_adds',
            'what_it_is_not',
            'button_label',
            'available',
        ], $errors);

        $resultSnapshotAssets = (array) data_get($docs, 'result_snapshot.assets', []);
        $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/result_snapshot.json', $version), (array) ($resultSnapshotAssets['eq.snapshot.high_empathy_low_recovery'] ?? []), [
            'headline',
            'core_judgment',
            'evidence_point',
            'minimal_action',
            'share_safe_sentence',
            'continue_path',
        ], $errors);
        $highEmpathySnapshot = (array) ($resultSnapshotAssets['eq.snapshot.high_empathy_low_recovery'] ?? []);
        foreach (['zh-CN', 'en'] as $locale) {
            if ((array) data_get($highEmpathySnapshot, $locale.'.conversion_actions', []) === []) {
                $errors[] = $this->error($this->loader->rawPath('report_assets/result_snapshot.json', $version), 1, $locale.'.conversion_actions cannot be empty.');
            }
        }

        $conversionAssets = (array) data_get($docs, 'commercial_conversion_assets.assets', []);
        foreach ([
            'eq.conversion.save_report',
            'eq.conversion.email_revisit',
            'eq.conversion.pdf_export',
            'eq.conversion.share_card',
            'eq.conversion.retest_reminder',
            'eq.conversion.related_tests',
            'eq.conversion.agent_entry',
        ] as $assetId) {
            $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/commercial_conversion_assets.json', $version), (array) ($conversionAssets[$assetId] ?? []), [
                'title',
                'body',
                'cta_label',
                'do_not_overread',
            ], $errors);
        }

        $conversionJson = json_encode(data_get($docs, 'commercial_conversion_assets.assets', []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        foreach (['购买', '解锁', 'paywall', 'SKU_EQ_60_FULL_299', 'EQ_60_FULL'] as $blockedTerm) {
            if (str_contains($conversionJson, $blockedTerm)) {
                $errors[] = $this->error($this->loader->rawPath('report_assets/commercial_conversion_assets.json', $version), 1, 'commercial conversion assets must not contain blocked term: '.$blockedTerm);
            }
        }

        $qualityAssets = (array) data_get($docs, 'quality_confidence.assets', []);
        foreach (['eq.quality.level.A', 'eq.quality.level.B', 'eq.quality.level.C', 'eq.quality.level.D'] as $assetId) {
            $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/quality_confidence.json', $version), (array) ($qualityAssets[$assetId] ?? []), [
                'label',
                'body',
                'user_guidance',
                'retest_note',
                'why_this_level',
                'how_to_read',
            ], $errors);
        }

        $evidenceAssets = (array) data_get($docs, 'psychometric_evidence_status.assets', []);
        $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/psychometric_evidence_status.json', $version), (array) ($evidenceAssets['eq.evidence.content_validity'] ?? []), [
            'status',
            'body',
            'user_facing_status_label',
            'what_this_means_for_user',
            'next_validation_step',
        ], $errors);

        $depthAssets = (array) data_get($docs, 'result_page_depth_modules.assets', []);
        foreach ([
            'eq.depth.evidence_stack.default',
            'eq.depth.how_to_read.default',
            'eq.depth.reality_check.default',
        ] as $assetId) {
            $asset = (array) ($depthAssets[$assetId] ?? []);
            $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/result_page_depth_modules.json', $version), $asset, [
                'title',
                'body',
            ], $errors);

            $claimRisk = strtolower(trim((string) data_get($asset, 'meta.claim_risk', '')));
            if (! in_array($claimRisk, ['low', 'medium', 'high'], true)) {
                $errors[] = $this->error($this->loader->rawPath('report_assets/result_page_depth_modules.json', $version), 1, $assetId.'.meta.claim_risk must be low, medium, or high.');
            }
        }

        $agentKnowledgeFile = $this->loader->rawPath('report_assets/agent_knowledge_base_schema.json', $version);
        if ((string) data_get($docs, 'agent_knowledge_base_schema.schema') !== 'eq60.report_assets.agent_knowledge_base_schema.v1') {
            $errors[] = $this->error($agentKnowledgeFile, 1, 'schema must be eq60.report_assets.agent_knowledge_base_schema.v1.');
        }
        if ((string) data_get($docs, 'agent_knowledge_base_schema.authority.report_authority') !== 'backend_content_pack_and_report_composer') {
            $errors[] = $this->error($agentKnowledgeFile, 1, 'authority.report_authority must remain backend_content_pack_and_report_composer.');
        }

        foreach ([
            'mutate_scores',
            'override_formulation_selection',
            'rewrite_report_authority',
            'enable_sjt',
            'create_paid_unlock_language',
            'expose_raw_technical_tags',
            'make_clinical_or_hiring_decisions',
        ] as $blockedAgentAction) {
            if (! in_array($blockedAgentAction, (array) data_get($docs, 'agent_knowledge_base_schema.authority.agent_must_not', []), true)) {
                $errors[] = $this->error($agentKnowledgeFile, 1, 'authority.agent_must_not missing: '.$blockedAgentAction);
            }
        }

        foreach ([
            'asset:scientific_contract',
            'asset:core_formulation',
            'asset:action_prescription',
            'dimension:SA',
            'dimension:ER',
            'dimension:EM',
            'dimension:RM',
            'quality:low_confidence',
            'risk:sjt_unavailable',
        ] as $requiredRetrievalTag) {
            if (! in_array($requiredRetrievalTag, (array) data_get($docs, 'agent_knowledge_base_schema.retrieval_tag_taxonomy.core_tags', []), true)) {
                $errors[] = $this->error($agentKnowledgeFile, 1, 'retrieval_tag_taxonomy.core_tags missing: '.$requiredRetrievalTag);
            }
        }

        foreach ([
            'scientific_contract',
            'score_system',
            'core_formulation',
            'mechanism',
            'reality_scene',
            'career_environment',
            'action_prescription',
            'quality_confidence',
            'psychometric_evidence',
            'conversion_action',
            'sjt_bridge',
            'cross_assessment_context',
        ] as $assetType) {
            $assetTypeNode = (array) data_get($docs, 'agent_knowledge_base_schema.asset_type_taxonomy.'.$assetType, []);
            if ($assetTypeNode === []) {
                $errors[] = $this->error($agentKnowledgeFile, 1, 'asset_type_taxonomy missing: '.$assetType);

                continue;
            }
            foreach (['allowed_use', 'blocked_use', 'claim_risk'] as $field) {
                if (trim((string) ($assetTypeNode[$field] ?? '')) === '') {
                    $errors[] = $this->error($agentKnowledgeFile, 1, 'asset_type_taxonomy.'.$assetType.'.'.$field.' is required.');
                }
            }
        }

        foreach ([
            'understand_my_result',
            'why_this_result',
            'how_to_improve',
            'career_environment_fit',
            'relationship_or_conflict_help',
            'quality_or_confidence_question',
            'compare_with_other_tests',
            'ask_for_sjt',
            'share_or_save_report',
            'clinical_or_hiring_request',
        ] as $intentId) {
            $intent = (array) data_get($docs, 'agent_knowledge_base_schema.user_intent_map.intents.'.$intentId, []);
            if ((string) ($intent['intent_id'] ?? '') !== $intentId) {
                $errors[] = $this->error($agentKnowledgeFile, 1, 'user_intent_map intent_id mismatch: '.$intentId);
            }
            foreach (['retrieval_tags', 'preferred_asset_types', 'forbidden_claim_ids'] as $arrayField) {
                if ((array) ($intent[$arrayField] ?? []) === []) {
                    $errors[] = $this->error($agentKnowledgeFile, 1, 'user_intent_map.'.$intentId.'.'.$arrayField.' cannot be empty.');
                }
            }
            $this->lintLocalizedAssetFields($agentKnowledgeFile, $intent, [
                'label',
                'agent_goal',
                'safe_opening',
            ], $errors);
        }

        foreach ([
            'true_emotional_ability',
            'msceit_like',
            'certified_ei',
            'clinical_diagnosis',
            'hiring_suitability',
            'job_performance_prediction',
            'guaranteed_outcome',
            'paid_unlock_required',
        ] as $claimId) {
            $claim = (array) data_get($docs, 'agent_knowledge_base_schema.forbidden_claims.claims.'.$claimId, []);
            if ($claim === []) {
                $errors[] = $this->error($agentKnowledgeFile, 1, 'forbidden_claims missing: '.$claimId);

                continue;
            }
            if ((array) ($claim['blocked_patterns'] ?? []) === []) {
                $errors[] = $this->error($agentKnowledgeFile, 1, 'forbidden_claims.'.$claimId.'.blocked_patterns cannot be empty.');
            }
            $this->lintLocalizedScalar($agentKnowledgeFile, (array) ($claim['replacement_boundary'] ?? []), 'forbidden_claims.'.$claimId.'.replacement_boundary', $errors);
        }

        foreach ([
            'self_harm_or_crisis',
            'clinical_distress',
            'workplace_hiring_decision',
            'legal_or_medical_advice',
            'sjt_availability_request',
            'low_confidence_result',
            'raw_payload_debug_request',
        ] as $flagId) {
            $flag = (array) data_get($docs, 'agent_knowledge_base_schema.escalation_flags.'.$flagId, []);
            if ($flag === []) {
                $errors[] = $this->error($agentKnowledgeFile, 1, 'escalation_flags missing: '.$flagId);

                continue;
            }
            $this->lintLocalizedAssetFields($agentKnowledgeFile, $flag, [
                'label',
                'agent_action',
            ], $errors);
        }

        if ((string) data_get($docs, 'agent_knowledge_base_schema.maintenance.agent_runtime_status') !== 'not_implemented') {
            $errors[] = $this->error($agentKnowledgeFile, 1, 'maintenance.agent_runtime_status must remain not_implemented.');
        }
        if ((string) data_get($docs, 'agent_knowledge_base_schema.maintenance.sjt_status') !== 'planned_unavailable') {
            $errors[] = $this->error($agentKnowledgeFile, 1, 'maintenance.sjt_status must remain planned_unavailable.');
        }

        $agentPlaybookAssets = (array) data_get($docs, 'agent_dialogue_playbooks.assets', []);
        $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/agent_dialogue_playbooks.json', $version), (array) ($agentPlaybookAssets['eq.agent.playbook.understand_result'] ?? []), [
            'title',
            'response_policy',
            'clarifying_question',
            'safe_response_example',
            'refusal_example',
            'escalation_rule',
        ], $errors);

        $backendContractAssets = (array) data_get($docs, 'backend_integration_contract.assets', []);
        $this->lintLocalizedAssetFields($this->loader->rawPath('report_assets/backend_integration_contract.json', $version), (array) ($backendContractAssets['eq.backend_contract.schema_mapping'] ?? []), [
            'title',
            'requirement',
            'acceptance',
            'do_not_overread',
        ], $errors);
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintPersonalizationRoutes(string $version, array &$errors): void
    {
        $file = $this->loader->rawPath('personalization_routes/route_matrix.json', $version);
        $doc = $this->loader->readJson($file);
        if (! is_array($doc)) {
            $errors[] = $this->error($file, 1, 'personalization route matrix json invalid or missing.');

            return;
        }

        if ((string) ($doc['schema'] ?? '') !== 'eq60.personalization_routes.route_matrix.v1') {
            $errors[] = $this->error($file, 1, 'schema must be eq60.personalization_routes.route_matrix.v1.');
        }
        if ((string) ($doc['pack_id'] ?? '') !== Eq60PackLoader::PACK_ID) {
            $errors[] = $this->error($file, 1, 'pack_id must be EQ_60.');
        }

        $routes = is_array($doc['routes'] ?? null) ? $doc['routes'] : [];
        foreach ([
            'balanced_integrated',
            'high_empathy_low_recovery',
            'aware_but_unregulated',
            'low_confidence_result',
        ] as $requiredRoute) {
            if (! is_array($routes[$requiredRoute] ?? null)) {
                $errors[] = $this->error($file, 1, 'required personalization route missing: '.$requiredRoute);
            }
        }

        foreach ($routes as $routeId => $route) {
            if (! is_array($route)) {
                $errors[] = $this->error($file, 1, 'route must be an object: '.(string) $routeId);

                continue;
            }

            $normalizedRouteId = trim((string) ($route['route_id'] ?? ''));
            $formulationId = trim((string) ($route['formulation_id'] ?? ''));
            $selected = is_array($route['selected_asset_ids'] ?? null) ? $route['selected_asset_ids'] : [];
            if ($normalizedRouteId === '' || $normalizedRouteId !== (string) $routeId) {
                $errors[] = $this->error($file, 1, 'route_id must match route key: '.(string) $routeId);
            }
            if ($formulationId === '') {
                $errors[] = $this->error($file, 1, 'formulation_id is required for route: '.(string) $routeId);
            }
            if ((string) ($selected['core_formulation_id'] ?? '') !== $formulationId) {
                $errors[] = $this->error($file, 1, 'selected_asset_ids.core_formulation_id must match formulation_id for route: '.(string) $routeId);
            }
            foreach (['mechanism_ids', 'scene_ids', 'career_environment_ids'] as $listKey) {
                if (! is_array($selected[$listKey] ?? null)) {
                    $errors[] = $this->error($file, 1, 'selected_asset_ids.'.$listKey.' must be a list for route: '.(string) $routeId);
                }
            }
            if (trim((string) ($selected['action_prescription_id'] ?? '')) === '') {
                $errors[] = $this->error($file, 1, 'selected_asset_ids.action_prescription_id is required for route: '.(string) $routeId);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $asset
     * @param  list<string>  $fields
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintLocalizedAssetFields(string $file, array $asset, array $fields, array &$errors): void
    {
        foreach (['zh-CN', 'en'] as $locale) {
            $node = is_array($asset[$locale] ?? null) ? $asset[$locale] : null;
            if (! is_array($node)) {
                $errors[] = $this->error($file, 1, 'localized asset missing for '.$locale.'.');

                continue;
            }

            foreach ($fields as $field) {
                $value = $node[$field] ?? null;
                if (is_array($value)) {
                    if ($value === []) {
                        $errors[] = $this->error($file, 1, $locale.'.'.$field.' cannot be empty.');
                    }

                    continue;
                }
                if (is_bool($value)) {
                    continue;
                }
                if (trim((string) $value) === '') {
                    $errors[] = $this->error($file, 1, $locale.'.'.$field.' is required.');
                }
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintLocalizedScalar(string $file, mixed $node, string $path, array &$errors): void
    {
        if (! is_array($node)) {
            $errors[] = $this->error($file, 1, $path.' must be localized object.');

            return;
        }

        foreach (['zh-CN', 'en'] as $locale) {
            $value = $node[$locale] ?? '';
            if (is_array($value)) {
                $errors[] = $this->error($file, 1, $path.'.'.$locale.' must be scalar text.');

                continue;
            }
            if (trim((string) $value) === '') {
                $errors[] = $this->error($file, 1, $path.'.'.$locale.' is required.');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractTemplateVars(string $template): array
    {
        if ($template === '') {
            return [];
        }

        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', $template, $matches) !== 1) {
            return [];
        }

        $vars = is_array($matches[1] ?? null) ? $matches[1] : [];

        return array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $vars
        ))));
    }

    /**
     * @param  array<int,array{dimension:string,direction:int}>  $questionIndex
     * @param  list<array{file:string,line:int,message:string}>  $errors
     */
    private function lintGoldenCases(string $version, array $questionIndex, array &$errors): void
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

            $caseId = trim((string) ($row['case_id'] ?? ''));
            if ($caseId === '') {
                $errors[] = $this->error($file, $line, 'case_id is required.');
            }

            $locale = trim((string) ($row['locale'] ?? ''));
            if (! in_array($locale, ['zh-CN', 'en'], true)) {
                $errors[] = $this->error($file, $line, 'locale must be zh-CN or en.');
            }

            $answersMap = $this->parseGoldenAnswersJson($file, $line, (string) ($row['answers_json'] ?? ''), $errors);
            if (count($answersMap) !== self::REQUIRED_QUESTION_COUNT) {
                $errors[] = $this->error($file, $line, 'answers_json must contain exactly 60 answers.');
            }

            foreach ($answersMap as $qid => $code) {
                if (! isset($questionIndex[$qid])) {
                    $errors[] = $this->error($file, $line, 'answers_json contains unknown question_id='.$qid.'.');
                }
                if (! in_array($code, ['A', 'B', 'C', 'D', 'E'], true)) {
                    $errors[] = $this->error($file, $line, 'answers_json code must be A-E.');
                }
            }

            $qualityLevel = strtoupper(trim((string) ($row['expected_quality_level'] ?? '')));
            if (! in_array($qualityLevel, ['A', 'B', 'C', 'D'], true)) {
                $errors[] = $this->error($file, $line, 'expected_quality_level must be A/B/C/D.');
            }

            $flags = json_decode((string) ($row['expected_quality_flags_json'] ?? '[]'), true);
            if (! is_array($flags)) {
                $errors[] = $this->error($file, $line, 'expected_quality_flags_json must be valid json array.');
            } else {
                foreach ($flags as $flag) {
                    $norm = strtoupper(trim((string) $flag));
                    if (! in_array($norm, self::QUALITY_FLAGS, true)) {
                        $errors[] = $this->error($file, $line, 'expected_quality_flags_json contains invalid flag: '.$norm);
                    }
                }
            }

            $primaryProfile = trim((string) ($row['expected_primary_profile'] ?? ''));
            if ($primaryProfile !== '' && ! str_starts_with($primaryProfile, 'profile:')) {
                $errors[] = $this->error($file, $line, 'expected_primary_profile must be empty or profile:*');
            }

            $tags = json_decode((string) ($row['expected_report_tags_json'] ?? '[]'), true);
            if (! is_array($tags)) {
                $errors[] = $this->error($file, $line, 'expected_report_tags_json must be valid json array.');
            }

            $dimLevels = json_decode((string) ($row['expected_dim_levels_json'] ?? '{}'), true);
            if (! is_array($dimLevels)) {
                $errors[] = $this->error($file, $line, 'expected_dim_levels_json must be valid json object.');
            } else {
                foreach (self::REQUIRED_DIMENSIONS as $dimension) {
                    $level = strtolower(trim((string) ($dimLevels[$dimension] ?? '')));
                    if (! in_array($level, self::MATURITY_LEVELS, true)) {
                        $errors[] = $this->error($file, $line, 'expected_dim_levels_json.'.$dimension.' must be valid maturity level.');
                    }
                }
            }

            $globalLevel = strtolower(trim((string) ($row['expected_global_level'] ?? '')));
            if ($globalLevel !== '' && ! in_array($globalLevel, self::MATURITY_LEVELS, true)) {
                $errors[] = $this->error($file, $line, 'expected_global_level must be valid maturity level.');
            }

            $freeSections = json_decode((string) ($row['expected_free_sections'] ?? '[]'), true);
            if (! is_array($freeSections) || $freeSections === []) {
                $errors[] = $this->error($file, $line, 'expected_free_sections must be valid json array and cannot be empty.');
            }

            $fullSections = json_decode((string) ($row['expected_full_sections'] ?? '[]'), true);
            if (! is_array($fullSections) || $fullSections === []) {
                $errors[] = $this->error($file, $line, 'expected_full_sections must be valid json array and cannot be empty.');
            }
        }
    }

    /**
     * @param  list<array{file:string,line:int,message:string}>  $errors
     * @return array<int,string>
     */
    private function parseGoldenAnswersJson(string $file, int $line, string $raw, array &$errors): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $errors[] = $this->error($file, $line, 'answers_json must be valid json array.');

            return [];
        }

        $out = [];
        foreach ($decoded as $idx => $item) {
            if (! is_array($item)) {
                $errors[] = $this->error($file, $line, 'answers_json['.$idx.'] must be object.');

                continue;
            }

            $qid = (int) ($item['question_id'] ?? 0);
            if ($qid < 1 || $qid > self::REQUIRED_QUESTION_COUNT) {
                $errors[] = $this->error($file, $line, 'answers_json['.$idx.'].question_id must be in 1..60.');

                continue;
            }

            if (isset($out[$qid])) {
                $errors[] = $this->error($file, $line, 'answers_json contains duplicate question_id='.$qid.'.');

                continue;
            }

            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            if (! in_array($code, ['A', 'B', 'C', 'D', 'E'], true)) {
                $errors[] = $this->error($file, $line, 'answers_json['.$idx.'].code must be A-E.');

                continue;
            }

            $out[$qid] = $code;
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }

    private function expectedDimensionForQuestion(int $qid): ?string
    {
        if ($qid >= 1 && $qid <= 15) {
            return 'SA';
        }
        if ($qid >= 16 && $qid <= 30) {
            return 'ER';
        }
        if ($qid >= 31 && $qid <= 45) {
            return 'EM';
        }
        if ($qid >= 46 && $qid <= 60) {
            return 'RM';
        }

        return null;
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
}
