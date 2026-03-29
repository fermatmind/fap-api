<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\Eq60PackLoader;

final class Eq60ReportComposer
{
    public function __construct(
        private readonly Eq60PackLoader $packLoader,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        $version = trim((string) ($attempt->dir_version ?? Eq60PackLoader::PACK_VERSION));
        if ($version === '') {
            $version = Eq60PackLoader::PACK_VERSION;
        }

        $locale = $this->packLoader->normalizeLocale((string) ($attempt->locale ?? 'zh-CN'));
        $reportCompiled = $this->packLoader->readCompiledJson('report.compiled.json', $version);
        if (! is_array($reportCompiled)) {
            return [
                'ok' => false,
                'error' => 'REPORT_LAYOUT_MISSING',
                'message' => 'EQ_60 report compiled data missing.',
                'status' => 500,
            ];
        }

        $score = $this->extractScoreResult($result);
        if (! is_array($score)) {
            return [
                'ok' => false,
                'error' => 'REPORT_SCORE_RESULT_MISSING',
                'message' => 'EQ_60 score result missing.',
                'status' => 500,
            ];
        }

        $modulesAllowed = ReportAccess::normalizeModules(is_array($ctx['modules_allowed'] ?? null) ? $ctx['modules_allowed'] : []);
        if ($modulesAllowed === []) {
            $modulesAllowed = ReportAccess::defaultModulesAllowedForLocked(ReportAccess::SCALE_EQ_60);
        }

        $layoutSections = is_array(data_get($reportCompiled, 'layout.sections'))
            ? array_values(array_filter((array) data_get($reportCompiled, 'layout.sections'), 'is_array'))
            : [];
        if ($layoutSections === []) {
            $layoutSections = $this->defaultSections();
        }

        $layoutByKey = [];
        foreach ($layoutSections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $key = trim((string) ($section['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $layoutByKey[$key] = $section;
        }

        $sections = [];
        foreach ($layoutSections as $sectionConfig) {
            if (! is_array($sectionConfig)) {
                continue;
            }

            $sectionKey = trim((string) ($sectionConfig['key'] ?? ''));
            if ($sectionKey === '') {
                continue;
            }

            $requiredVariants = $this->normalizeVariants((array) ($sectionConfig['required_in_variant'] ?? ['free', 'full']));
            if ($requiredVariants !== [] && ! in_array($variant, $requiredVariants, true)) {
                continue;
            }

            $source = strtolower(trim((string) ($sectionConfig['source'] ?? 'blocks')));
            $accessLevel = strtolower(trim((string) ($sectionConfig['access_level'] ?? 'free')));
            $moduleCode = $this->normalizeModuleCode((string) ($sectionConfig['module_code'] ?? ReportAccess::MODULE_EQ_CORE));
            $maxBlocks = max(1, (int) ($sectionConfig['max_blocks'] ?? 1));

            if ($accessLevel === 'paid') {
                if ($variant !== ReportAccess::VARIANT_FULL) {
                    continue;
                }
                if (! in_array($moduleCode, $modulesAllowed, true)) {
                    continue;
                }
            }

            if ($source === 'copy') {
                $copySection = $this->composeCopySection($sectionKey, $locale, $accessLevel, $moduleCode);
                if (is_array($copySection)) {
                    $sections[] = $copySection;
                }

                continue;
            }

            $sectionBlocks = $this->resolveSectionBlocks($reportCompiled, $locale, $sectionConfig, $score);
            if ($sectionBlocks === []) {
                continue;
            }

            if (count($sectionBlocks) > $maxBlocks) {
                $sectionBlocks = array_slice($sectionBlocks, 0, $maxBlocks);
            }

            $sections[] = [
                'key' => $sectionKey,
                'title' => $this->resolveSectionTitle($sectionKey, $locale, $layoutByKey),
                'access_level' => $accessLevel,
                'module_code' => $moduleCode,
                'blocks' => $sectionBlocks,
            ];
        }

        [$compatFree, $compatPaid] = $this->buildCompatBlocks($sections);

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'eq_60.report.v2',
                'scale_code' => 'EQ_60',
                'variant' => $variant,
                'locale' => $locale,
                'sections' => $sections,
                'compat' => [
                    'free_blocks' => $compatFree,
                    'paid_blocks' => $compatPaid,
                ],
                'quality' => is_array($score['quality'] ?? null) ? $score['quality'] : [],
                'scores' => is_array($score['scores'] ?? null) ? $score['scores'] : [],
                'report' => is_array($score['report'] ?? null) ? $score['report'] : [],
                'report_tags' => array_values(array_filter(
                    array_map('strval', (array) ($score['report_tags'] ?? [])),
                    static fn (string $tag): bool => $tag !== ''
                )),
                'generated_at' => now()->toISOString(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractScoreResult(Result $result): ?array
    {
        $payload = is_array($result->result_json ?? null) ? $result->result_json : [];
        $candidates = [
            $payload['normed_json'] ?? null,
            $payload['breakdown_json']['score_result'] ?? null,
            $payload['axis_scores_json']['score_result'] ?? null,
            $payload,
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            if (strtoupper((string) ($candidate['scale_code'] ?? '')) !== 'EQ_60') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function composeCopySection(string $sectionKey, string $locale, string $accessLevel, string $moduleCode): ?array
    {
        if ($sectionKey !== 'disclaimer_top') {
            return null;
        }

        return [
            'key' => 'disclaimer_top',
            'title' => $this->resolveSectionTitle('disclaimer_top', $locale),
            'access_level' => $accessLevel,
            'module_code' => $moduleCode,
            'blocks' => [[
                'id' => 'eq_disclaimer_top',
                'type' => 'markdown',
                'title' => '',
                'content' => $locale === 'zh-CN'
                    ? '本测评仅供自我探索参考，不构成医疗诊断或治疗建议。'
                    : 'This assessment is for self-reflection only and is not medical diagnosis or treatment advice.',
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $sectionConfig
     * @param  array<string,mixed>  $score
     * @return list<array<string,mixed>>
     */
    private function resolveSectionBlocks(
        array $compiled,
        string $locale,
        array $sectionConfig,
        array $score
    ): array {
        $sectionKey = trim((string) ($sectionConfig['key'] ?? ''));
        if ($sectionKey === '') {
            return [];
        }

        $sectionAccessLevel = strtolower(trim((string) ($sectionConfig['access_level'] ?? 'free')));
        $maxBlocks = max(1, (int) ($sectionConfig['max_blocks'] ?? 1));

        $allBlocks = array_values(array_filter((array) ($compiled['blocks'] ?? []), 'is_array'));
        if ($allBlocks === []) {
            return [];
        }

        $selectionTags = $this->buildSelectionTagsForSection($sectionConfig, $score);
        $selected = [];

        foreach ($this->localeFallbackOrder($locale) as $candidateLocale) {
            $localeCandidates = [];
            foreach ($allBlocks as $block) {
                if (! is_array($block)) {
                    continue;
                }

                $blockSection = trim((string) ($block['section'] ?? $block['section_key'] ?? ''));
                if ($blockSection !== $sectionKey) {
                    continue;
                }

                $blockLocale = $this->packLoader->normalizeLocale((string) ($block['locale'] ?? 'zh-CN'));
                if ($blockLocale !== $candidateLocale) {
                    continue;
                }

                $blockAccessLevel = strtolower(trim((string) ($block['access_level'] ?? 'free')));
                if ($blockAccessLevel !== $sectionAccessLevel) {
                    continue;
                }

                if (! $this->matchBlock($block, $selectionTags)) {
                    continue;
                }

                $localeCandidates[] = $block;
            }

            if ($localeCandidates !== []) {
                $selected = $localeCandidates;
                break;
            }
        }

        if ($selected === []) {
            return [];
        }

        usort($selected, [$this, 'comparePriority']);
        $selected = $this->enforceExclusiveGroup($selected);
        if (count($selected) > $maxBlocks) {
            $selected = array_slice($selected, 0, $maxBlocks);
        }

        $renderCtx = [
            'quality' => is_array($score['quality'] ?? null) ? $score['quality'] : [],
            'scores' => is_array($score['scores'] ?? null) ? $score['scores'] : [],
            'report' => is_array($score['report'] ?? null) ? $score['report'] : [],
            'report_tags' => array_values(array_filter(
                array_map('strval', (array) ($score['report_tags'] ?? [])),
                static fn (string $tag): bool => $tag !== ''
            )),
        ];
        $renderCtx['report_tags_csv'] = implode(', ', (array) ($renderCtx['report_tags'] ?? []));

        $blocks = [];
        foreach ($selected as $row) {
            if (! is_array($row)) {
                continue;
            }

            $blocks[] = [
                'id' => (string) ($row['block_id'] ?? ''),
                'type' => 'markdown',
                'title' => (string) ($row['title'] ?? ''),
                'content' => $this->renderTemplate((string) ($row['body'] ?? ($row['body_md'] ?? '')), $renderCtx),
            ];
        }

        return $blocks;
    }

    /**
     * @param  array<string,mixed>  $sectionConfig
     * @param  array<string,mixed>  $score
     * @return list<string>
     */
    private function buildSelectionTagsForSection(array $sectionConfig, array $score): array
    {
        $sectionKey = trim((string) ($sectionConfig['key'] ?? ''));
        $tags = [];
        if ($sectionKey !== '') {
            $tags[] = 'section:'.$sectionKey;
        }

        $qualityLevel = strtoupper(trim((string) data_get($score, 'quality.level', '')));
        if ($qualityLevel !== '') {
            $tags[] = 'quality_level:'.$qualityLevel;
        }

        foreach ((array) data_get($score, 'quality.flags', []) as $flag) {
            $normalizedFlag = strtoupper(trim((string) $flag));
            if ($normalizedFlag !== '') {
                $tags[] = 'quality_flag:'.$normalizedFlag;
            }
        }

        $sectionDim = $this->sectionDimensionMap($sectionKey);
        if ($sectionDim !== null) {
            $level = strtolower(trim((string) data_get($score, 'scores.'.$sectionDim.'.level', '')));
            if ($level !== '') {
                $tags[] = 'bucket:'.$level;
            }
        } elseif ($sectionKey === 'global_overview') {
            $globalLevel = strtolower(trim((string) data_get($score, 'scores.global.level', '')));
            if ($globalLevel !== '') {
                $tags[] = 'bucket:'.$globalLevel;
            }
        }

        foreach ((array) ($score['report_tags'] ?? []) as $tag) {
            $normalizedTag = trim((string) $tag);
            if ($normalizedTag !== '') {
                $tags[] = $normalizedTag;
            }
        }

        $primaryProfile = trim((string) data_get($score, 'report.primary_profile', ''));
        if ($primaryProfile !== '') {
            $tags[] = $primaryProfile;
        }

        foreach ((array) ($sectionConfig['fallback_tags'] ?? []) as $fallbackTag) {
            $normalizedTag = trim((string) $fallbackTag);
            if ($normalizedTag !== '') {
                $tags[] = $normalizedTag;
            }
        }

        $tags = array_values(array_unique(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            $tags
        ))));

        return $tags;
    }

    /**
     * @param  array<string,mixed>  $block
     * @param  list<string>  $selectionTags
     */
    private function matchBlock(array $block, array $selectionTags): bool
    {
        $selectionSet = array_fill_keys($selectionTags, true);

        $tagsAll = array_values(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            (array) ($block['tags_all'] ?? [])
        )));

        foreach ($tagsAll as $tag) {
            if (! isset($selectionSet[$tag])) {
                return false;
            }
        }

        $tagsAny = array_values(array_filter(array_map(
            static fn ($tag): string => trim((string) $tag),
            (array) ($block['tags_any'] ?? [])
        )));

        if ($tagsAny === []) {
            return true;
        }

        foreach ($tagsAny as $tag) {
            if (isset($selectionSet[$tag])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     * @return list<array<string,mixed>>
     */
    private function enforceExclusiveGroup(array $blocks): array
    {
        $out = [];
        $seen = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $group = trim((string) ($block['exclusive_group'] ?? ''));
            if ($group === '') {
                $out[] = $block;

                continue;
            }

            if (isset($seen[$group])) {
                continue;
            }

            $seen[$group] = true;
            $out[] = $block;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function comparePriority(array $a, array $b): int
    {
        $priorityCompare = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));
        if ($priorityCompare !== 0) {
            return $priorityCompare;
        }

        return strcmp((string) ($a['block_id'] ?? ''), (string) ($b['block_id'] ?? ''));
    }

    /**
     * @return list<string>
     */
    private function localeFallbackOrder(string $locale): array
    {
        $normalized = $this->packLoader->normalizeLocale($locale);

        return $normalized === 'zh-CN'
            ? ['zh-CN', 'en']
            : ['en', 'zh-CN'];
    }

    private function sectionDimensionMap(string $sectionKey): ?string
    {
        return match ($sectionKey) {
            'self_awareness' => 'SA',
            'emotion_regulation' => 'ER',
            'empathy' => 'EM',
            'relationship_management' => 'RM',
            default => null,
        };
    }

    private function normalizeModuleCode(string $moduleCode): string
    {
        $value = strtolower(trim($moduleCode));
        if ($value === '') {
            return ReportAccess::MODULE_EQ_CORE;
        }

        return match ($value) {
            'eq60.meta', 'eq60.summary', 'eq60.quadrants', 'eq60.core', 'eq_core', ReportAccess::MODULE_EQ_CORE => ReportAccess::MODULE_EQ_CORE,
            'eq60.insights', 'eq_cross_insights', ReportAccess::MODULE_EQ_CROSS_INSIGHTS => ReportAccess::MODULE_EQ_CROSS_INSIGHTS,
            'eq60.action_plan', 'eq_growth_plan', ReportAccess::MODULE_EQ_GROWTH_PLAN => ReportAccess::MODULE_EQ_GROWTH_PLAN,
            'eq60.full', 'eq_full', ReportAccess::MODULE_EQ_FULL => ReportAccess::MODULE_EQ_FULL,
            default => $value,
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderTemplate(string $template, array $data): string
    {
        if ($template === '') {
            return '';
        }

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', static function (array $matches) use ($data): string {
            $path = (string) ($matches[1] ?? '');
            if ($path === '') {
                return '';
            }

            $value = data_get($data, $path);
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_scalar($value)) {
                return trim((string) $value);
            }

            return '';
        }, $template);
    }

    /**
     * @param  list<array<string,mixed>>  $sections
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function buildCompatBlocks(array $sections): array
    {
        $free = [];
        $paid = [];

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            $sectionKey = (string) ($section['key'] ?? '');
            $accessLevel = strtolower((string) ($section['access_level'] ?? 'free'));
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];

            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $payload = [
                    'section_key' => $sectionKey,
                    'id' => (string) ($block['id'] ?? ''),
                    'title' => (string) ($block['title'] ?? ''),
                    'content' => (string) ($block['content'] ?? ''),
                ];
                if ($accessLevel === 'paid') {
                    $paid[] = $payload;
                } else {
                    $free[] = $payload;
                }
            }
        }

        return [$free, $paid];
    }

    /**
     * @param  array<int,mixed>  $variants
     * @return list<string>
     */
    private function normalizeVariants(array $variants): array
    {
        $out = [];
        foreach ($variants as $variant) {
            $v = ReportAccess::normalizeVariant((string) $variant);
            $out[$v] = true;
        }

        return array_keys($out);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultSections(): array
    {
        return [
            [
                'key' => 'disclaimer_top',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'quality_notice',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 0,
                'max_blocks' => 2,
            ],
            [
                'key' => 'global_overview',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'self_awareness',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'emotion_regulation',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'empathy',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'relationship_management',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'cross_quadrant_insight',
                'source' => 'blocks',
                'access_level' => 'paid',
                'required_in_variant' => ['full'],
                'module_code' => ReportAccess::MODULE_EQ_CROSS_INSIGHTS,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'action_plan_14d',
                'source' => 'blocks',
                'access_level' => 'paid',
                'required_in_variant' => ['full'],
                'module_code' => ReportAccess::MODULE_EQ_GROWTH_PLAN,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'methodology',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
            [
                'key' => 'disclaimer_bottom',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
                'min_blocks' => 1,
                'max_blocks' => 1,
            ],
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $layoutByKey
     */
    private function resolveSectionTitle(string $sectionKey, string $locale, array $layoutByKey = []): string
    {
        $layoutTitle = '';
        $layoutNode = $layoutByKey[$sectionKey] ?? null;
        if (is_array($layoutNode)) {
            $layoutTitle = trim((string) ($locale === 'zh-CN' ? ($layoutNode['title_zh'] ?? '') : ($layoutNode['title_en'] ?? '')));
            if ($layoutTitle === '') {
                $layoutTitle = trim((string) ($layoutNode['title_zh'] ?? $layoutNode['title_en'] ?? ''));
            }
        }

        if ($layoutTitle !== '') {
            return $layoutTitle;
        }

        $titlesZh = [
            'disclaimer_top' => '重要声明',
            'quality_notice' => '作答质量提示',
            'global_overview' => '综合概览',
            'self_awareness' => '自我情绪认知',
            'emotion_regulation' => '情绪调节与控制',
            'empathy' => '同理心与社会感知',
            'relationship_management' => '社交技能与人际管理',
            'cross_quadrant_insight' => '交叉洞察长文',
            'action_plan_14d' => '14 天游程',
            'methodology' => '方法说明',
            'disclaimer_bottom' => '结尾提示',
        ];
        $titlesEn = [
            'disclaimer_top' => 'Important Notice',
            'quality_notice' => 'Response Quality',
            'global_overview' => 'Overview',
            'self_awareness' => 'Self-Awareness',
            'emotion_regulation' => 'Emotion Regulation',
            'empathy' => 'Social Awareness & Empathy',
            'relationship_management' => 'Relationship Management',
            'cross_quadrant_insight' => 'Cross-Quadrant Insight',
            'action_plan_14d' => '14-Day Plan',
            'methodology' => 'Methodology',
            'disclaimer_bottom' => 'Closing Notes',
        ];

        $map = $locale === 'zh-CN' ? $titlesZh : $titlesEn;

        return (string) ($map[$sectionKey] ?? $sectionKey);
    }
}
