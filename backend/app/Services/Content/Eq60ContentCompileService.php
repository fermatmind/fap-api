<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class Eq60ContentCompileService
{
    public function __construct(
        private readonly Eq60PackLoader $loader,
        private readonly Eq60ContentLintService $lint,
    ) {}

    /**
     * @return array{ok:bool,pack_id:string,version:string,compiled_dir:string,errors:list<array{file:string,line:int,message:string}>,hashes:array<string,string>}
     */
    public function compile(?string $version = null): array
    {
        $version = $this->loader->normalizeVersion($version);
        $lint = $this->lint->lint($version);
        if (! ($lint['ok'] ?? false)) {
            return [
                'ok' => false,
                'pack_id' => Eq60PackLoader::PACK_ID,
                'version' => $version,
                'compiled_dir' => $this->loader->compiledDir($version),
                'errors' => is_array($lint['errors'] ?? null) ? $lint['errors'] : [],
                'hashes' => [],
            ];
        }

        $compiledDir = $this->loader->compiledDir($version);
        if (! is_dir($compiledDir)) {
            File::makeDirectory($compiledDir, 0775, true, true);
        }

        foreach ([
            'questions.compiled.json',
            'options.compiled.json',
            'policy.compiled.json',
            'landing.compiled.json',
            'report.compiled.json',
            'golden_cases.compiled.json',
            'manifest.json',
        ] as $compiledFile) {
            $path = $this->loader->compiledPath($compiledFile, $version);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $questionRows = $this->loader->readCsvWithLines($this->loader->rawPath('questions_eq60_bilingual.csv', $version));
        $questionsZh = [];
        $questionsEn = [];
        $questionIndex = [];

        foreach ($questionRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            $dimension = strtoupper(trim((string) ($row['dimension'] ?? '')));
            $direction = (int) ($row['direction'] ?? 0);
            if ($qid <= 0 || ! in_array($dimension, ['SA', 'ER', 'EM', 'RM'], true) || ! in_array($direction, [1, -1], true)) {
                continue;
            }

            $questionIndex[(string) $qid] = [
                'dimension' => $dimension,
                'direction' => $direction,
            ];

            $questionsZh[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'dimension' => $dimension,
                'direction' => $direction,
                'text' => trim((string) ($row['text_zh'] ?? '')),
            ];

            $questionsEn[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'dimension' => $dimension,
                'direction' => $direction,
                'text' => trim((string) ($row['text_en'] ?? '')),
            ];
        }

        usort($questionsZh, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        usort($questionsEn, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        ksort($questionIndex, SORT_NATURAL);

        $optionsRaw = $this->loader->readJson($this->loader->rawPath('options_eq60_bilingual.json', $version)) ?? [];
        $codes = array_values(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) ($optionsRaw['codes'] ?? ['A', 'B', 'C', 'D', 'E'])
        ));

        $labelsByLocale = [
            'zh-CN' => array_values(array_map(static fn ($label): string => trim((string) $label), (array) data_get($optionsRaw, 'labels.zh-CN', []))),
            'en' => array_values(array_map(static fn ($label): string => trim((string) $label), (array) data_get($optionsRaw, 'labels.en', []))),
        ];

        $scoreMapRaw = is_array($optionsRaw['score_map'] ?? null) ? $optionsRaw['score_map'] : [];
        $scoreMap = [];
        foreach ($scoreMapRaw as $code => $value) {
            $normCode = strtoupper(trim((string) $code));
            if ($normCode === '') {
                continue;
            }
            $scoreMap[$normCode] = (int) $value;
        }

        $optionAnchorsByLocale = [
            'zh-CN' => $this->buildOptionAnchors($codes, $labelsByLocale['zh-CN']),
            'en' => $this->buildOptionAnchors($codes, $labelsByLocale['en']),
        ];

        $policyRaw = $this->loader->readJson($this->loader->rawPath('policy.json', $version)) ?? [];
        $landingRaw = $this->loader->readJson($this->loader->rawPath('landing_i18n.json', $version)) ?? [];
        $reportLayoutRaw = $this->loader->readJson($this->loader->rawPath('report_layout.json', $version)) ?? [];
        $reportFreeBlocksRaw = $this->loader->readJson($this->loader->rawPath('blocks/free_blocks.json', $version)) ?? [];
        $reportPaidBlocksRaw = $this->loader->readJson($this->loader->rawPath('blocks/paid_blocks.json', $version)) ?? [];
        $reportVariablesRaw = $this->loader->readJson($this->loader->rawPath('variables_allowlist.json', $version)) ?? [];

        $normalizedPolicy = $this->normalizePolicy($policyRaw);
        $normalizedLayout = $this->normalizeLayout($reportLayoutRaw);
        $normalizedBlocks = $this->normalizeBlocks($reportFreeBlocksRaw, $reportPaidBlocksRaw);
        $normalizedVariables = $this->normalizeVariablesAllowlist($reportVariablesRaw);
        $goldenCases = $this->compileGoldenCases($version);

        $files = [
            'questions.compiled.json' => [
                'schema' => 'eq_60.questions.compiled.v1',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'dimension_codes' => ['SA', 'ER', 'EM', 'RM'],
                'question_index' => $questionIndex,
                'questions_doc_by_locale' => [
                    'zh-CN' => [
                        'schema' => 'fap.questions.v1',
                        'locale' => 'zh-CN',
                        'items' => $questionsZh,
                    ],
                    'en' => [
                        'schema' => 'fap.questions.v1',
                        'locale' => 'en',
                        'items' => $questionsEn,
                    ],
                ],
            ],
            'options.compiled.json' => [
                'schema' => 'eq_60.options.compiled.v1',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'codes' => $codes,
                'labels_by_locale' => $labelsByLocale,
                'score_map' => $scoreMap,
                'option_anchors_by_locale' => $optionAnchorsByLocale,
            ],
            'policy.compiled.json' => [
                'schema' => 'eq_60.policy.compiled.v2',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'policy' => $normalizedPolicy,
            ],
            'landing.compiled.json' => [
                'schema' => 'eq_60.landing.compiled.v1',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'landing' => $landingRaw,
            ],
            'report.compiled.json' => [
                'schema' => 'eq_60.report.compiled.v2',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'layout' => $normalizedLayout,
                'blocks' => $normalizedBlocks,
                'variables_allowlist' => $normalizedVariables,
            ],
            'golden_cases.compiled.json' => [
                'schema' => 'eq_60.golden_cases.compiled.v2',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'cases' => $goldenCases,
            ],
        ];

        $hashes = [];
        foreach ($files as $name => $payload) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($json)) {
                continue;
            }

            File::put($this->loader->compiledPath($name, $version), $json."\n");
            $hashes[$name] = hash('sha256', $json);
        }

        $manifest = [
            'schema' => 'eq_60.compiled.manifest.v1',
            'pack_id' => Eq60PackLoader::PACK_ID,
            'pack_version' => $version,
            'compiled_at' => now()->toISOString(),
            'generated_at' => now()->toISOString(),
            'content_hash' => $this->hashDirectory($this->loader->rawDir($version)),
            'compiled_hash' => $this->hashMap($hashes),
            'hashes' => $hashes,
            'compiled_files' => array_keys($files),
        ];

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($manifestJson)) {
            File::put($this->loader->compiledPath('manifest.json', $version), $manifestJson."\n");
            $hashes['manifest.json'] = hash('sha256', $manifestJson);
        }

        return [
            'ok' => true,
            'pack_id' => Eq60PackLoader::PACK_ID,
            'version' => $version,
            'compiled_dir' => $compiledDir,
            'errors' => [],
            'hashes' => $hashes,
        ];
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $labels
     * @return list<array{code:string,label:string}>
     */
    private function buildOptionAnchors(array $codes, array $labels): array
    {
        $anchors = [];
        foreach ($codes as $idx => $codeRaw) {
            $code = strtoupper(trim((string) $codeRaw));
            $label = trim((string) ($labels[$idx] ?? ''));
            if ($code === '' || $label === '') {
                continue;
            }

            $anchors[] = [
                'code' => $code,
                'label' => $label,
            ];
        }

        return $anchors;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function normalizePolicy(array $raw): array
    {
        $dimensionKeyMap = [
            'self_awareness' => 'SA',
            'emotion_regulation' => 'ER',
            'empathy' => 'EM',
            'relationship_management' => 'RM',
        ];

        $dimensionMap = [];
        foreach ($dimensionKeyMap as $name => $code) {
            $qids = array_map('intval', (array) data_get($raw, 'scoring.dimensions.'.$name.'.qids', []));
            if ($qids === []) {
                $qids = (array) data_get($raw, 'dimension_map.'.$code, []);
                $qids = array_map('intval', $qids);
            }
            $dimensionMap[$code] = array_values(array_unique(array_filter($qids, static fn (int $qid): bool => $qid > 0)));
        }

        $reverse = array_map('intval', (array) data_get($raw, 'scoring.reverse_items', []));
        if ($reverse === []) {
            $reverse = array_map('intval', (array) ($raw['reverse_question_ids'] ?? []));
        }
        sort($reverse);

        $stdMean = (float) data_get($raw, 'scoring.standardization.target_mean', 100.0);
        $stdSd = (float) data_get($raw, 'scoring.standardization.target_sd', 15.0);
        $stdClampMin = (float) data_get($raw, 'scoring.standardization.clamp_min', 55.0);
        $stdClampMax = (float) data_get($raw, 'scoring.standardization.clamp_max', 145.0);

        $bootstrapMu = (float) data_get($raw, 'scoring.standardization.bootstrap_params.dimension.mu', 53.5);
        $bootstrapSigma = (float) data_get($raw, 'scoring.standardization.bootstrap_params.dimension.sigma', 7.5);

        $bands = is_array(data_get($raw, 'maturity_bands.bands')) ? data_get($raw, 'maturity_bands.bands') : [];
        $levelBands = [
            'baseline_max' => 84.99,
            'developing_max' => 92.99,
            'competent_max' => 107.99,
            'proficient_max' => 115.0,
        ];
        foreach ($bands as $band) {
            if (! is_array($band)) {
                continue;
            }
            $bucket = strtolower(trim((string) ($band['bucket'] ?? '')));
            $maxExclusive = (float) ($band['max_exclusive'] ?? 0.0);
            if ($maxExclusive <= 0.0) {
                continue;
            }
            $max = round($maxExclusive - 0.01, 2);
            if ($bucket === 'baseline') {
                $levelBands['baseline_max'] = $max;
            } elseif ($bucket === 'developing') {
                $levelBands['developing_max'] = $max;
            } elseif ($bucket === 'competent') {
                $levelBands['competent_max'] = $max;
            } elseif ($bucket === 'proficient') {
                $levelBands['proficient_max'] = $max;
            }
        }

        $validity = [
            'speeding' => [
                'c_lt_seconds' => (int) data_get($raw, 'validity.time_seconds_thresholds.C_below', 120),
                'd_lt_seconds' => (int) data_get($raw, 'validity.time_seconds_thresholds.D_below', 75),
            ],
            'longstring' => [
                'c_gte' => (int) data_get($raw, 'validity.longstring_thresholds.C_at_or_above', 25),
                'd_gte' => (int) data_get($raw, 'validity.longstring_thresholds.D_at_or_above', 35),
            ],
            'extreme_rate' => [
                'c_gte' => (float) data_get($raw, 'validity.response_style_thresholds.extreme_rate_C_at_or_above', 0.85),
            ],
            'neutral_rate' => [
                'c_gte' => (float) data_get($raw, 'validity.response_style_thresholds.neutral_rate_C_at_or_above', 0.70),
            ],
            'inconsistency' => [
                'c_gte' => (int) data_get($raw, 'validity.inconsistency_thresholds.C_at_or_above', 18),
                'd_gte' => (int) data_get($raw, 'validity.inconsistency_thresholds.D_at_or_above', 24),
            ],
        ];

        $inconsistencyPairs = [];
        foreach ((array) data_get($raw, 'validity.inconsistency_pairs', []) as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }
            $a = (int) ($pair[0] ?? 0);
            $b = (int) ($pair[1] ?? 0);
            if ($a <= 0 || $b <= 0) {
                continue;
            }
            $inconsistencyPairs[] = [$a, $b];
        }

        $rawRules = (array) data_get($raw, 'tags.rules', []);
        $tagRules = [];
        foreach ($rawRules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $tag = trim((string) ($rule['tag'] ?? ''));
            if ($tag === '') {
                continue;
            }

            $all = [];
            foreach ((array) data_get($rule, 'when.all', []) as $condition) {
                if (! is_array($condition)) {
                    continue;
                }
                $metric = strtoupper(trim((string) ($condition['metric'] ?? '')));
                if ($metric === '') {
                    continue;
                }

                $op = trim((string) ($condition['op'] ?? ''));
                $value = (float) ($condition['value'] ?? 0);

                $all[] = [
                    'metric' => $metric,
                    'op' => in_array($op, ['>', '>=', '<', '<=', '==', '!='], true) ? $op : '>=',
                    'value' => $value,
                ];
            }

            if ($all === []) {
                continue;
            }

            $addTags = array_values(array_unique(array_filter(array_map(
                static fn ($tagValue): string => trim((string) $tagValue),
                (array) ($rule['add_tags'] ?? [])
            ))));

            $tagRules[] = [
                'tag' => $tag,
                'when' => ['all' => $all],
                'add_tags' => $addTags,
            ];
        }

        $crossInsightRules = [];
        foreach ($tagRules as $rule) {
            $when = [];
            foreach ((array) data_get($rule, 'when.all', []) as $condition) {
                if (! is_array($condition)) {
                    continue;
                }
                $metric = strtoupper(trim((string) ($condition['metric'] ?? '')));
                $op = trim((string) ($condition['op'] ?? ''));
                $value = (float) ($condition['value'] ?? 0.0);

                if (! in_array($metric, ['SA', 'ER', 'EM', 'RM'], true)) {
                    continue;
                }

                $suffix = match ($op) {
                    '>=', '>' => 'GTE',
                    '<=', '<' => 'LTE',
                    default => null,
                };
                if ($suffix === null) {
                    continue;
                }

                $when[$metric.'_'.$suffix] = $value;
            }

            if ($when === []) {
                continue;
            }

            $crossInsightRules[] = [
                'tag' => (string) ($rule['tag'] ?? ''),
                'when' => $when,
            ];
        }

        $primaryProfilePriority = array_values(array_unique(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            (array) data_get($raw, 'tags.primary_profile_priority', [])
        ))));

        if ($primaryProfilePriority === []) {
            $primaryProfilePriority = [
                'profile:balanced_high_eq',
                'profile:emotion_leader',
                'profile:compassion_overload',
                'profile:overthinking_burn',
                'profile:social_masking',
                'profile:cool_detached',
            ];
        }

        $sectionsFree = array_values(array_unique(array_filter(array_map(
            static fn ($key): string => trim((string) $key),
            (array) data_get($raw, 'report.sections_free', [])
        ))));
        $sectionsFull = array_values(array_unique(array_filter(array_map(
            static fn ($key): string => trim((string) $key),
            (array) data_get($raw, 'report.sections_full', [])
        ))));

        $accessModulesFree = array_values(array_unique(array_filter(array_map(
            fn ($module): string => $this->normalizeModuleCode((string) $module),
            (array) data_get($raw, 'report.access_modules.free', [])
        ))));
        $accessModulesPaid = array_values(array_unique(array_filter(array_map(
            fn ($module): string => $this->normalizeModuleCode((string) $module),
            (array) data_get($raw, 'report.access_modules.paid', [])
        ))));

        return [
            'schema' => 'eq_60.policy.runtime.v2',
            'pack_id' => Eq60PackLoader::PACK_ID,
            'pack_version' => 'v1',
            'source_pack_id' => trim((string) ($raw['pack_id'] ?? 'EQ_GOLEMAN_60')),
            'source_scale_code' => trim((string) ($raw['scale_code'] ?? 'EQ_GOLEMAN_60')),
            'engine_version' => 'v1.0_normed_validity',
            'scoring_spec_version' => 'eq60_spec_2026_v2',
            'source_spec_version' => trim((string) ($raw['spec_version'] ?? ($raw['scoring_spec_version'] ?? 'eq60_spec_v1'))),

            'answer_codes_allowed' => ['A', 'B', 'C', 'D', 'E'],
            'dimension_codes' => ['SA', 'ER', 'EM', 'RM'],
            'dimension_map' => $dimensionMap,
            'reverse_question_ids' => $reverse,
            'reverse_rule' => '6_minus_raw',
            'score_range' => [
                'dimension' => ['min' => 15, 'max' => 75],
                'total' => ['min' => 60, 'max' => 300],
            ],

            'validity_rules' => $validity,
            'inconsistency_pairs' => $inconsistencyPairs,
            'std_score' => [
                'mean' => $stdMean,
                'sd' => $stdSd,
                'clamp_min' => $stdClampMin,
                'clamp_max' => $stdClampMax,
            ],
            'bootstrap_norms' => [
                'mu_dim' => $bootstrapMu,
                'sigma_dim' => $bootstrapSigma,
                'status' => 'PROVISIONAL',
                'version' => 'bootstrap_v1',
                'group' => 'locale_all_18-60',
            ],
            'level_bands' => $levelBands,
            'cross_insight_rules' => $crossInsightRules,
            'tags' => [
                'primary_profile_priority' => $primaryProfilePriority,
                'rules' => $tagRules,
            ],
            'report' => [
                'sections_free' => $sectionsFree,
                'sections_full' => $sectionsFull,
                'access_modules' => [
                    'free' => $accessModulesFree,
                    'paid' => $accessModulesPaid,
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function normalizeLayout(array $raw): array
    {
        $sections = is_array($raw['sections'] ?? null)
            ? $raw['sections']
            : (is_array(data_get($raw, 'layout.sections')) ? data_get($raw, 'layout.sections') : []);

        $normalizedSections = [];
        foreach ((array) $sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = trim((string) ($section['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $requiredVariants = array_values(array_unique(array_filter(array_map(
                static fn ($variant): string => strtolower(trim((string) $variant)),
                (array) ($section['required_in_variant'] ?? ['free', 'full'])
            ))));
            if ($requiredVariants === []) {
                $requiredVariants = ['free', 'full'];
            }

            $source = strtolower(trim((string) ($section['source'] ?? 'blocks')));
            if (! in_array($source, ['copy', 'blocks'], true)) {
                $source = 'blocks';
            }

            $accessLevel = strtolower(trim((string) ($section['access_level'] ?? 'free')));
            if (! in_array($accessLevel, ['free', 'paid'], true)) {
                $accessLevel = 'free';
            }

            $minBlocks = max(0, (int) ($section['min_blocks'] ?? 0));
            $maxBlocks = max($minBlocks, (int) ($section['max_blocks'] ?? $minBlocks));

            $normalizedSections[] = [
                'key' => $key,
                'source' => $source,
                'title_zh' => trim((string) ($section['title_zh'] ?? '')),
                'title_en' => trim((string) ($section['title_en'] ?? '')),
                'access_level' => $accessLevel,
                'module_code' => $this->normalizeModuleCode((string) ($section['module_code'] ?? 'eq_core')),
                'required_in_variant' => $requiredVariants,
                'min_blocks' => $minBlocks,
                'max_blocks' => $maxBlocks,
                'fallback_tags' => array_values(array_unique(array_filter(array_map(
                    static fn ($tag): string => trim((string) $tag),
                    (array) ($section['fallback_tags'] ?? [])
                )))),
            ];
        }

        return [
            'schema' => trim((string) ($raw['schema'] ?? 'eq60.report_layout.v1')),
            'conflict_rules' => [
                'priority_wins' => (bool) data_get($raw, 'conflict_rules.priority_wins', true),
                'exclusive_group' => (bool) data_get($raw, 'conflict_rules.exclusive_group', true),
            ],
            'sections' => $normalizedSections,
        ];
    }

    /**
     * @param  array<string,mixed>  $freeDoc
     * @param  array<string,mixed>  $paidDoc
     * @return list<array<string,mixed>>
     */
    private function normalizeBlocks(array $freeDoc, array $paidDoc): array
    {
        $all = [];

        foreach ([
            ['doc' => $freeDoc, 'access_level' => 'free'],
            ['doc' => $paidDoc, 'access_level' => 'paid'],
        ] as $source) {
            $doc = is_array($source['doc'] ?? null) ? $source['doc'] : [];
            $defaultAccess = (string) ($source['access_level'] ?? 'free');

            $rawBlocks = [];
            if (is_array($doc['blocks'] ?? null)) {
                $rawBlocks = array_values(array_filter((array) $doc['blocks'], 'is_array'));
            } else {
                foreach (['zh-CN', 'en'] as $locale) {
                    $rows = $doc[$locale] ?? null;
                    if (! is_array($rows)) {
                        continue;
                    }

                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            continue;
                        }

                        $row['locale'] = $locale;
                        $rawBlocks[] = $row;
                    }
                }
            }

            foreach ($rawBlocks as $row) {
                $blockId = trim((string) ($row['block_id'] ?? $row['id'] ?? ''));
                if ($blockId === '') {
                    continue;
                }

                $section = trim((string) ($row['section'] ?? $row['section_key'] ?? ''));
                if ($section === '') {
                    continue;
                }

                $title = trim((string) ($row['title'] ?? ''));
                $body = trim((string) ($row['body'] ?? ($row['body_md'] ?? '')));

                $tagsAny = array_values(array_unique(array_filter(array_map(
                    static fn ($tag): string => trim((string) $tag),
                    (array) ($row['tags_any'] ?? [])
                ))));
                $tagsAll = array_values(array_unique(array_filter(array_map(
                    static fn ($tag): string => trim((string) $tag),
                    (array) ($row['tags_all'] ?? [])
                ))));
                if (! in_array('section:'.$section, $tagsAll, true)) {
                    $tagsAll[] = 'section:'.$section;
                }

                $all[] = array_filter([
                    'block_id' => $blockId,
                    'section' => $section,
                    'kind' => trim((string) ($row['kind'] ?? 'paragraph')),
                    'access_level' => strtolower(trim((string) ($row['access_level'] ?? $defaultAccess))),
                    'module_code' => $this->normalizeModuleCode((string) ($row['module_code'] ?? 'eq_core')),
                    'locale' => $this->loader->normalizeLocale((string) ($row['locale'] ?? 'zh-CN')),
                    'title' => $title,
                    'body' => $body,
                    'variables' => array_values(array_unique(array_filter(array_map(
                        static fn ($var): string => trim((string) $var),
                        (array) ($row['variables'] ?? [])
                    )))),
                    'tags_any' => $tagsAny,
                    'tags_all' => $tagsAll,
                    'priority' => (int) ($row['priority'] ?? 0),
                    'exclusive_group' => trim((string) ($row['exclusive_group'] ?? '')),
                    'metric_level' => trim((string) ($row['metric_level'] ?? '')),
                    'metric_code' => trim((string) ($row['metric_code'] ?? '')),
                ], static fn ($value): bool => $value !== '');
            }
        }

        usort($all, function (array $a, array $b): int {
            $sectionCompare = strcmp((string) ($a['section'] ?? ''), (string) ($b['section'] ?? ''));
            if ($sectionCompare !== 0) {
                return $sectionCompare;
            }

            $localeCompare = strcmp((string) ($a['locale'] ?? ''), (string) ($b['locale'] ?? ''));
            if ($localeCompare !== 0) {
                return $localeCompare;
            }

            $priorityCompare = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return strcmp((string) ($a['block_id'] ?? ''), (string) ($b['block_id'] ?? ''));
        });

        return $all;
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array{required:list<string>,allowed:list<string>}
     */
    private function normalizeVariablesAllowlist(array $raw): array
    {
        $required = array_values(array_unique(array_filter(array_map(
            static fn ($var): string => trim((string) $var),
            (array) ($raw['required'] ?? [])
        ))));

        $allowed = array_values(array_unique(array_filter(array_map(
            static fn ($var): string => trim((string) $var),
            (array) ($raw['allowed'] ?? ($raw['variables'] ?? []))
        ))));

        if ($allowed === []) {
            $allowed = $required;
        }

        if ($required === []) {
            $required = [
                'scale_code',
                'attempt_id',
                'locale',
                'variant',
                'quality.level',
            ];
        }

        return [
            'required' => $required,
            'allowed' => $allowed,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function compileGoldenCases(string $version): array
    {
        $rows = $this->loader->readCsvWithLines($this->loader->rawPath('golden_cases.csv', $version));
        $cases = [];

        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);

            $answersByQid = $this->parseAnswersJsonToMap((string) ($row['answers_json'] ?? '[]'));
            $answerString = '';
            for ($qid = 1; $qid <= 60; $qid++) {
                $answerString .= (string) ($answersByQid[$qid] ?? 'C');
            }

            $qualityFlags = json_decode((string) ($row['expected_quality_flags_json'] ?? '[]'), true);
            $reportTags = json_decode((string) ($row['expected_report_tags_json'] ?? '[]'), true);
            $dimLevels = json_decode((string) ($row['expected_dim_levels_json'] ?? '{}'), true);
            $freeSections = json_decode((string) ($row['expected_free_sections'] ?? '[]'), true);
            $fullSections = json_decode((string) ($row['expected_full_sections'] ?? '[]'), true);

            $cases[] = [
                'case_id' => trim((string) ($row['case_id'] ?? '')),
                'locale' => trim((string) ($row['locale'] ?? 'zh-CN')),
                'country' => trim((string) ($row['country'] ?? '')),
                'age_band' => trim((string) ($row['age_band'] ?? '')),
                'gender' => trim((string) ($row['gender'] ?? '')),
                'time_seconds_total' => (int) ($row['time_seconds_total'] ?? 420),

                'answers' => $answerString,
                'answers_by_qid' => $answersByQid,

                'expected_quality_level' => strtoupper(trim((string) ($row['expected_quality_level'] ?? 'A'))),
                'expected_quality_flags' => is_array($qualityFlags) ? array_values(array_map('strval', $qualityFlags)) : [],
                'expected_primary_profile' => trim((string) ($row['expected_primary_profile'] ?? '')),
                'expected_report_tags' => is_array($reportTags) ? array_values(array_map('strval', $reportTags)) : [],
                'expected_dim_levels' => is_array($dimLevels) ? $dimLevels : [],
                'expected_global_level' => strtolower(trim((string) ($row['expected_global_level'] ?? ''))),
                'expected_free_sections' => is_array($freeSections) ? array_values(array_map('strval', $freeSections)) : [],
                'expected_full_sections' => is_array($fullSections) ? array_values(array_map('strval', $fullSections)) : [],
            ];
        }

        return $cases;
    }

    /**
     * @return array<int,string>
     */
    private function parseAnswersJsonToMap(string $answersJson): array
    {
        $decoded = json_decode($answersJson, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $qid = (int) ($item['question_id'] ?? 0);
            $code = strtoupper(trim((string) ($item['code'] ?? '')));
            if ($qid < 1 || $qid > 60 || ! in_array($code, ['A', 'B', 'C', 'D', 'E'], true)) {
                continue;
            }

            $out[$qid] = $code;
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }

    private function normalizeModuleCode(string $moduleCode): string
    {
        $value = strtolower(trim($moduleCode));
        if ($value === '') {
            return 'eq_core';
        }

        return match ($value) {
            'eq60.meta', 'eq60.summary', 'eq60.quadrants', 'eq60.core', 'eq_core' => 'eq_core',
            'eq60.insights', 'eq_cross_insights' => 'eq_cross_insights',
            'eq60.action_plan', 'eq_growth_plan' => 'eq_growth_plan',
            'eq60.full', 'eq_full' => 'eq_full',
            default => $value,
        };
    }

    /**
     * @param  array<string,string>  $hashes
     */
    private function hashMap(array $hashes): string
    {
        ksort($hashes);
        $rows = [];
        foreach ($hashes as $name => $hash) {
            $rows[] = $name.':'.$hash;
        }

        return hash('sha256', implode("\n", $rows));
    }

    private function hashDirectory(string $dir): string
    {
        if (! is_dir($dir)) {
            return '';
        }

        $files = File::allFiles($dir);
        usort($files, static fn (\SplFileInfo $a, \SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));

        $prefix = rtrim($dir, '/\\').DIRECTORY_SEPARATOR;
        $rows = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            $rel = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $file->getFilename();
            $rows[] = $rel.':'.hash_file('sha256', $path);
        }

        return hash('sha256', implode("\n", $rows));
    }
}
