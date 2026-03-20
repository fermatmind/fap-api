<?php

declare(strict_types=1);

namespace App\Services\BigFive;

use App\Models\Result;
use App\Services\AI\ControlledGenerationRuntime;
use App\Services\AI\ControlledNarrativeLayerService;

final class BigFivePublicProjectionService
{
    private const DOMAIN_ORDER = ['O', 'C', 'E', 'A', 'N'];

    public function __construct(
        private readonly ControlledGenerationRuntime $controlledGenerationRuntime,
        private readonly ControlledNarrativeLayerService $controlledNarrativeLayerService,
    ) {
    }

    /**
     * @var array<string,array{en:string,zh:string}>
     */
    private const TRAIT_LABELS = [
        'O' => ['en' => 'Openness', 'zh' => '开放性'],
        'C' => ['en' => 'Conscientiousness', 'zh' => '尽责性'],
        'E' => ['en' => 'Extraversion', 'zh' => '外向性'],
        'A' => ['en' => 'Agreeableness', 'zh' => '宜人性'],
        'N' => ['en' => 'Neuroticism', 'zh' => '情绪性'],
    ];

    /**
     * @var array<string,array<string,array{en:string,zh:string}>>
     */
    private const BAND_LABELS = [
        'low' => [
            'O' => ['en' => 'grounded', 'zh' => '更务实'],
            'C' => ['en' => 'adaptive', 'zh' => '更灵活'],
            'E' => ['en' => 'reserved', 'zh' => '更克制'],
            'A' => ['en' => 'direct', 'zh' => '更直接'],
            'N' => ['en' => 'steady', 'zh' => '更稳定'],
        ],
        'mid' => [
            'O' => ['en' => 'balanced', 'zh' => '相对平衡'],
            'C' => ['en' => 'balanced', 'zh' => '相对平衡'],
            'E' => ['en' => 'balanced', 'zh' => '相对平衡'],
            'A' => ['en' => 'balanced', 'zh' => '相对平衡'],
            'N' => ['en' => 'responsive', 'zh' => '相对敏感'],
        ],
        'high' => [
            'O' => ['en' => 'exploratory', 'zh' => '更探索'],
            'C' => ['en' => 'structured', 'zh' => '更有序'],
            'E' => ['en' => 'expressive', 'zh' => '更外放'],
            'A' => ['en' => 'harmonizing', 'zh' => '更体谅'],
            'N' => ['en' => 'sensitive', 'zh' => '更敏感'],
        ],
    ];

    /**
     * @var array<string,array{en:string,zh:string}>
     */
    private const PROFILE_LABELS = [
        'profile:resilient' => ['en' => 'Resilient profile', 'zh' => '韧性画像'],
        'profile:overcontrolled' => ['en' => 'Overcontrolled profile', 'zh' => '高约束画像'],
        'profile:undercontrolled' => ['en' => 'Undercontrolled profile', 'zh' => '低约束画像'],
        'profile:explorer' => ['en' => 'Explorer profile', 'zh' => '探索者画像'],
        'profile:steady_operator' => ['en' => 'Steady operator profile', 'zh' => '稳态执行画像'],
    ];

    /**
     * @return array<string,mixed>
     */
    public function buildFromResult(Result $result, string $locale, ?string $variant = null, ?bool $locked = null): array
    {
        return $this->build($this->extractScoreResult($result), $locale, $variant, $locked);
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array<string,mixed>
     */
    public function build(array $scoreResult, string $locale, ?string $variant = null, ?bool $locked = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $domainsMean = is_array(data_get($scoreResult, 'raw_scores.domains_mean')) ? data_get($scoreResult, 'raw_scores.domains_mean') : [];
        $domainsPercentile = is_array(data_get($scoreResult, 'scores_0_100.domains_percentile')) ? data_get($scoreResult, 'scores_0_100.domains_percentile') : [];
        $domainBuckets = is_array(data_get($scoreResult, 'facts.domain_buckets')) ? data_get($scoreResult, 'facts.domain_buckets') : [];
        $topStrengthFacets = array_values(array_filter(array_map('strval', is_array(data_get($scoreResult, 'facts.top_strength_facets')) ? data_get($scoreResult, 'facts.top_strength_facets') : [])));
        $topGrowthFacets = array_values(array_filter(array_map('strval', is_array(data_get($scoreResult, 'facts.top_growth_facets')) ? data_get($scoreResult, 'facts.top_growth_facets') : [])));
        $tags = array_values(array_filter(array_map('strval', is_array($scoreResult['tags'] ?? null) ? $scoreResult['tags'] : [])));

        $traitVector = [];
        foreach (self::DOMAIN_ORDER as $trait) {
            $band = strtolower(trim((string) ($domainBuckets[$trait] ?? 'mid')));
            if (! in_array($band, ['low', 'mid', 'high'], true)) {
                $band = 'mid';
            }

            $traitVector[] = [
                'key' => $trait,
                'label' => $this->traitLabel($trait, $locale),
                'mean' => round((float) ($domainsMean[$trait] ?? 0.0), 2),
                'percentile' => (int) ($domainsPercentile[$trait] ?? 0),
                'band' => $band,
                'band_label' => $this->bandLabel($trait, $band, $locale),
            ];
        }

        $traitBands = [];
        foreach ($traitVector as $trait) {
            $traitBands[(string) $trait['key']] = (string) $trait['band'];
        }

        $rankedTraits = $traitVector;
        usort($rankedTraits, fn (array $a, array $b): int => $this->compareTraitsByPercentile($a, $b, true));

        $dominantTraits = array_values(array_map(
            fn (array $trait, int $index): array => [
                'key' => (string) $trait['key'],
                'label' => (string) $trait['label'],
                'percentile' => (int) $trait['percentile'],
                'band' => (string) $trait['band'],
                'rank' => $index + 1,
            ],
            array_slice($rankedTraits, 0, 3),
            array_keys(array_slice($rankedTraits, 0, 3))
        ));

        $variantKeys = $this->buildVariantKeys($tags, $traitBands);
        $sceneFingerprint = $this->buildSceneFingerprint($traitBands);
        $explainabilitySummary = $this->buildExplainabilitySummary($dominantTraits, $topStrengthFacets, $variantKeys, $locale);
        $actionPlanSummary = $this->buildActionPlanSummary($traitVector, $topGrowthFacets, $locale);
        $sections = $this->buildSections(
            $traitVector,
            $dominantTraits,
            $sceneFingerprint,
            $variantKeys,
            $explainabilitySummary,
            $actionPlanSummary,
            $locale
        );

        $projection = [
            'schema_version' => 'big5.public_projection.v1',
            'trait_vector' => $traitVector,
            'trait_bands' => $traitBands,
            'dominant_traits' => $dominantTraits,
            'variant_keys' => $variantKeys,
            'scene_fingerprint' => $sceneFingerprint,
            'explainability_summary' => $explainabilitySummary,
            'action_plan_summary' => $actionPlanSummary,
            'ordered_section_keys' => array_values(array_map(
                static fn (array $section): string => (string) ($section['key'] ?? ''),
                $sections
            )),
            'sections' => $sections,
            '_meta' => array_filter([
                'scale_code' => 'BIG5_OCEAN',
                'engine_version' => (string) ($scoreResult['engine_version'] ?? ''),
                'variant' => $variant,
                'locked' => $locked,
            ], static fn ($value): bool => $value !== null && $value !== ''),
        ];

        $runtimeContract = $this->controlledGenerationRuntime->buildContract(
            'big5.report',
            'BIG5_OCEAN',
            $locale === 'zh' ? 'zh-CN' : 'en',
            $projection,
            [
                'engine_version' => (string) ($scoreResult['engine_version'] ?? ''),
                'schema_version' => 'big5.public_projection.v1',
            ]
        );
        $projection['_meta']['narrative_runtime_contract_v1'] = $runtimeContract;
        $projection['controlled_narrative_v1'] = $this->controlledNarrativeLayerService->buildFromRuntimeContract($runtimeContract);

        return $projection;
    }

    /**
     * @return array<string,mixed>
     */
    private function extractScoreResult(Result $result): array
    {
        $resultJson = is_array($result->result_json ?? null) ? $result->result_json : [];
        $candidates = [
            $result->normed_json,
            $resultJson['normed_json'] ?? null,
            data_get($resultJson, 'breakdown_json.score_result'),
            data_get($resultJson, 'axis_scores_json.score_result'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function normalizeLocale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh' : 'en';
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     */
    private function compareTraitsByPercentile(array $left, array $right, bool $descending): int
    {
        $leftPercentile = (int) ($left['percentile'] ?? 0);
        $rightPercentile = (int) ($right['percentile'] ?? 0);

        $percentileComparison = $descending
            ? ($rightPercentile <=> $leftPercentile)
            : ($leftPercentile <=> $rightPercentile);

        if ($percentileComparison !== 0) {
            return $percentileComparison;
        }

        return $this->domainOrderIndex((string) ($left['key'] ?? ''))
            <=> $this->domainOrderIndex((string) ($right['key'] ?? ''));
    }

    private function domainOrderIndex(string $trait): int
    {
        $index = array_search($trait, self::DOMAIN_ORDER, true);

        return $index === false ? count(self::DOMAIN_ORDER) : (int) $index;
    }

    private function traitLabel(string $trait, string $locale): string
    {
        return self::TRAIT_LABELS[$trait][$locale] ?? $trait;
    }

    private function bandLabel(string $trait, string $band, string $locale): string
    {
        return self::BAND_LABELS[$band][$trait][$locale] ?? $band;
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string,string>  $traitBands
     * @return list<string>
     */
    private function buildVariantKeys(array $tags, array $traitBands): array
    {
        $variantKeys = [];
        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'profile:') || str_starts_with($tag, 'big5:')) {
                $variantKeys[$tag] = true;
            }
        }

        foreach (self::DOMAIN_ORDER as $trait) {
            $band = strtolower((string) ($traitBands[$trait] ?? 'mid'));
            $variantKeys['band:'.strtolower($trait).'.'.$band] = true;
        }

        return array_keys($variantKeys);
    }

    /**
     * @param  array<string,string>  $traitBands
     * @return array<string,string>
     */
    private function buildSceneFingerprint(array $traitBands): array
    {
        return [
            'novelty' => match ($traitBands['O'] ?? 'mid') {
                'high' => 'exploratory',
                'low' => 'grounded',
                default => 'balanced',
            },
            'structure' => match ($traitBands['C'] ?? 'mid') {
                'high' => 'structured',
                'low' => 'adaptive',
                default => 'balanced',
            },
            'social_energy' => match ($traitBands['E'] ?? 'mid') {
                'high' => 'outward',
                'low' => 'reserved',
                default => 'balanced',
            },
            'cooperation' => match ($traitBands['A'] ?? 'mid') {
                'high' => 'harmonizing',
                'low' => 'direct',
                default => 'balanced',
            },
            'stress_posture' => match ($traitBands['N'] ?? 'mid') {
                'high' => 'sensitive',
                'low' => 'steady',
                default => 'responsive',
            },
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $dominantTraits
     * @param  list<string>  $topStrengthFacets
     * @param  list<string>  $variantKeys
     * @return array<string,mixed>
     */
    private function buildExplainabilitySummary(array $dominantTraits, array $topStrengthFacets, array $variantKeys, string $locale): array
    {
        $primary = $dominantTraits[0] ?? ['label' => $locale === 'zh' ? '当前人格' : 'Current profile'];
        $secondary = $dominantTraits[1] ?? null;
        $profileTags = array_values(array_filter($variantKeys, static fn (string $key): bool => str_starts_with($key, 'profile:')));

        $headline = $locale === 'zh'
            ? sprintf('这次画像主要由%s驱动。', (string) ($primary['label'] ?? '你的主维度'))
            : sprintf('This profile is primarily driven by %s.', (string) ($primary['label'] ?? 'your leading trait'));

        $reasons = [];
        $reasons[] = $locale === 'zh'
            ? sprintf('%s位于第 %d 百分位，形成了这次结果的第一主轴。', (string) ($primary['label'] ?? ''), (int) ($primary['percentile'] ?? 0))
            : sprintf('%s sits at percentile %d, making it the strongest axis in this read.', (string) ($primary['label'] ?? ''), (int) ($primary['percentile'] ?? 0));

        if (is_array($secondary)) {
            $reasons[] = $locale === 'zh'
                ? sprintf('%s作为第二主轴，解释了你在不同场景中的稳定表现。', (string) ($secondary['label'] ?? '第二主维度'))
                : sprintf('%s acts as the secondary axis that stabilizes this profile across scenes.', (string) ($secondary['label'] ?? 'the secondary trait'));
        }

        if ($topStrengthFacets !== []) {
            $reasons[] = $locale === 'zh'
                ? sprintf('高分刻面集中在 %s，说明这不是单点波动，而是有结构的特征组合。', implode(' / ', array_slice($topStrengthFacets, 0, 3)))
                : sprintf('Top facets cluster around %s, suggesting a structured pattern rather than a one-off spike.', implode(' / ', array_slice($topStrengthFacets, 0, 3)));
        }

        if ($profileTags !== []) {
            $reasons[] = $locale === 'zh'
                ? sprintf('当前命中的 profile tag 为 %s。', implode('、', array_map(fn (string $tag): string => $this->profileTagLabel($tag, $locale), $profileTags)))
                : sprintf('The current profile tags resolve to %s.', implode(', ', array_map(fn (string $tag): string => $this->profileTagLabel($tag, $locale), $profileTags)));
        }

        return [
            'headline' => $headline,
            'reasons' => $reasons,
            'top_strength_facets' => array_slice($topStrengthFacets, 0, 3),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $traitVector
     * @param  list<string>  $topGrowthFacets
     * @return array<string,mixed>
     */
    private function buildActionPlanSummary(array $traitVector, array $topGrowthFacets, string $locale): array
    {
        $reverse = $traitVector;
        usort($reverse, fn (array $a, array $b): int => $this->compareTraitsByPercentile($a, $b, false));
        $focus = $reverse[0] ?? null;
        $focusKey = (string) ($focus['key'] ?? 'C');
        $focusLabel = (string) ($focus['label'] ?? $focusKey);

        $actions = match ($focusKey) {
            'O' => $locale === 'zh'
                ? ['每周给自己一次低风险的新刺激。', '把探索想法落成一页记录，避免只停在灵感。', '在新项目里先验证一个最小假设。']
                : ['Add one low-risk novelty exposure each week.', 'Turn exploratory ideas into one-page notes.', 'Test one smallest viable hypothesis before scaling.'],
            'C' => $locale === 'zh'
                ? ['先搭一个最小稳定系统，而不是追求完美流程。', '把重复动作固定到同一个时间窗口。', '每周只追一项能看见进度的目标。']
                : ['Build one minimal reliable system before optimizing.', 'Anchor repetitive work to the same time window.', 'Track one visible weekly goal instead of many.'],
            'E' => $locale === 'zh'
                ? ['把反馈节点提前，而不是等到最后统一收集。', '刻意保留一段对外连接的时间。', '在关键沟通前先写清一句核心诉求。']
                : ['Move feedback checkpoints earlier.', 'Reserve a deliberate outward-connection window.', 'Write one clear communication ask before key conversations.'],
            'A' => $locale === 'zh'
                ? ['把边界说清楚，避免靠猜测维持关系。', '在分歧里先确认目标一致，再谈做法。', '把“我不同意”的表达练成固定句式。']
                : ['State boundaries explicitly instead of implying them.', 'Confirm shared goals before debating method.', 'Practice a reusable sentence for disagreement.'],
            'N' => $locale === 'zh'
                ? ['先固定一个恢复动作，再谈更大的改变。', '把高压时最常见的触发点写出来。', '在任务切换前留出三分钟过渡。']
                : ['Lock in one recovery ritual before larger changes.', 'Write down your most common pressure triggers.', 'Leave a three-minute transition before task switching.'],
            default => [],
        };

        $headline = $locale === 'zh'
            ? sprintf('当前最值得优先经营的是 %s。', $focusLabel)
            : sprintf('The best near-term growth lever is %s.', $focusLabel);

        return [
            'headline' => $headline,
            'focus_trait' => $focusKey,
            'focus_trait_label' => $focusLabel,
            'actions' => $actions,
            'top_growth_facets' => array_slice($topGrowthFacets, 0, 3),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $traitVector
     * @param  list<array<string,mixed>>  $dominantTraits
     * @param  array<string,string>  $sceneFingerprint
     * @param  list<string>  $variantKeys
     * @param  array<string,mixed>  $explainabilitySummary
     * @param  array<string,mixed>  $actionPlanSummary
     * @return list<array<string,mixed>>
     */
    private function buildSections(
        array $traitVector,
        array $dominantTraits,
        array $sceneFingerprint,
        array $variantKeys,
        array $explainabilitySummary,
        array $actionPlanSummary,
        string $locale
    ): array {
        $primary = $dominantTraits[0] ?? null;
        $secondary = $dominantTraits[1] ?? null;
        $primaryLabel = (string) ($primary['label'] ?? ($locale === 'zh' ? '当前主维度' : 'the leading trait'));
        $secondaryLabel = (string) ($secondary['label'] ?? ($locale === 'zh' ? '第二主维度' : 'the secondary trait'));

        $traitBullets = array_map(
            fn (array $trait): string => $locale === 'zh'
                ? sprintf('%s：第 %d 百分位，当前更偏 %s。', (string) $trait['label'], (int) $trait['percentile'], (string) $trait['band_label'])
                : sprintf('%s: percentile %d, currently leaning %s.', (string) $trait['label'], (int) $trait['percentile'], (string) $trait['band_label']),
            $traitVector
        );

        return [
            [
                'key' => 'traits.overview',
                'title' => $locale === 'zh' ? '人格总览' : 'Traits Overview',
                'access_level' => 'free',
                'module_code' => 'big5_core',
                'blocks' => [
                    [
                        'kind' => 'paragraph',
                        'title' => $locale === 'zh' ? '这份 Big Five 画像的主轴' : 'The main axis of this Big Five read',
                        'body' => $locale === 'zh'
                            ? sprintf('你这次的画像主要由%s和%s共同决定。它不是一句标签，而是五维组合的结果。', $primaryLabel, $secondaryLabel)
                            : sprintf('This read is primarily shaped by %s and %s. It is a five-trait combination, not a single label.', $primaryLabel, $secondaryLabel),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '五维带宽' : 'Trait bands',
                        'body' => implode("\n", $traitBullets),
                    ],
                ],
            ],
            [
                'key' => 'traits.why_this_profile',
                'title' => $locale === 'zh' ? '为什么会是这个画像' : 'Why This Profile',
                'access_level' => 'free',
                'module_code' => 'big5_core',
                'blocks' => [
                    [
                        'kind' => 'paragraph',
                        'title' => $locale === 'zh' ? '解释主线' : 'Explainability thread',
                        'body' => (string) ($explainabilitySummary['headline'] ?? ''),
                        'tags' => array_map(fn (string $key): string => $this->profileTagLabel($key, $locale), array_values(array_filter($variantKeys, static fn (string $key): bool => str_starts_with($key, 'profile:')))),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '为什么会命中这组特征' : 'Why this combination resolves',
                        'body' => implode("\n", array_map('strval', is_array($explainabilitySummary['reasons'] ?? null) ? $explainabilitySummary['reasons'] : [])),
                    ],
                ],
            ],
            [
                'key' => 'relationships.interpersonal_style',
                'title' => $locale === 'zh' ? '关系与互动风格' : 'Interpersonal Style',
                'access_level' => 'paid',
                'module_code' => 'big5_full',
                'blocks' => [
                    [
                        'kind' => 'paragraph',
                        'title' => $locale === 'zh' ? '互动场景画像' : 'Relationship scene fingerprint',
                        'body' => $locale === 'zh'
                            ? sprintf('在互动里，你更偏%s的社交能量、%s的合作方式，以及%s的压力姿态。', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale))
                            : sprintf('In relationships, you lean toward %s social energy, %s cooperation, and a %s stress posture.', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '关系提示' : 'Relationship cues',
                        'body' => implode("\n", $this->relationshipCues($sceneFingerprint, $locale)),
                    ],
                ],
            ],
            [
                'key' => 'career.work_style',
                'title' => $locale === 'zh' ? '工作风格与场景' : 'Work Style',
                'access_level' => 'paid',
                'module_code' => 'big5_full',
                'blocks' => [
                    [
                        'kind' => 'paragraph',
                        'title' => $locale === 'zh' ? '工作场景指纹' : 'Work scene fingerprint',
                        'body' => $locale === 'zh'
                            ? sprintf('工作上，你更适合%s的变化、%s的结构，以及%s的对外节奏。', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale))
                            : sprintf('At work, you fit %s change, %s structure, and a %s outward rhythm.', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '工作提示' : 'Work cues',
                        'body' => implode("\n", $this->workCues($sceneFingerprint, $locale)),
                    ],
                ],
            ],
            [
                'key' => 'growth.next_actions',
                'title' => $locale === 'zh' ? '成长与下一步动作' : 'Next Actions',
                'access_level' => 'paid',
                'module_code' => 'big5_action_plan',
                'blocks' => [
                    [
                        'kind' => 'paragraph',
                        'title' => $locale === 'zh' ? '接下来优先修什么' : 'What to work on next',
                        'body' => (string) ($actionPlanSummary['headline'] ?? ''),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '行动建议' : 'Action plan',
                        'body' => implode("\n", array_map('strval', is_array($actionPlanSummary['actions'] ?? null) ? $actionPlanSummary['actions'] : [])),
                    ],
                ],
            ],
        ];
    }

    private function profileTagLabel(string $tag, string $locale): string
    {
        return self::PROFILE_LABELS[$tag][$locale] ?? $tag;
    }

    private function sceneLabel(string $value, string $locale): string
    {
        return match ($value) {
            'exploratory' => $locale === 'zh' ? '更探索' : 'more exploratory',
            'grounded' => $locale === 'zh' ? '更务实' : 'more grounded',
            'structured' => $locale === 'zh' ? '更有序' : 'more structured',
            'adaptive' => $locale === 'zh' ? '更灵活' : 'more adaptive',
            'outward' => $locale === 'zh' ? '更外放' : 'more outward',
            'reserved' => $locale === 'zh' ? '更克制' : 'more reserved',
            'harmonizing' => $locale === 'zh' ? '更体谅' : 'more harmonizing',
            'direct' => $locale === 'zh' ? '更直接' : 'more direct',
            'sensitive' => $locale === 'zh' ? '更敏感' : 'more sensitive',
            'steady' => $locale === 'zh' ? '更稳定' : 'more steady',
            'responsive' => $locale === 'zh' ? '相对敏感' : 'responsive',
            default => $locale === 'zh' ? '相对平衡' : 'balanced',
        };
    }

    /**
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function relationshipCues(array $sceneFingerprint, string $locale): array
    {
        return [
            $locale === 'zh'
                ? sprintf('在关系里，你通常更偏%s地处理互动。', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale))
                : sprintf('In relationships, you tend to handle interaction in a %s way.', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
            $locale === 'zh'
                ? sprintf('遇到分歧时，你更可能用%s的方式推进沟通。', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale))
                : sprintf('During conflict, you are more likely to communicate in a %s mode.', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
            $locale === 'zh'
                ? sprintf('压力上来时，关系体验会呈现%s的姿态。', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale))
                : sprintf('Under pressure, your relationship posture becomes more %s.', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
        ];
    }

    /**
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function workCues(array $sceneFingerprint, string $locale): array
    {
        return [
            $locale === 'zh'
                ? sprintf('面对变化时，你更适合%s的节奏。', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale))
                : sprintf('When handling change, you fit a %s pace.', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale)),
            $locale === 'zh'
                ? sprintf('执行任务时，你更习惯%s的结构。', $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale))
                : sprintf('When executing work, you tend to prefer a %s structure.', $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale)),
            $locale === 'zh'
                ? sprintf('协作节奏上，你通常会选择%s的对外方式。', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale))
                : sprintf('In collaboration, you usually prefer a %s outward rhythm.', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
        ];
    }
}
