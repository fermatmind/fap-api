<?php

declare(strict_types=1);

namespace App\Services\BigFive;

use App\Models\Result;
use App\Services\AI\ControlledGenerationRuntime;
use App\Services\AI\ControlledNarrativeLayerService;
use App\Services\Comparative\VersionedComparativeNormingLayerService;
use App\Services\Content\CulturalCalibrationLayerService;

final class BigFivePublicProjectionService
{
    private const DOMAIN_ORDER = ['O', 'C', 'E', 'A', 'N'];

    private const FACET_ORDER = [
        'N1', 'E1', 'O1', 'A1', 'C1',
        'N2', 'E2', 'O2', 'A2', 'C2',
        'N3', 'E3', 'O3', 'A3', 'C3',
        'N4', 'E4', 'O4', 'A4', 'C4',
        'N5', 'E5', 'O5', 'A5', 'C5',
        'N6', 'E6', 'O6', 'A6', 'C6',
    ];

    public function __construct(
        private readonly ControlledGenerationRuntime $controlledGenerationRuntime,
        private readonly ControlledNarrativeLayerService $controlledNarrativeLayerService,
        private readonly VersionedComparativeNormingLayerService $comparativeNormingLayerService,
        private readonly CulturalCalibrationLayerService $culturalCalibrationLayerService,
    ) {}

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
     * @var array<string,string>
     */
    private const FACET_NAME_SLUG = [
        'N1' => 'anxiety',
        'N2' => 'anger',
        'N3' => 'depression',
        'N4' => 'self_consciousness',
        'N5' => 'immoderation',
        'N6' => 'vulnerability',
        'E1' => 'friendliness',
        'E2' => 'gregariousness',
        'E3' => 'assertiveness',
        'E4' => 'activity_level',
        'E5' => 'excitement_seeking',
        'E6' => 'cheerfulness',
        'O1' => 'imagination',
        'O2' => 'artistic_interests',
        'O3' => 'emotionality',
        'O4' => 'adventurousness',
        'O5' => 'intellect',
        'O6' => 'liberalism',
        'A1' => 'trust',
        'A2' => 'morality',
        'A3' => 'altruism',
        'A4' => 'cooperation',
        'A5' => 'modesty',
        'A6' => 'sympathy',
        'C1' => 'self_efficacy',
        'C2' => 'orderliness',
        'C3' => 'dutifulness',
        'C4' => 'achievement_striving',
        'C5' => 'self_discipline',
        'C6' => 'cautiousness',
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
        $facetsMean = is_array(data_get($scoreResult, 'raw_scores.facets_mean')) ? data_get($scoreResult, 'raw_scores.facets_mean') : [];
        $domainsPercentile = is_array(data_get($scoreResult, 'scores_0_100.domains_percentile')) ? data_get($scoreResult, 'scores_0_100.domains_percentile') : [];
        $facetsPercentile = is_array(data_get($scoreResult, 'scores_0_100.facets_percentile')) ? data_get($scoreResult, 'scores_0_100.facets_percentile') : [];
        $domainBuckets = is_array(data_get($scoreResult, 'facts.domain_buckets')) ? data_get($scoreResult, 'facts.domain_buckets') : [];
        $facetBuckets = is_array(data_get($scoreResult, 'facts.facet_buckets')) ? data_get($scoreResult, 'facts.facet_buckets') : [];
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

        $facetVector = [];
        foreach (self::FACET_ORDER as $facet) {
            $bucket = strtolower(trim((string) ($facetBuckets[$facet] ?? 'mid')));
            if (! in_array($bucket, ['low', 'mid', 'high', 'extreme_low', 'extreme_high'], true)) {
                $bucket = 'mid';
            }

            $facetVector[] = [
                'key' => $facet,
                'label' => $this->facetLabel($facet),
                'slug' => self::FACET_NAME_SLUG[$facet] ?? strtolower($facet),
                'domain' => substr($facet, 0, 1),
                'mean' => round((float) ($facetsMean[$facet] ?? 0.0), 2),
                'percentile' => (int) ($facetsPercentile[$facet] ?? 0),
                'bucket' => $bucket,
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
        $qualityLevel = strtoupper(trim((string) data_get($scoreResult, 'quality.level', 'D')));
        $normStatus = strtoupper(trim((string) data_get($scoreResult, 'norms.status', 'MISSING')));

        $projectionSeed = [
            'schema_version' => 'big5.public_projection.v1',
            'trait_vector' => $traitVector,
            'facet_vector' => $facetVector,
            'trait_bands' => $traitBands,
            'dominant_traits' => $dominantTraits,
            'variant_keys' => $variantKeys,
            'scene_fingerprint' => $sceneFingerprint,
            'explainability_summary' => $explainabilitySummary,
            'action_plan_summary' => $actionPlanSummary,
            '_meta' => [
                'scale_code' => 'BIG5_OCEAN',
                'engine_version' => (string) ($scoreResult['engine_version'] ?? ''),
                'variant' => $variant,
                'locked' => $locked,
            ],
        ];

        $comparativeV1 = $this->comparativeNormingLayerService->buildForBigFive(
            $projectionSeed,
            $scoreResult,
            ['locale' => $locale]
        );

        $sections = $this->buildSections(
            $traitVector,
            $facetVector,
            $dominantTraits,
            $sceneFingerprint,
            $variantKeys,
            $explainabilitySummary,
            $actionPlanSummary,
            $comparativeV1,
            $qualityLevel,
            $normStatus,
            $variant,
            $locale
        );

        $projection = [
            'schema_version' => 'big5.public_projection.v1',
            'trait_vector' => $traitVector,
            'facet_vector' => $facetVector,
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
        $projection['cultural_calibration_v1'] = $this->culturalCalibrationLayerService->buildForBigFive(
            $projection,
            ['locale' => $locale]
        );
        $projection['comparative_v1'] = $comparativeV1;

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

    private function facetLabel(string $facet): string
    {
        $slug = self::FACET_NAME_SLUG[$facet] ?? strtolower($facet);
        $words = array_filter(explode('_', $slug), static fn (string $word): bool => $word !== '');
        if ($words === []) {
            return $facet;
        }

        $title = implode(' ', array_map(static fn (string $word): string => ucfirst($word), $words));

        return sprintf('%s %s', $facet, $title);
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
        $tertiary = $dominantTraits[2] ?? null;
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

        if (is_array($tertiary)) {
            $reasons[] = $locale === 'zh'
                ? sprintf('%s作为第三条辅助轴，通常决定你在压力、熟悉度和节奏变化中的微调方式。', (string) ($tertiary['label'] ?? '第三主维度'))
                : sprintf('%s acts as the tertiary support axis, usually shaping your micro-adjustments under pressure, familiarity, and pace shifts.', (string) ($tertiary['label'] ?? 'the tertiary trait'));
        }

        if ($topStrengthFacets !== []) {
            $reasons[] = $locale === 'zh'
                ? sprintf('高分刻面集中在 %s，说明这不是单点波动，而是有结构的特征组合。', implode(' / ', array_slice($topStrengthFacets, 0, 3)))
                : sprintf('Top facets cluster around %s, suggesting a structured pattern rather than a one-off spike.', implode(' / ', array_slice($topStrengthFacets, 0, 3)));
        }

        $reasons[] = $locale === 'zh'
            ? sprintf('行为解释优先看“主轴 + 次轴 + 场景指纹”的组合，而不是只看单个分数。')
            : 'Behavioral interpretation should prioritize the combination of lead axis + secondary axis + scene fingerprint, not any single score.';

        $reasons[] = $locale === 'zh'
            ? '如果你在不同场景里呈现得不一样，这通常不是矛盾，而是情境把不同轴放大了。'
            : 'If you look different across contexts, that is usually not inconsistency; it is the context amplifying different axes.';

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

        $shortTerm = match ($focusKey) {
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

        $longTerm = match ($focusKey) {
            'O' => $locale === 'zh'
                ? ['把探索纳入固定的复盘节奏。', '让新想法先经历一个最小实验。', '把学习收束成可重复的输入-输出链路。']
                : ['Fold novelty into a repeatable review rhythm.', 'Put every new idea through one smallest experiment.', 'Turn learning into a repeatable input-output chain.'],
            'C' => $locale === 'zh'
                ? ['把稳定流程做成可持续系统，而不是单次冲刺。', '在关键节点留一个检查点。', '让高标准和弹性共存。']
                : ['Turn reliable routines into sustainable systems, not one-off sprints.', 'Leave one checkpoint at key milestones.', 'Make high standards and flexibility coexist.'],
            'E' => $locale === 'zh'
                ? ['建立可重复的反馈节奏。', '把表达与倾听拆成两个动作。', '让外部连接成为稳定输入，而不是偶发消耗。']
                : ['Build a repeatable feedback rhythm.', 'Split speaking and listening into separate moves.', 'Make outward connection a stable input rather than an occasional drain.'],
            'A' => $locale === 'zh'
                ? ['把协商规则写成可以重复使用的句式。', '在合作开始前先说清边界。', '让体谅不再依赖猜测。']
                : ['Write negotiation rules into reusable sentences.', 'Name boundaries before collaboration starts.', 'Make consideration explicit instead of implied.'],
            'N' => $locale === 'zh'
                ? ['把恢复动作系统化，而不是等失衡后再补救。', '让压力监测成为日常而非临时反应。', '把敏感转成早发现、早调整的能力。']
                : ['Systematize recovery instead of waiting to patch after overload.', 'Make pressure monitoring a daily habit, not a crisis response.', 'Turn sensitivity into early detection and earlier adjustment.'],
            default => [],
        };

        $caseStudy = match ($focusKey) {
            'O' => $locale === 'zh'
                ? '例如在一个陌生项目里，先试一个新工具或新方法，再决定是否扩张。'
                : 'For example, on an unfamiliar project, test one new tool or method before expanding.',
            'C' => $locale === 'zh'
                ? '例如先把一个重复任务整理成模板，再把模板放进每周固定时段。'
                : 'For example, turn one repeat task into a template, then place it in a fixed weekly slot.',
            'E' => $locale === 'zh'
                ? '例如在关键沟通前先写一句核心诉求，再安排一次反馈回路。'
                : 'For example, write one core ask before a key conversation, then schedule a feedback loop.',
            'A' => $locale === 'zh'
                ? '例如在协作开始前先说边界和决策规则，再进入具体分工。'
                : 'For example, state boundaries and decision rules before moving into task division.',
            'N' => $locale === 'zh'
                ? '例如给高压任务预留一个恢复动作，把情绪管理嵌进流程。'
                : 'For example, reserve a recovery move for high-pressure work and build emotion management into the process.',
            default => '',
        };

        $socialFocus = match ($focusKey) {
            'E' => $locale === 'zh'
                ? '把沟通节奏前置，避免最后一刻才集中表达。'
                : 'Bring communication cadence forward instead of clustering it at the end.',
            'A' => $locale === 'zh'
                ? '把边界与合作规则提前说清。'
                : 'State boundaries and collaboration rules early.',
            'N' => $locale === 'zh'
                ? '在高压关系里先做情绪降噪，再做回应。'
                : 'Reduce emotional noise before responding in high-pressure relationships.',
            default => $locale === 'zh'
                ? '让关系里的默认动作更清晰、更可预测。'
                : 'Make your default relational moves clearer and more predictable.',
        };

        $healthFocus = match ($focusKey) {
            'N' => $locale === 'zh'
                ? '把恢复、睡眠和过渡时间纳入固定安排。'
                : 'Build recovery, sleep, and transition time into a fixed routine.',
            'C' => $locale === 'zh'
                ? '把规律化作息当成稳定输出的基础设施。'
                : 'Treat regular routines as infrastructure for stable output.',
            'O' => $locale === 'zh'
                ? '给自己保留“输入-消化-输出”的完整循环。'
                : 'Preserve a full input-digest-output loop for yourself.',
            default => $locale === 'zh'
                ? '关注最容易被忽略的消耗点。'
                : 'Pay attention to the easiest-to-miss sources of drain.',
        };

        $caseStudy2 = match ($focusKey) {
            'O' => $locale === 'zh'
                ? '例如在社交或工作里，先试一次新表达方式，再观察反馈。'
                : 'For example, try one new way of expressing yourself in work or social settings, then watch the feedback.',
            'C' => $locale === 'zh'
                ? '例如把最常反复出错的一步单独抽出来做成检查清单。'
                : 'For example, isolate the step that fails most often and turn it into a checklist.',
            'E' => $locale === 'zh'
                ? '例如在会议前写出一句要点，确保你先说重点再展开。'
                : 'For example, write one key point before a meeting so you lead with the core message.',
            'A' => $locale === 'zh'
                ? '例如在合作开始前先明确“什么可以、什么不可以”。'
                : 'For example, define “what is okay and what is not” before collaboration starts.',
            'N' => $locale === 'zh'
                ? '例如给自己设置一个压力预警阈值，低于阈值先调整而不是硬扛。'
                : 'For example, set a pressure threshold so you adjust before overload instead of powering through.',
            default => '',
        };

        $headline = $locale === 'zh'
            ? sprintf('当前最值得优先经营的是 %s。', $focusLabel)
            : sprintf('The best near-term growth lever is %s.', $focusLabel);

        return [
            'headline' => $headline,
            'focus_trait' => $focusKey,
            'focus_trait_label' => $focusLabel,
            'short_term' => $shortTerm,
            'long_term' => $longTerm,
            'case_study' => $caseStudy,
            'case_study_2' => $caseStudy2,
            'social_focus' => $socialFocus,
            'health_focus' => $healthFocus,
            'top_growth_facets' => array_slice($topGrowthFacets, 0, 3),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $traitVector
     * @param  list<array<string,mixed>>  $facetVector
     * @param  list<array<string,mixed>>  $dominantTraits
     * @param  array<string,string>  $sceneFingerprint
     * @param  list<string>  $variantKeys
     * @param  array<string,mixed>  $explainabilitySummary
     * @param  array<string,mixed>  $actionPlanSummary
     * @param  array<string,mixed>  $comparativeV1
     * @return list<array<string,mixed>>
     */
    private function buildSections(
        array $traitVector,
        array $facetVector,
        array $dominantTraits,
        array $sceneFingerprint,
        array $variantKeys,
        array $explainabilitySummary,
        array $actionPlanSummary,
        array $comparativeV1,
        string $qualityLevel,
        string $normStatus,
        ?string $variant,
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
        $corePortraitLines = $this->buildCorePortraitLines($traitVector, $dominantTraits, $sceneFingerprint, $locale);
        $coreCaseLines = $this->buildCoreCaseLines($sceneFingerprint, $locale);
        $coreHighLowLines = $this->buildCoreHighLowLines($dominantTraits, $locale);
        $coreCareerLines = $this->buildCoreCareerLines($traitVector, $sceneFingerprint, $locale);
        $relationshipLines = $this->buildRelationshipLines($sceneFingerprint, $locale);
        $relationshipCaseLines = $this->buildRelationshipScenarioLines($sceneFingerprint, $locale);
        $relationshipBoundaryLines = $this->buildRelationshipBoundaryLines($sceneFingerprint, $locale);
        $workLines = $this->buildWorkLines($sceneFingerprint, $locale);
        $workRoleLines = $this->buildWorkRoleLines($sceneFingerprint, $locale);
        $growthLines = $this->buildGrowthPlanLines($actionPlanSummary, $locale);
        $growthCaseLines = $this->buildGrowthCaseLines($actionPlanSummary, $locale);
        $isFullVariant = strtolower(trim((string) $variant)) === 'full';

        $sections = [
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
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '核心画像' : 'Core portrait',
                        'body' => implode("\n", $corePortraitLines),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '情境案例' : 'Scene cases',
                        'body' => implode("\n", $coreCaseLines),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '高低位点与职业' : 'High/low points and careers',
                        'body' => implode("\n", $coreHighLowLines),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '成长接口' : 'Growth interface',
                        'body' => implode("\n", $coreCareerLines),
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
                        'body' => implode("\n", array_merge(
                            array_map('strval', is_array($explainabilitySummary['reasons'] ?? null) ? $explainabilitySummary['reasons'] : []),
                            [$locale === 'zh'
                                ? '视觉提示：把这组结果想成一张五向雷达图，主轴决定默认方式，次轴决定场景稳定性。'
                                : 'Visual cue: think of this read as a five-axis radar map, where the main axes define the default mode and the secondary axes define stability across scenes.']
                        )),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '解读层级' : 'Interpretation layers',
                        'body' => implode("\n", $this->buildWhyThisProfileDeepLines($explainabilitySummary, $locale)),
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
                        'body' => implode("\n", $relationshipLines),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '关系案例' : 'Relationship cases',
                        'body' => implode("\n", $relationshipCaseLines),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '边界与修复' : 'Boundaries and repair',
                        'body' => implode("\n", $relationshipBoundaryLines),
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
                        'body' => implode("\n", $workLines),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '角色与环境' : 'Roles and environments',
                        'body' => implode("\n", $workRoleLines),
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
                        'body' => implode("\n", $growthLines),
                    ],
                    [
                        'kind' => 'bullets',
                        'title' => $locale === 'zh' ? '场景案例' : 'Scenario cases',
                        'body' => implode("\n", $growthCaseLines),
                    ],
                ],
            ],
        ];

        if ($isFullVariant) {
            $sections[] = $this->buildDomainDeepDiveSection($traitVector, $sceneFingerprint, $locale);
            $sections[] = $this->buildFacetDetailSection($facetVector, $locale);
            $sections[] = $this->buildComparativeSection($comparativeV1, $primary ?? [], $locale);
            $sections[] = $this->buildMethodologySection($qualityLevel, $normStatus, $locale);
        }

        return $sections;
    }

    /**
     * @param  list<array<string,mixed>>  $traitVector
     * @param  list<array<string,mixed>>  $dominantTraits
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildCorePortraitLines(array $traitVector, array $dominantTraits, array $sceneFingerprint, string $locale): array
    {
        $primary = $dominantTraits[0] ?? ($traitVector[0] ?? []);
        $secondary = $dominantTraits[1] ?? ($traitVector[1] ?? []);
        $primaryLabel = (string) ($primary['label'] ?? '');
        $secondaryLabel = (string) ($secondary['label'] ?? '');
        $primaryBand = (string) ($primary['band_label'] ?? '');

        return $locale === 'zh'
            ? [
                sprintf('工作情境：你更容易用%s处理变化，同时让%s提供结构感。', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale)),
                sprintf('关系情境：你会在%s的合作方式和%s的互动节奏之间找到默认模式。', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
                sprintf('压力情境：压力上来时，你会更依赖%s的姿态来稳住自己。', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
                $primaryLabel !== ''
                    ? sprintf('高位点示例：当 %s 继续上升时，你的默认策略会更明显地靠近 %s。', $primaryLabel, $primaryBand !== '' ? $primaryBand : '该维度的高位风格')
                    : '高位点示例：当主轴继续上升时，你的默认策略会更明显地表现出来。',
                $primaryLabel !== ''
                    ? sprintf('低位点示例：如果 %s 回落，你会更接近另一端的行为风格，并更依赖环境提示。', $primaryLabel)
                    : '低位点示例：如果主轴回落，你会更接近另一端的行为风格，并更依赖环境提示。',
                '视觉提示：把这份结果想成一张五向雷达图，最亮的两条轴决定了你的默认操作系统。',
            ]
            : [
                sprintf('Work scene: you are more likely to handle change through %s while using %s as a stabilizer.', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale)),
                sprintf('Relationship scene: you tend to find your default mode between %s cooperation and %s interaction rhythm.', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
                sprintf('Pressure scene: when pressure rises, you lean on a %s posture to stay steady.', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
                $primaryLabel !== ''
                    ? sprintf('High-point example: if %s climbs further, the trait becomes an even more visible operating mode.', $primaryLabel)
                    : 'High-point example: if the lead trait climbs further, it becomes an even more visible operating mode.',
                $primaryLabel !== ''
                    ? sprintf('Low-point example: if %s eases, you will lean more on external cues and the opposite behavioral style.', $primaryLabel)
                    : 'Low-point example: if the lead trait eases, you will lean more on external cues and the opposite behavioral style.',
                'Visual cue: imagine a five-axis radar chart, where the brightest two axes define your default operating system.',
            ];
    }

    /**
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildRelationshipLines(array $sceneFingerprint, string $locale): array
    {
        return [
            $locale === 'zh'
                ? sprintf('互动方式：你更倾向用%s开场，用%s收束分歧。', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale))
                : sprintf('Interaction style: you tend to open with a %s mode and close disagreements in a %s way.', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
            $locale === 'zh'
                ? sprintf('实际场景：在朋友、伴侣或同事面前，你通常会先调节%s，再决定要不要继续表达。', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale))
                : sprintf('Real-world scene: with friends, partners, or coworkers, you usually regulate your %s before deciding whether to keep talking.', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
            $locale === 'zh'
                ? '风险点：如果体谅太多，边界会被稀释；如果表达太快，关系会感觉没被接住。'
                : 'Risk point: too much accommodation can blur boundaries, while too much speed can make others feel unheard.',
            $locale === 'zh'
                ? '适配关系：最适合能直接说规则、又允许你保留节奏差异的关系。'
                : 'Best-fit relationships: the ones that state rules directly while still allowing you to keep your own rhythm.',
        ];
    }

    /**
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildWorkLines(array $sceneFingerprint, string $locale): array
    {
        return [
            $locale === 'zh'
                ? sprintf('工作节奏：你更适合%s的变化节奏和%s的执行结构。', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale))
                : sprintf('Work rhythm: you fit a %s pace of change and a %s execution structure.', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale)),
            $locale === 'zh'
                ? sprintf('团队协作：你通常在%s的外部沟通和%s的内部对齐之间寻找平衡。', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale))
                : sprintf('Team collaboration: you usually balance a %s outward rhythm with a %s internal alignment style.', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
            $locale === 'zh'
                ? '职业适配：研究、产品探索、运营、项目管理、销售推进、服务协调，都能从你的五维组合里找到不同切面。'
                : 'Career fit: research, product discovery, operations, project management, sales motion, and service coordination can each map to a different slice of your trait mix.',
            $locale === 'zh'
                ? '风险点：如果结构感太强，可能会压缩探索；如果探索感太强，可能会稀释交付。'
                : 'Risk point: too much structure can suppress exploration, while too much exploration can dilute delivery.',
        ];
    }

    /**
     * @return list<string>
     */
    private function buildGrowthPlanLines(array $actionPlanSummary, string $locale): array
    {
        $shortTerm = array_map('strval', is_array($actionPlanSummary['short_term'] ?? null) ? $actionPlanSummary['short_term'] : []);
        $longTerm = array_map('strval', is_array($actionPlanSummary['long_term'] ?? null) ? $actionPlanSummary['long_term'] : []);
        $caseStudy = trim((string) ($actionPlanSummary['case_study'] ?? ''));
        $focusLabel = trim((string) ($actionPlanSummary['focus_trait_label'] ?? ''));

        return array_values(array_filter([
            $locale === 'zh'
                ? sprintf('短期（1-2 周）：%s', implode('；', $shortTerm))
                : sprintf('Short term (1-2 weeks): %s', implode('; ', $shortTerm)),
            $locale === 'zh'
                ? sprintf('长期（1-3 个月）：%s', implode('；', $longTerm))
                : sprintf('Long term (1-3 months): %s', implode('; ', $longTerm)),
            $caseStudy !== ''
                ? ($locale === 'zh'
                    ? sprintf('案例：%s', $caseStudy)
                    : sprintf('Case study: %s', $caseStudy))
                : null,
            $focusLabel !== ''
                ? ($locale === 'zh'
                    ? sprintf('优先聚焦：%s。', $focusLabel)
                    : sprintf('Primary focus: %s.', $focusLabel))
                : null,
        ], static fn ($value): bool => is_string($value) && trim($value) !== ''));
    }

    /**
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildCoreCaseLines(array $sceneFingerprint, string $locale): array
    {
        return $locale === 'zh'
            ? [
                sprintf('工作案例：当会议需要快速定方向时，你会先用%s处理信息，再用%s把结论落到可执行步骤。', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale)),
                sprintf('社交案例：在朋友、伴侣或同事面前，你会先判断场合是否需要%s，再决定自己是直接表达还是先观察。', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
                sprintf('压力案例：当节奏失控时，你最先依赖%s来回稳，而不是继续把变化往外推。', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
                sprintf('冲突案例：一旦出现分歧，你会优先看%s是否还能维持，然后再决定是继续谈、暂缓，还是切换到书面沟通。', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
            ]
            : [
                sprintf('Work case: when a meeting needs a fast direction, you first process it through %s and then land it with %s.', $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale)),
                sprintf('Social case: with friends, partners, or coworkers, you first judge whether the scene needs %s before deciding to speak directly or observe first.', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
                sprintf('Pressure case: when the pace gets out of hand, you stabilize through a %s posture rather than pushing change outward.', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
                sprintf('Conflict case: once disagreement appears, you check whether %s can still hold, then decide whether to keep talking, pause, or move to written communication.', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
            ];
    }

    /**
     * @param  list<array<string,mixed>>  $dominantTraits
     * @return list<string>
     */
    private function buildCoreHighLowLines(array $dominantTraits, string $locale): array
    {
        $lines = [];
        foreach (array_slice($dominantTraits, 0, 2) as $trait) {
            if (! is_array($trait)) {
                continue;
            }

            $label = (string) ($trait['label'] ?? '');
            $percentile = (int) ($trait['percentile'] ?? 0);
            $band = (string) ($trait['band_label'] ?? '');
            if ($locale === 'zh') {
                $lines[] = sprintf('%s 高位时：这条轴会更主动地决定你的默认动作，通常表现为%s。', $label, $band !== '' ? $band : '更鲜明的行为风格');
                $lines[] = sprintf('%s 低位时：如果这一轴回落，你会更依赖环境提示，行为也会向另一端靠拢。', $label);
                $lines[] = sprintf('%s 现在在第 %d 百分位，说明你不是偶然表现，而是有稳定倾向。', $label, $percentile);
            } else {
                $lines[] = sprintf('High %s: this axis takes over more of your default mode, typically showing up as %s.', $label, $band !== '' ? $band : 'a clearer behavioral style');
                $lines[] = sprintf('Low %s: if this axis eases, you rely more on scene cues and move toward the opposite end.', $label);
                $lines[] = sprintf('%s is currently at percentile %d, which suggests a stable tendency rather than a one-off spike.', $label, $percentile);
            }
        }

        return $lines;
    }

    /**
     * @param  list<array<string,mixed>>  $traitVector
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildCoreCareerLines(array $traitVector, array $sceneFingerprint, string $locale): array
    {
        $lines = [];
        foreach (array_slice($traitVector, 0, 5) as $trait) {
            if (! is_array($trait)) {
                continue;
            }

            $key = strtoupper(trim((string) ($trait['key'] ?? '')));
            $label = (string) ($trait['label'] ?? $key);
            $percentile = (int) ($trait['percentile'] ?? 0);
            $fit = $this->facetCareerFit($key, $locale);
            $scene = match ($key) {
                'O' => $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale),
                'C' => $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale),
                'E' => $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale),
                'A' => $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale),
                'N' => $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale),
                default => $locale === 'zh' ? '相对平衡' : 'balanced',
            };

            $lines[] = $locale === 'zh'
                ? sprintf('%s：第 %d 百分位，职业上更适合 %s；在这条轴上越高，你越容易在 %s 类环境中省力输出。', $label, $percentile, $fit, $scene)
                : sprintf('%s: percentile %d, with stronger fit toward %s; the higher this axis goes, the easier it becomes to output in %s-like environments.', $label, $percentile, $fit, $scene);
        }

        return $lines;
    }

    /**
     * @param  array<string,mixed>  $explainabilitySummary
     * @return list<string>
     */
    private function buildWhyThisProfileDeepLines(array $explainabilitySummary, string $locale): array
    {
        $reasons = array_values(array_filter(array_map('strval', is_array($explainabilitySummary['reasons'] ?? null) ? $explainabilitySummary['reasons'] : [])));
        $topFacets = array_values(array_filter(array_map('strval', is_array($explainabilitySummary['top_strength_facets'] ?? null) ? $explainabilitySummary['top_strength_facets'] : [])));

        $lines = [];
        $lines[] = $locale === 'zh'
            ? '解释层级 1：主轴决定默认动作，次轴决定你在不同场景里会不会保持一致。'
            : 'Layer 1: the lead axis sets the default move, while the secondary axis determines whether you stay consistent across scenes.';
        $lines[] = $locale === 'zh'
            ? '解释层级 2：第三轴与高分刻面会把“看起来不一样”的行为串成同一条逻辑线。'
            : 'Layer 2: the tertiary axis and strong facets tie apparently different behaviors into one logic line.';
        $lines[] = $locale === 'zh'
            ? '解释层级 3：场景指纹决定你在工作、关系、压力和恢复时，哪条轴会先被放大。'
            : 'Layer 3: the scene fingerprint decides which axis gets amplified first in work, relationships, stress, and recovery.';
        if ($reasons !== []) {
            $lines[] = $locale === 'zh'
                ? sprintf('解释依据：%s。', implode('；', array_slice($reasons, 0, 3)))
                : sprintf('Supporting reasons: %s.', implode(' | ', array_slice($reasons, 0, 3)));
        }
        if ($topFacets !== []) {
            $lines[] = $locale === 'zh'
                ? sprintf('高分刻面提示：%s 这些细节说明主轴不是空泛标签，而是可观察的行为簇。', implode(' / ', array_slice($topFacets, 0, 3)))
                : sprintf('Top facet cue: %s shows that the lead axis is a visible behavioral cluster, not a vague label.', implode(' / ', array_slice($topFacets, 0, 3)));
        }

        return $lines;
    }

    /**
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildRelationshipScenarioLines(array $sceneFingerprint, string $locale): array
    {
        return $locale === 'zh'
            ? [
                sprintf('同事场景：你更可能先用%s找到共同语言，再决定要不要把立场说满。', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
                sprintf('亲密关系场景：你会在%s和%s之间切换，既想被理解，也想保留自己的节奏。', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
                sprintf('群体场景：当多人同时发言时，你会先看%s，再决定是主动推进还是先观察。', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
            ]
            : [
                sprintf('Coworker scene: you usually seek common ground through %s before deciding whether to state your position fully.', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
                sprintf('Close-relationship scene: you switch between %s and %s, wanting to be understood while keeping your own rhythm.', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale), $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
                sprintf('Group scene: when several people talk at once, you first watch %s before deciding to move the conversation forward or observe.', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
            ];
    }

    /**
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildRelationshipBoundaryLines(array $sceneFingerprint, string $locale): array
    {
        return $locale === 'zh'
            ? [
                sprintf('边界提醒：如果你总是%s，别人会感到舒服，但你可能会越来越累。', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
                sprintf('修复动作：一旦误会出现，先把事实、感受和需求分开，再谈下一步。', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
                '关系信号：能不能直接说规则、能不能允许不同节奏，是判断这段关系是否适合你的关键。',
                '最佳环境：那些既能给你反馈，又不会强迫你用别人的节奏处理关系的场景。',
            ]
            : [
                sprintf('Boundary note: if you keep defaulting to %s, other people may feel comfortable while you quietly get exhausted.', $this->sceneLabel($sceneFingerprint['cooperation'] ?? 'balanced', $locale)),
                sprintf('Repair move: when misunderstandings appear, separate facts, feelings, and needs before discussing next steps; your %s posture helps you slow down enough to do that.', $this->sceneLabel($sceneFingerprint['stress_posture'] ?? 'responsive', $locale)),
                'Relationship signal: whether rules can be stated directly, and whether different rhythms are allowed, is the key test for fit.',
                'Best environment: scenes that give you feedback without forcing you to use someone else’s rhythm for every interaction.',
            ];
    }

    /**
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildWorkRoleLines(array $sceneFingerprint, string $locale): array
    {
        return $locale === 'zh'
            ? [
                sprintf('角色选择：更适合 %s 类型岗位，这类岗位通常允许你用 %s 处理变化。', $this->facetCareerFit('O', $locale), $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale)),
                sprintf('执行环境：如果工作要求 %s，你会更容易把能力稳定复用。', $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale)),
                sprintf('会议节奏：你往往需要先看 %s，再决定要不要把观点一次讲完。', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
                '团队分工：最好的配置通常是你既能看到全局，又能保留自己整理信息的空间。',
            ]
            : [
                sprintf('Role choice: you fit %s-type roles, which usually let you handle change through a %s mode.', $this->facetCareerFit('O', $locale), $this->sceneLabel($sceneFingerprint['novelty'] ?? 'balanced', $locale)),
                sprintf('Execution environment: when work demands %s, you are more likely to reuse your strengths steadily.', $this->sceneLabel($sceneFingerprint['structure'] ?? 'balanced', $locale)),
                sprintf('Meeting rhythm: you often need to check %s before deciding whether to deliver your view all at once.', $this->sceneLabel($sceneFingerprint['social_energy'] ?? 'balanced', $locale)),
                'Team design: the best setup usually lets you keep both the big picture and the space to organize information in your own way.',
            ];
    }

    /**
     * @param  array<string,mixed>  $actionPlanSummary
     * @return list<string>
     */
    private function buildGrowthCaseLines(array $actionPlanSummary, string $locale): array
    {
        $caseStudy = trim((string) ($actionPlanSummary['case_study'] ?? ''));
        $caseStudy2 = trim((string) ($actionPlanSummary['case_study_2'] ?? ''));
        $socialFocus = trim((string) ($actionPlanSummary['social_focus'] ?? ''));
        $healthFocus = trim((string) ($actionPlanSummary['health_focus'] ?? ''));
        $focusLabel = trim((string) ($actionPlanSummary['focus_trait_label'] ?? ''));
        $topGrowthFacets = array_values(array_filter(array_map('strval', is_array($actionPlanSummary['top_growth_facets'] ?? null) ? $actionPlanSummary['top_growth_facets'] : [])));

        return array_values(array_filter([
            $caseStudy !== ''
                ? ($locale === 'zh' ? sprintf('工作案例：%s', $caseStudy) : sprintf('Work case: %s', $caseStudy))
                : null,
            $caseStudy2 !== ''
                ? ($locale === 'zh' ? sprintf('补充案例：%s', $caseStudy2) : sprintf('Second case: %s', $caseStudy2))
                : null,
            $socialFocus !== ''
                ? ($locale === 'zh' ? sprintf('社交练习：%s', $socialFocus) : sprintf('Social practice: %s', $socialFocus))
                : null,
            $healthFocus !== ''
                ? ($locale === 'zh' ? sprintf('健康练习：%s', $healthFocus) : sprintf('Health practice: %s', $healthFocus))
                : null,
            $focusLabel !== ''
                ? ($locale === 'zh'
                    ? sprintf('长期主线：把 %s 做成稳定习惯，再去谈更大的改变。', $focusLabel)
                    : sprintf('Long-term line: turn %s into a stable habit before asking for bigger change.', $focusLabel))
                : null,
            $topGrowthFacets !== []
                ? ($locale === 'zh'
                    ? sprintf('优先刻面：%s。它们是你最值得重复练习的改变量。', implode(' / ', array_slice($topGrowthFacets, 0, 3)))
                    : sprintf('Priority facets: %s. These are the change levers worth rehearsing repeatedly.', implode(' / ', array_slice($topGrowthFacets, 0, 3))))
                : null,
        ], static fn ($value): bool => is_string($value) && trim($value) !== ''));
    }

    /**
     * @param  list<array<string,mixed>>  $facetVector
     * @return array<string,mixed>
     */
    private function buildFacetDetailSection(array $facetVector, string $locale): array
    {
        $groupedFacets = $this->groupFacetsByDomain($facetVector);

        return [
            'key' => 'facets.detail',
            'title' => $locale === 'zh' ? 'Facet 细节' : 'Facet Details',
            'access_level' => 'paid',
            'module_code' => 'big5_full',
            'blocks' => [
                [
                    'kind' => 'paragraph',
                    'title' => $locale === 'zh' ? '30 个 Facet 的深层解释' : 'Deep explanations for all 30 facets',
                    'body' => $locale === 'zh'
                        ? '这一层把每个 Facet 解释成位置、表现、影响、挑战、图表位置和实际行为，让抽象分数变成可以观察、比较和练习的自我画像。'
                        : 'This layer turns each facet into position, expression, impact, challenge, chart position, and real behavior so abstract scores become observable, comparable, and practice-ready.',
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '总览图表提示' : 'Chart-level guidance',
                    'body' => implode("\n", $locale === 'zh'
                        ? [
                            '把 30 个 Facet 当作 5 条域轴上的细分刻度：越高说明这条微轴越容易在真实行为里被看见。',
                            '图表上更外侧的点，往往对应更明显的行为风格；更内侧的点，往往对应更节制或更依赖情境的表现。',
                            '真正有用的不是单个分数，而是同一维度里多个 Facet 是否共同朝一个方向移动。',
                        ]
                        : [
                            'Treat the 30 facets as fine-grained ticks on the five domain axes: the farther out they sit, the more visible they become in real behavior.',
                            'Points toward the outside of the chart usually mean a more visible style; points nearer the center usually mean a more restrained or context-sensitive pattern.',
                            'What matters most is not a single score, but whether multiple facets in the same domain move in the same direction.',
                        ]),
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '分域提示' : 'Domain-by-domain cues',
                    'body' => implode("\n", $this->buildFacetDomainIntroLines($groupedFacets, $locale)),
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? 'Facet 清单' : 'Facet list',
                    'body' => implode("\n", $this->buildFacetDetailLines($facetVector, $locale)),
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $facetVector
     * @return list<string>
     */
    private function buildFacetDetailLines(array $facetVector, string $locale): array
    {
        $lines = [];
        foreach ($facetVector as $facet) {
            if (! is_array($facet)) {
                continue;
            }

            $lines[] = $this->facetDetailLine($facet, $locale);
        }

        return $lines;
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $groupedFacets
     * @return list<string>
     */
    private function buildFacetDomainIntroLines(array $groupedFacets, string $locale): array
    {
        $lines = [];
        foreach (self::DOMAIN_ORDER as $domain) {
            $facets = $groupedFacets[$domain] ?? [];
            if ($facets === []) {
                continue;
            }

            $count = count($facets);
            $domainLabel = $locale === 'zh'
                ? match ($domain) {
                    'N' => 'N 维（压力与变化）',
                    'E' => 'E 维（社交与能量）',
                    'O' => 'O 维（探索与想象）',
                    'A' => 'A 维（合作与体谅）',
                    'C' => 'C 维（秩序与推进）',
                    default => $domain,
                }
                : match ($domain) {
                    'N' => 'N domain (stress and change)',
                    'E' => 'E domain (social energy)',
                    'O' => 'O domain (exploration and imagination)',
                    'A' => 'A domain (cooperation and consideration)',
                    'C' => 'C domain (order and follow-through)',
                    default => $domain,
                };

            $lines[] = $locale === 'zh'
                ? sprintf('%s：包含 %d 个 Facet。这里看的不是单点，而是这一整组微轴如何一起塑造你的默认动作。', $domainLabel, $count)
                : sprintf('%s: %d facets. The key question is not any one point, but how this whole micro-axis set shapes your defaults together.', $domainLabel, $count);
        }

        return $lines;
    }

    /**
     * @param  array<string,mixed>  $facet
     */
    private function facetDetailLine(array $facet, string $locale): string
    {
        $code = strtoupper(trim((string) ($facet['key'] ?? '')));
        $label = (string) ($facet['label'] ?? $code);
        $domain = strtoupper(substr($code, 0, 1));
        $bucket = strtolower(trim((string) ($facet['bucket'] ?? 'mid')));
        $percentile = (int) ($facet['percentile'] ?? 0);

        $domainMeaning = $locale === 'zh'
            ? match ($domain) {
                'N' => '更早感知压力与变化',
                'E' => '更容易外放表达和获得社交能量',
                'O' => '更偏探索、想象与新经验',
                'A' => '更关注协作、体谅与边界',
                'C' => '更关注秩序、责任与推进',
                default => '更强的结构化信号',
            }
            : match ($domain) {
                'N' => 'sensitive to stress and change earlier',
                'E' => 'more outward, expressive, and energized by people',
                'O' => 'more exploratory, imaginative, and novelty-seeking',
                'A' => 'more focused on cooperation, consideration, and boundaries',
                'C' => 'more oriented toward order, duty, and follow-through',
                default => 'a stronger structural signal',
            };

        $behavior = $locale === 'zh'
            ? match ($bucket) {
                'low' => '表现更收敛，常见于节制、谨慎或不那么外显的行为',
                'high', 'extreme_high' => '表现更明显，常见于更强烈、可见度更高的行为',
                default => '表现更平衡，往往受情境影响更大',
            }
            : match ($bucket) {
                'low' => 'shows up more quietly, often as restraint, caution, or lower visibility',
                'high', 'extreme_high' => 'shows up more visibly, often as stronger and more obvious behavior',
                default => 'stays more balanced and is usually more context-sensitive',
            };

        $challenge = $locale === 'zh'
            ? match ($domain.'.'.$bucket) {
                'N.low' => '留意是否把压力信号藏得太晚',
                'N.high', 'N.extreme_high' => '留意是否过早放大风险或情绪波动',
                'E.low' => '留意是否错过连接和表达的窗口',
                'E.high', 'E.extreme_high' => '留意是否说得太快而压过倾听',
                'O.low' => '留意是否过早锁定熟路而少了试验',
                'O.high', 'O.extreme_high' => '留意是否只停在新鲜感而没有收束',
                'A.low' => '留意是否因为过于直接而让边界变硬',
                'A.high', 'A.extreme_high' => '留意是否因为太体谅而不敢说不',
                'C.low' => '留意是否因为太灵活而少了收口',
                'C.high', 'C.extreme_high' => '留意是否因为太有序而压缩弹性',
                default => '留意是否过度放大这一维的单一解释',
            }
            : match ($domain.'.'.$bucket) {
                'N.low' => 'watch for stress signals surfacing too late',
                'N.high', 'N.extreme_high' => 'watch for over-reading risk or emotional spikes',
                'E.low' => 'watch for missing the window to connect or speak up',
                'E.high', 'E.extreme_high' => 'watch for speaking so fast that listening gets crowded out',
                'O.low' => 'watch for locking into familiar paths too early',
                'O.high', 'O.extreme_high' => 'watch for staying in novelty without enough closure',
                'A.low' => 'watch for boundaries getting hard because you are too direct',
                'A.high', 'A.extreme_high' => 'watch for over-accommodating and not saying no',
                'C.low' => 'watch for too much flexibility and not enough closure',
                'C.high', 'C.extreme_high' => 'watch for structure crowding out flexibility',
                default => 'watch for over-reading this trait as a single story',
            };

        $visual = $locale === 'zh'
            ? match ($bucket) {
                'low' => '图表上更靠内，像一条被收起的刻度。',
                'high', 'extreme_high' => '图表上更靠外，像一条被拉开的刻度。',
                default => '图表上通常在中间，像一条受情境影响的刻度。',
            }
            : match ($bucket) {
                'low' => 'on the chart it sits closer to the center, like a folded-in tick mark.',
                'high', 'extreme_high' => 'on the chart it sits closer to the edge, like a stretched-out tick mark.',
                default => 'on the chart it usually sits in the middle, like a context-sensitive tick mark.',
            };

        $realExample = $locale === 'zh'
            ? match ($domain.'.'.$bucket) {
                'N.low' => '现实例子：你可能更晚才承认压力已经超标，但一旦确认就会更务实地处理。',
                'N.high', 'N.extreme_high' => '现实例子：你会更早察觉波动，并倾向先做风险预警或情绪整理。',
                'E.low' => '现实例子：你更可能先观察再回应，社交能量会花得更省。',
                'E.high', 'E.extreme_high' => '现实例子：你更容易主动开口、带动讨论，并在互动中获得能量。',
                'O.low' => '现实例子：你会优先沿用成熟方法，减少不必要的试错。',
                'O.high', 'O.extreme_high' => '现实例子：你会主动尝试新路径，并愿意把灵感快速做成实验。',
                'A.low' => '现实例子：你更容易直接说出立场，不太愿意绕弯。',
                'A.high', 'A.extreme_high' => '现实例子：你更常先顾及对方感受，再处理分歧。',
                'C.low' => '现实例子：你会保留较多临场弹性，允许计划边做边调。',
                'C.high', 'C.extreme_high' => '现实例子：你会更主动地建流程、盯进度、锁定交付节奏。',
                default => '现实例子：它会更多体现在具体情境里的默认处理方式。',
            }
            : match ($domain.'.'.$bucket) {
                'N.low' => 'Real-life example: you may notice stress only later, but once you do, you handle it pragmatically.',
                'N.high', 'N.extreme_high' => 'Real-life example: you spot fluctuations earlier and tend to preempt risk or regulate emotion first.',
                'E.low' => 'Real-life example: you usually observe before replying, which makes your social energy go further.',
                'E.high', 'E.extreme_high' => 'Real-life example: you speak up more readily, drive discussion, and recharge through interaction.',
                'O.low' => 'Real-life example: you prefer proven methods and reduce unnecessary trial and error.',
                'O.high', 'O.extreme_high' => 'Real-life example: you actively try new routes and turn ideas into experiments quickly.',
                'A.low' => 'Real-life example: you state your position directly and do not like too much circling around it.',
                'A.high', 'A.extreme_high' => 'Real-life example: you usually account for other people’s feelings before handling disagreement.',
                'C.low' => 'Real-life example: you leave more room for on-the-fly adjustment and allow the plan to evolve while executing.',
                'C.high', 'C.extreme_high' => 'Real-life example: you are more likely to build process, track progress, and stabilize delivery rhythm.',
                default => 'Real-life example: it shows up more in how you default to handling real situations.',
            };

        return $locale === 'zh'
            ? sprintf('%s：第 %d 百分位，当前偏 %s；%s；%s；职业上更常适配 %s；挑战是 %s。', $label, $percentile, $domainMeaning, $behavior, $visual, $this->facetCareerFit($domain, $locale), $challenge)
            : sprintf('%s: percentile %d, currently %s; %s; %s; career fit often leans toward %s; challenge: %s.', $label, $percentile, $domainMeaning, $behavior, $visual, $this->facetCareerFit($domain, $locale), $challenge);
    }

    private function facetCareerFit(string $domain, string $locale): string
    {
        return $locale === 'zh'
            ? match ($domain) {
                'N' => '风险预警、复盘、支持性角色',
                'E' => '沟通推进、销售、主持、协调角色',
                'O' => '研究、创意、产品探索、策略角色',
                'A' => '服务、教育、HR、调解角色',
                'C' => '运营、项目管理、质量、合规角色',
                default => '需要综合判断的岗位',
            }
            : match ($domain) {
                'N' => 'risk detection, review, and support roles',
                'E' => 'communication, sales, facilitation, and coordination roles',
                'O' => 'research, creative, product discovery, and strategy roles',
                'A' => 'service, education, HR, and mediation roles',
                'C' => 'operations, project management, quality, and compliance roles',
                default => 'roles that require integrated judgment',
            };
    }

    /**
     * @param  list<array<string,mixed>>  $facetVector
     * @return array<string,list<array<string,mixed>>>
     */
    private function groupFacetsByDomain(array $facetVector): array
    {
        $groups = [
            'N' => [],
            'E' => [],
            'O' => [],
            'A' => [],
            'C' => [],
        ];

        foreach ($facetVector as $facet) {
            if (! is_array($facet)) {
                continue;
            }

            $code = strtoupper(trim((string) ($facet['key'] ?? '')));
            $domain = strtoupper(substr($code, 0, 1));
            if (! array_key_exists($domain, $groups)) {
                continue;
            }

            $groups[$domain][] = $facet;
        }

        return $groups;
    }

    /**
     * @param  array<string,mixed>  $comparativeV1
     * @param  array<string,mixed>  $leadTrait
     * @return array<string,mixed>
     */
    private function buildComparativeSection(array $comparativeV1, array $leadTrait, string $locale): array
    {
        $comparison = is_array($comparativeV1['cohort_relative_position'] ?? null) ? $comparativeV1['cohort_relative_position'] : [];
        $sameTypeContrast = is_array($comparativeV1['same_type_contrast'] ?? null) ? $comparativeV1['same_type_contrast'] : [];
        $percentile = (int) data_get($comparativeV1, 'percentile.value', 0);
        $metricLabel = (string) data_get($comparativeV1, 'percentile.metric_label', '');
        $normingVersion = trim((string) ($comparativeV1['norming_version'] ?? ''));
        $normingScope = trim((string) ($comparativeV1['norming_scope'] ?? ''));
        $normingSource = trim((string) ($comparativeV1['norming_source'] ?? ''));

        $summary = trim((string) ($comparison['summary'] ?? ''));
        $contrastSummary = trim((string) ($sameTypeContrast['summary'] ?? ''));
        $leadLabel = trim((string) ($leadTrait['label'] ?? $leadTrait['key'] ?? ''));

        return [
            'key' => 'comparative.norms',
            'title' => $locale === 'zh' ? '常模与比较' : 'Norms & Comparison',
            'access_level' => 'paid',
            'module_code' => 'big5_full',
            'blocks' => [
                [
                    'kind' => 'paragraph',
                    'title' => $locale === 'zh' ? '常模位置与同类对照' : 'Cohort position and same-type contrast',
                    'body' => $locale === 'zh'
                        ? '这一节把你的结果放回常模、同类对照、历史快照和行为预测里，帮助你判断它是偶然波动，还是稳定风格。'
                        : 'This section places the read back into norms, same-type contrast, historical snapshots, and behavior forecasting so you can tell whether it is a temporary fluctuation or a stable style.',
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '全球 / 群体位置' : 'Global / cohort position',
                    'body' => implode("\n", $this->buildComparativeLines($comparison, $percentile, $metricLabel, $normingVersion, $normingScope, $normingSource, $locale)),
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '同类型对照' : 'Same-type contrast',
                    'body' => implode("\n", $this->buildSameTypeContrastLines($sameTypeContrast, $leadLabel, $locale)),
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '历史与预测' : 'History and forecast',
                    'body' => implode("\n", $this->buildForecastLines($leadLabel, $locale)),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $comparison
     * @return list<string>
     */
    private function buildComparativeLines(array $comparison, int $percentile, string $metricLabel, string $normingVersion, string $normingScope, string $normingSource, string $locale): array
    {
        $summary = trim((string) ($comparison['summary'] ?? ''));

        return $locale === 'zh'
            ? [
                $summary !== ''
                    ? $summary
                    : sprintf('你当前在 %s 这一维的结果位于当前常模中的 %d 百分位。', $metricLabel !== '' ? $metricLabel : '主轴', $percentile),
                sprintf('常模版本：%s；常模范围：%s；来源：%s。', $normingVersion !== '' ? $normingVersion : 'unknown', $normingScope !== '' ? $normingScope : 'unknown', $normingSource !== '' ? $normingSource : 'scale_norms'),
                '这不是“你比别人更好”，而是“你更常在哪些行为模式里省力”。',
                '如果同一结果重复出现，说明这条轴更可能是稳定倾向，而不是一次性的状态波动。',
            ]
            : [
                $summary !== ''
                    ? $summary
                    : sprintf('Your current result on this lead trait sits at the %dth percentile within the current norm set.', $percentile),
                sprintf('Norm version: %s; norm scope: %s; source: %s.', $normingVersion !== '' ? $normingVersion : 'unknown', $normingScope !== '' ? $normingScope : 'unknown', $normingSource !== '' ? $normingSource : 'scale_norms'),
                'This is not about being better than others; it is about where you are likely to spend less effort in real behavior.',
                'If the same pattern repeats over time, the axis is more likely to be a stable tendency than a one-off state.',
            ];
    }

    /**
     * @param  array<string,mixed>  $sameTypeContrast
     * @return list<string>
     */
    private function buildSameTypeContrastLines(array $sameTypeContrast, string $leadLabel, string $locale): array
    {
        $summary = trim((string) ($sameTypeContrast['summary'] ?? ''));

        return $locale === 'zh'
            ? [
                $summary !== ''
                    ? $summary
                    : sprintf('同类型对比显示，你的 %s 组合更偏向当前这组行为风格，而不是另一端的默认模式。', $leadLabel !== '' ? $leadLabel : '主轴'),
                '同类型的人也会因为刻面组合不同而呈现完全不同的外观，所以“像不像自己人”不是关键，关键是默认动作是否一致。',
                '如果你和同类对比时差异很大，这通常意味着你的辅助轴比平均同类更有存在感。',
            ]
            : [
                $summary !== ''
                    ? $summary
                    : sprintf('Same-type contrast suggests your %s mix leans toward this operating style rather than the opposite default.', $leadLabel !== '' ? $leadLabel : 'lead-trait'),
                'People in the same broad type can still look very different because facet combinations reshape the outward style; the key question is whether the default move is the same.',
                'If you look quite different from peers with the same profile, that often means the secondary axes are carrying more weight than average.',
            ];
    }

    /**
     * @return list<string>
     */
    private function buildForecastLines(string $leadLabel, string $locale): array
    {
        return $locale === 'zh'
            ? [
                $leadLabel !== ''
                    ? sprintf('行为预测：如果当前带宽保持稳定，%s 这条主轴会继续影响你在决策、协作和恢复中的默认选择。', $leadLabel)
                    : '行为预测：如果当前带宽保持稳定，主轴会继续影响你在决策、协作和恢复中的默认选择。',
                '历史轨迹提示：把下一次结果和这一次的主轴、百分位、top facets 对照，就能看到成长曲线。',
                '轨迹解读要看连续快照，不要拿一次测试直接下结论。',
                '如果未来分数变化，重点看变化是否出现在同一条轴上，还是只在某些刻面上局部移动。',
            ]
            : [
                $leadLabel !== ''
                    ? sprintf('Behavior forecast: if the current band stays stable, %s will continue to shape your defaults in decision-making, collaboration, and recovery.', $leadLabel)
                    : 'Behavior forecast: if the current band stays stable, the lead trait will continue to shape your defaults in decision-making, collaboration, and recovery.',
                'Trajectory note: compare the next snapshot against this one to see whether the lead trait, percentile, and top facets are moving.',
                'Read the trajectory through repeated snapshots rather than a single test result.',
                'If scores change later, focus on whether the shift happens on the same axis or only in a few local facets.',
            ];
    }

    private function buildDomainDeepDiveSection(array $traitVector, array $sceneFingerprint, string $locale): array
    {
        return [
            'key' => 'domains.deep_dive',
            'title' => $locale === 'zh' ? '五维深解' : 'Domain Deep Dive',
            'access_level' => 'paid',
            'module_code' => 'big5_full',
            'blocks' => [
                [
                    'kind' => 'paragraph',
                    'title' => $locale === 'zh' ? '五维在现实中的样子' : 'How the five domains show up in real life',
                    'body' => $locale === 'zh'
                        ? '这一层把五大维度放回日常生活、工作方式、关系模式、压力反应和职业适配中，帮助你把分数读成行为。'
                        : 'This layer translates the five domains into daily life, work style, relationship patterns, stress response, and career fit so you can read the scores as behavior.',
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '场景化解释' : 'Scene-based explanations',
                    'body' => implode("\n", $this->buildDomainDeepDiveLines($traitVector, $sceneFingerprint, $locale)),
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '职业适配' : 'Career fit',
                    'body' => implode("\n", $this->buildDomainCareerLines($traitVector, $locale)),
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '高低位点与误读' : 'High/low points and pitfalls',
                    'body' => implode("\n", $this->buildDomainContrastLines($traitVector, $locale)),
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $traitVector
     * @param  array<string,string>  $sceneFingerprint
     * @return list<string>
     */
    private function buildDomainDeepDiveLines(array $traitVector, array $sceneFingerprint, string $locale): array
    {
        $lines = [];
        foreach ($traitVector as $trait) {
            if (! is_array($trait)) {
                continue;
            }

            $key = strtoupper(trim((string) ($trait['key'] ?? '')));
            $label = (string) ($trait['label'] ?? $key);
            $percentile = (int) ($trait['percentile'] ?? 0);
            $bandLabel = (string) ($trait['band_label'] ?? 'balanced');
            $scene = $locale === 'zh'
                ? match ($key) {
                    'O' => '现实里你更愿意先试新方法，再把陌生信息变成想法；在学习、探索和产品思考里尤其明显。',
                    'C' => '现实里你更容易拆步骤、建流程、盯进度；在执行、交付和项目推进里尤其明显。',
                    'E' => '现实里你更容易外放表达、带动对话；在会议、社交和推进协作里尤其明显。',
                    'A' => '现实里你更容易先考虑关系温度和合作可行性；在支持、协商和修复里尤其明显。',
                    'N' => '现实里你更早感知压力和变化；在风险预警和恢复安排里尤其明显。',
                    default => '现实里这一维更多受场景影响。',
                }
                : match ($key) {
                    'O' => 'In real life, you are more likely to try a new method first and turn unfamiliar input into ideas; this is most visible in learning, discovery, and product thinking.',
                    'C' => 'In real life, you are more likely to break work into steps, build process, and track progress; this is most visible in execution, delivery, and project movement.',
                    'E' => 'In real life, you are more likely to express outwardly and carry the conversation; this is most visible in meetings, social settings, and collaborative motion.',
                    'A' => 'In real life, you are more likely to consider relationship temperature and cooperation first; this is most visible in support, negotiation, and repair.',
                    'N' => 'In real life, you are more likely to notice stress and change earlier; this is most visible in risk detection and recovery planning.',
                    default => 'In real life, this axis is shaped more by the scene.',
                };

            $workScene = $locale === 'zh'
                ? match ($key) {
                    'O' => '工作场景：更适合研究、策略、产品探索和创意类任务，因为你会先看可能性。',
                    'C' => '工作场景：更适合运营、项目管理、质量和合规类任务，因为你会先看结构。',
                    'E' => '工作场景：更适合销售、主持、协调和客户成功类任务，因为你会先看互动。',
                    'A' => '工作场景：更适合服务、教育、HR 和调解类任务，因为你会先看合作。',
                    'N' => '工作场景：更适合监测、复盘、支持和风险类任务，因为你会先看波动。',
                    default => '工作场景：你会根据具体任务放大或收敛这条轴。',
                }
                : match ($key) {
                    'O' => 'Work scene: best fit often shows up in research, strategy, product discovery, and creative tasks because you look for possibilities first.',
                    'C' => 'Work scene: best fit often shows up in operations, project management, quality, and compliance because you look for structure first.',
                    'E' => 'Work scene: best fit often shows up in sales, facilitation, coordination, and customer success because you look for interaction first.',
                    'A' => 'Work scene: best fit often shows up in service, education, HR, and mediation because you look for cooperation first.',
                    'N' => 'Work scene: best fit often shows up in monitoring, review, support, and risk work because you look for fluctuation first.',
                    default => 'Work scene: the task decides how much this axis gets amplified.',
                };

            $relationshipScene = $locale === 'zh'
                ? match ($key) {
                    'O' => '关系场景：你会更在意关系里有没有足够空间去试新表达或新规则。',
                    'C' => '关系场景：你会更在意对方是否守约、节奏是否可预期。',
                    'E' => '关系场景：你会更在意互动是否有来有回、能量是否够外放。',
                    'A' => '关系场景：你会更在意是否能维持温度、减少硬碰硬。',
                    'N' => '关系场景：你会更在意关系里有没有安全感和压力缓冲。',
                    default => '关系场景：你会根据关系类型调整这条轴的表达。',
                }
                : match ($key) {
                    'O' => 'Relationship scene: you care whether there is room to try new expressions or new rules.',
                    'C' => 'Relationship scene: you care whether commitments are kept and the rhythm is predictable.',
                    'E' => 'Relationship scene: you care whether interaction has give-and-take and enough outward energy.',
                    'A' => 'Relationship scene: you care whether the temperature stays warm and hard collisions are reduced.',
                    'N' => 'Relationship scene: you care whether there is enough safety and pressure buffering.',
                    default => 'Relationship scene: you adjust the expression of this axis by relationship type.',
                };

            $contrast = $locale === 'zh'
                ? match ($key) {
                    'O' => '高位更敢试新，低位更务实守熟路。',
                    'C' => '高位更讲结构，低位更讲弹性。',
                    'E' => '高位更外放推进，低位更克制收束。',
                    'A' => '高位更体谅配合，低位更直接清边界。',
                    'N' => '高位更敏感早反应，低位更稳定晚波动。',
                    default => '高低位差异通常体现在场景选择。',
                }
                : match ($key) {
                    'O' => 'High scores mean more willingness to experiment; low scores mean more reliance on familiar routes.',
                    'C' => 'High scores mean more structure; low scores mean more flexibility.',
                    'E' => 'High scores mean more outward motion; low scores mean more restraint.',
                    'A' => 'High scores mean more consideration and cooperation; low scores mean more direct boundary-setting.',
                    'N' => 'High scores mean earlier sensitivity and reactivity; low scores mean steadier and later shifts.',
                    default => 'High/low differences usually show up in scene choice.',
                };

            $lines[] = $locale === 'zh'
                ? sprintf('%s：第 %d 百分位，当前偏 %s；%s；%s；%s；高低位点对照：%s。', $label, $percentile, $bandLabel, $scene, $workScene, $relationshipScene, $contrast)
                : sprintf('%s: percentile %d, currently leaning %s; %s; %s; %s; high/low contrast: %s.', $label, $percentile, $bandLabel, $scene, $workScene, $relationshipScene, $contrast);
        }

        return $lines;
    }

    /**
     * @param  list<array<string,mixed>>  $traitVector
     * @return list<string>
     */
    private function buildDomainCareerLines(array $traitVector, string $locale): array
    {
        $lines = [];
        foreach ($traitVector as $trait) {
            if (! is_array($trait)) {
                continue;
            }

            $key = strtoupper(trim((string) ($trait['key'] ?? '')));
            $label = (string) ($trait['label'] ?? $key);
            $fit = $this->facetCareerFit($key, $locale);
            $lines[] = $locale === 'zh'
                ? sprintf('%s：职业适配更偏 %s；如果这一维提高，你会更容易在对应岗位里自然省力。', $label, $fit)
                : sprintf('%s: career fit leans toward %s; if this axis strengthens, you will likely feel more natural in those roles.', $label, $fit);
        }

        return $lines;
    }

    /**
     * @param  list<array<string,mixed>>  $traitVector
     * @return list<string>
     */
    private function buildDomainContrastLines(array $traitVector, string $locale): array
    {
        $lines = [];
        foreach ($traitVector as $trait) {
            if (! is_array($trait)) {
                continue;
            }

            $key = strtoupper(trim((string) ($trait['key'] ?? '')));
            $label = (string) ($trait['label'] ?? $key);
            $percentile = (int) ($trait['percentile'] ?? 0);
            $bucket = strtolower(trim((string) ($trait['bucket'] ?? 'mid')));
            $lines[] = $locale === 'zh'
                ? match ($bucket) {
                    'low' => sprintf('%s：低位时会更收敛、更依赖环境提示；要小心把这种收敛误读成“没有这个特质”。', $label),
                    'high', 'extreme_high' => sprintf('%s：高位时会更鲜明、更容易被看见；要小心把这种鲜明误读成“永远都这样”。', $label),
                    default => sprintf('%s：中位时最受场景影响，常常在熟悉和陌生环境里表现得不一样。', $label),
                }
                : match ($bucket) {
                    'low' => sprintf('%s: at the low end, the axis becomes more restrained and cue-dependent; do not mistake that for absence.', $label),
                    'high', 'extreme_high' => sprintf('%s: at the high end, the axis becomes more visible; do not mistake that visibility for permanence.', $label),
                    default => sprintf('%s: in the middle range, context matters a lot, so it can look different across scenes.', $label),
                };
            $lines[] = $locale === 'zh'
                ? sprintf('百分位参考：第 %d 百分位只是位置，不是价值判断。', $percentile)
                : sprintf('Percentile reference: percentile %d is only a position, not a value judgment.', $percentile);
        }

        return $lines;
    }

    private function buildMethodologySection(string $qualityLevel, string $normStatus, string $locale): array
    {
        return [
            'key' => 'methodology.access',
            'title' => $locale === 'zh' ? '方法与边界' : 'Methodology & Access',
            'access_level' => 'paid',
            'module_code' => 'big5_full',
            'blocks' => [
                [
                    'kind' => 'paragraph',
                    'title' => $locale === 'zh' ? '这份报告怎么理解' : 'How to read this report',
                    'body' => $locale === 'zh'
                        ? 'Big Five 是五个连续维度的特质模型，不是把人塞进固定类型。百分位是在常模里对比你的相对位置，质量等级和常模状态则告诉你这次读数有多稳。它更像一张可重复更新的行为地图，而不是一次性的标签。'
                        : 'Big Five is a five-domain trait model, not a fixed type box. Percentiles compare your relative position against a norm group, while quality level and norm status tell you how stable this read is. It works more like a repeatable behavior map than a one-time label.',
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '科学依据' : 'Scientific basis',
                    'body' => implode("\n", $this->buildMethodologyScienceLines($qualityLevel, $normStatus, $locale)),
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '应用方式' : 'How to use it',
                    'body' => implode("\n", $this->buildMethodologyUseCaseLines($locale)),
                ],
                [
                    'kind' => 'bullets',
                    'title' => $locale === 'zh' ? '使用边界与风险提示' : 'Boundaries and cautions',
                    'body' => implode("\n", $this->buildMethodologyBoundaryLines($locale)),
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function buildMethodologyScienceLines(string $qualityLevel, string $normStatus, string $locale): array
    {
        return $locale === 'zh'
            ? [
                sprintf('读数稳定性：质量等级 %s；常模状态 %s。', $qualityLevel !== '' ? $qualityLevel : 'unknown', $normStatus !== '' ? $normStatus : 'unknown'),
                '科学基础：Big Five 是人格心理学里最常用的连续维度模型之一，核心假设是“特质分布在连续谱上”，不是非黑即白。',
                '常模意义：百分位的作用是把你的结果放回一个参照群体里，方便你理解它在总体中的相对位置。',
                '解释原则：单个分数没有结论力，必须结合刻面、场景指纹和重复快照一起看。',
            ]
            : [
                sprintf('Read stability: quality level %s; norm status %s.', $qualityLevel !== '' ? $qualityLevel : 'unknown', $normStatus !== '' ? $normStatus : 'unknown'),
                'Scientific basis: Big Five is one of the most widely used continuous trait models in personality psychology, built on the idea that traits lie on spectra rather than in black-and-white boxes.',
                'Norm meaning: percentiles place your result back into a reference group so you can understand its relative position in the broader population.',
                'Interpretation rule: no single score is decisive; facets, scene fingerprint, and repeated snapshots need to be read together.',
            ];
    }

    /**
     * @return list<string>
     */
    private function buildMethodologyUseCaseLines(string $locale): array
    {
        return $locale === 'zh'
            ? [
                '职业规划：用来判断你在哪种工作节奏、结构、反馈和协作方式里更省力。',
                '团队合作：用来判断你更适合先对齐规则，还是先启动互动，再把节奏调回来。',
                '个人成长：用来设计短期练习、长期习惯和复盘方式，而不是做一次性自我评价。',
                '生活方式：用来优化作息、恢复、刺激输入和社交消耗的分配。',
            ]
            : [
                'Career planning: use it to see which work rhythm, structure, feedback, and collaboration style will feel easier.',
                'Teamwork: use it to decide whether you should align rules first or start interaction first and then adjust rhythm later.',
                'Personal growth: use it to design short drills, long habits, and review methods instead of doing a one-time self-judgment.',
                'Lifestyle: use it to optimize sleep, recovery, stimulus intake, and social energy spending.',
            ];
    }

    /**
     * @return list<string>
     */
    private function buildMethodologyBoundaryLines(string $locale): array
    {
        return $locale === 'zh'
            ? [
                '它不是诊断工具，不能替代心理健康、医学或职业测评中的专业判断。',
                '它更适合解释“默认倾向”，不适合解释所有极端状态、临时压力或场景伪装。',
                '如果未来结果变化，先看场景、状态和作答质量，再看人格本身是否真的变了。',
                '最好的用法不是把结果当定论，而是把它当作可复核、可对照、可迭代的行为假设。',
            ]
            : [
                'It is not a diagnostic tool and cannot replace professional judgment in mental health, medical, or career assessment settings.',
                'It is better at explaining default tendencies than extreme temporary states, short-term stress, or scene-based masking.',
                'If future results change, check the scene, state, and response quality first before assuming the personality itself changed.',
                'The best use is not to treat the result as a verdict, but as a behavior hypothesis that can be checked, compared, and revised.',
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
