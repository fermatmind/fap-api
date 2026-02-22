<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\ClinicalComboPackLoader;
use App\Services\Report\ClinicalCombo\ClinicalComboBlockSelector;
use App\Services\Template\TemplateContext;
use App\Services\Template\TemplateEngine;

final class ClinicalCombo68ReportComposer
{
    public function __construct(
        private readonly ClinicalComboPackLoader $packLoader,
        private readonly TemplateEngine $templateEngine,
        private readonly ClinicalComboBlockSelector $blockSelector,
    ) {
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        $version = trim((string) ($attempt->dir_version ?? ClinicalComboPackLoader::PACK_VERSION));
        if ($version === '') {
            $version = ClinicalComboPackLoader::PACK_VERSION;
        }

        $locale = $this->packLoader->normalizeLocale((string) ($attempt->locale ?? 'zh-CN'));
        $region = strtoupper(trim((string) ($attempt->region ?? '')));

        $landing = $this->packLoader->loadLanding($version);
        $policy = $this->packLoader->loadPolicy($version);
        $layout = $this->packLoader->loadLayout($version);
        $allBlocks = $this->packLoader->loadBlocks($locale, $version);

        $score = $this->extractScoreResult($result);
        if (!is_array($score)) {
            return [
                'ok' => false,
                'error' => 'REPORT_SCORE_RESULT_MISSING',
                'message' => 'CLINICAL_COMBO_68 score result missing.',
                'status' => 500,
            ];
        }

        $quality = is_array($score['quality'] ?? null) ? $score['quality'] : [];
        $scores = is_array($score['scores'] ?? null) ? $score['scores'] : [];
        $facts = is_array($score['facts'] ?? null) ? $score['facts'] : [];
        $reportTags = is_array($score['report_tags'] ?? null) ? $score['report_tags'] : [];

        $crisisAlert = (bool) ($quality['crisis_alert'] ?? false);
        $crisisReasons = is_array($quality['crisis_reasons'] ?? null) ? $quality['crisis_reasons'] : [];
        $crisisResources = $this->packLoader->loadCrisisResources($locale, $region, $version);

        $templateContext = $this->buildTemplateContext($score, $variant);
        $conditionContext = [
            'quality' => $quality,
            'scores' => $scores,
            'facts' => $facts,
            'report_tags' => $reportTags,
        ];

        $layoutSections = is_array($layout['sections'] ?? null) ? $layout['sections'] : [];
        if ($layoutSections === []) {
            $layoutSections = $this->defaultLayoutSections();
        }

        $sections = [];
        foreach ($layoutSections as $sectionConfig) {
            if (!is_array($sectionConfig)) {
                continue;
            }

            $sectionKey = trim((string) ($sectionConfig['key'] ?? ''));
            if ($sectionKey === '') {
                continue;
            }

            $requiredVariants = $this->normalizeVariants((array) ($sectionConfig['required_in_variant'] ?? ['free', 'full']));
            if ($requiredVariants !== [] && !in_array($variant, $requiredVariants, true)) {
                continue;
            }

            $source = strtolower(trim((string) ($sectionConfig['source'] ?? 'blocks')));
            $accessLevel = strtolower(trim((string) ($sectionConfig['access_level'] ?? 'free')));
            $moduleCode = trim((string) ($sectionConfig['module_code'] ?? ReportAccess::MODULE_CLINICAL_CORE));
            $minBlocks = max(0, (int) ($sectionConfig['min_blocks'] ?? 0));
            $maxBlocks = max($minBlocks, (int) ($sectionConfig['max_blocks'] ?? $minBlocks));

            if ($accessLevel === 'paid' && ($variant !== ReportAccess::VARIANT_FULL || $crisisAlert)) {
                continue;
            }

            if ($source === 'copy') {
                $copy = $this->composeCopySection(
                    sectionKey: $sectionKey,
                    locale: $locale,
                    landing: $landing,
                    accessLevel: $accessLevel,
                    moduleCode: $moduleCode,
                    crisisAlert: $crisisAlert,
                    crisisReasons: $crisisReasons,
                    crisisResources: is_array($crisisResources['resources'] ?? null) ? $crisisResources['resources'] : []
                );
                if (is_array($copy)) {
                    $sections[] = $copy;
                }
                continue;
            }

            $allowedLevels = $accessLevel === 'paid' ? ['paid'] : ['free'];
            $selectedRows = $this->blockSelector->select(
                allBlocks: $allBlocks,
                sectionKey: $sectionKey,
                locale: $locale,
                allowedAccessLevels: $allowedLevels,
                minBlocks: $minBlocks,
                maxBlocks: $maxBlocks,
                context: $conditionContext,
                policy: $policy,
            );

            $blocks = [];
            foreach ($selectedRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $body = trim((string) ($row['body_md'] ?? $row['body'] ?? ''));
                $rendered = $body !== '' ? $this->templateEngine->renderString($body, $templateContext, 'text') : '';

                $blocks[] = [
                    'id' => (string) ($row['block_id'] ?? $row['id'] ?? ''),
                    'type' => 'markdown',
                    'title' => (string) ($row['title'] ?? ''),
                    'content' => $rendered,
                ];
            }

            if ($blocks === [] && $minBlocks > 0) {
                $blocks[] = [
                    'id' => 'fallback_'.$sectionKey,
                    'type' => 'markdown',
                    'title' => $this->resolveSectionTitle($sectionKey, $locale),
                    'content' => $locale === 'zh-CN'
                        ? '本段内容暂不可用，请稍后刷新或联系客服。'
                        : 'This section is temporarily unavailable. Please refresh later.',
                ];
            }

            if ($blocks === []) {
                continue;
            }

            if (count($blocks) > $maxBlocks) {
                $blocks = array_slice($blocks, 0, $maxBlocks);
            }

            $sections[] = [
                'key' => $sectionKey,
                'title' => $this->resolveSectionTitle($sectionKey, $locale),
                'access_level' => $accessLevel,
                'module_code' => $moduleCode,
                'blocks' => $blocks,
            ];
        }

        [$compatFree, $compatPaid] = $this->buildCompatBlocks($sections);

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'clinical_combo_68.report.v2',
                'scale_code' => 'CLINICAL_COMBO_68',
                'variant' => $variant,
                'locale' => $locale,
                'sections' => $sections,
                'compat' => [
                    'free_blocks' => $compatFree,
                    'paid_blocks' => $compatPaid,
                ],
                'quality' => $quality,
                'scores' => $scores,
                'facts' => $facts,
                'report_tags' => $reportTags,
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
            if (!is_array($candidate)) {
                continue;
            }
            if (strtoupper((string) ($candidate['scale_code'] ?? '')) !== 'CLINICAL_COMBO_68') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param array<string,mixed> $landing
     * @param list<string> $crisisReasons
     * @param list<array<string,mixed>> $crisisResources
     * @return array<string,mixed>|null
     */
    private function composeCopySection(
        string $sectionKey,
        string $locale,
        array $landing,
        string $accessLevel,
        string $moduleCode,
        bool $crisisAlert,
        array $crisisReasons,
        array $crisisResources
    ): ?array {
        if ($sectionKey === 'disclaimer_top') {
            return [
                'key' => 'disclaimer_top',
                'title' => $this->resolveSectionTitle('disclaimer_top', $locale),
                'access_level' => $accessLevel,
                'module_code' => $moduleCode,
                'blocks' => [[
                    'id' => 'disclaimer_text',
                    'type' => 'markdown',
                    'title' => '',
                    'content' => $this->resolveLandingText($landing, 'disclaimer', $locale),
                ]],
            ];
        }

        if ($sectionKey === 'crisis_banner') {
            if (!$crisisAlert) {
                return null;
            }

            return [
                'key' => 'crisis_banner',
                'title' => $this->resolveSectionTitle('crisis_banner', $locale),
                'access_level' => $accessLevel,
                'module_code' => $moduleCode,
                'blocks' => [[
                    'id' => 'crisis_banner_text',
                    'type' => 'markdown',
                    'title' => '',
                    'content' => $this->resolveLandingText($landing, 'crisis_banner', $locale),
                ]],
                'resources' => $crisisResources,
                'reasons' => $crisisReasons,
            ];
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $sections
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function buildCompatBlocks(array $sections): array
    {
        $free = [];
        $paid = [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $sectionKey = (string) ($section['key'] ?? '');
            $accessLevel = strtolower((string) ($section['access_level'] ?? 'free'));
            $blocks = is_array($section['blocks'] ?? null) ? $section['blocks'] : [];

            foreach ($blocks as $block) {
                if (!is_array($block)) {
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
     * @param array<string,mixed> $landing
     */
    private function resolveLandingText(array $landing, string $key, string $locale): string
    {
        $node = is_array($landing[$key] ?? null) ? $landing[$key] : [];
        $text = trim((string) ($node[$locale] ?? ''));
        if ($text === '') {
            $text = trim((string) ($node['zh-CN'] ?? ''));
        }

        return $text;
    }

    private function resolveSectionTitle(string $sectionKey, string $locale): string
    {
        $isZh = $locale === 'zh-CN';

        return match ($sectionKey) {
            'disclaimer_top' => $isZh ? '重要说明' : 'Important Disclaimer',
            'crisis_banner' => $isZh ? '危机提示' : 'Crisis Alert',
            'quick_overview' => $isZh ? '测评总览' : 'Quick Overview',
            'symptoms_depression' => $isZh ? '抑郁相关结果' : 'Depression Results',
            'symptoms_anxiety' => $isZh ? '焦虑相关结果' : 'Anxiety Results',
            'symptoms_ocd' => $isZh ? '强迫相关结果' : 'OCD Results',
            'stress_resilience' => $isZh ? '压力与韧性' : 'Stress and Resilience',
            'perfectionism_overview' => $isZh ? '完美主义概览' : 'Perfectionism Overview',
            'paid_deep_dive' => $isZh ? '深度解析' : 'Deep Dive',
            'action_plan' => $isZh ? '行动方案' : 'Action Plan',
            'resources_footer' => $isZh ? '求助与资源' : 'Resources',
            'scoring_notes' => $isZh ? '计分说明' : 'Scoring Notes',
            default => $sectionKey,
        };
    }

    /**
     * @param array<string,mixed> $score
     */
    private function buildTemplateContext(array $score, string $variant): TemplateContext
    {
        $scores = is_array($score['scores'] ?? null) ? $score['scores'] : [];
        $quality = is_array($score['quality'] ?? null) ? $score['quality'] : [];
        $facts = is_array($score['facts'] ?? null) ? $score['facts'] : [];

        return TemplateContext::fromArray([
            'variant' => $variant,
            'quality' => $quality,
            'scores' => $scores,
            'facts' => $facts,
            'report_tags' => is_array($score['report_tags'] ?? null) ? $score['report_tags'] : [],

            'depression_level' => (string) data_get($scores, 'depression.level', 'normal'),
            'depression_flags' => (array) data_get($scores, 'depression.flags', []),
            'anxiety_level' => (string) data_get($scores, 'anxiety.level', 'normal'),
            'ocd_level' => (string) data_get($scores, 'ocd.level', 'subclinical'),
            'stress_level' => (string) data_get($scores, 'stress.level', 'low'),
            'resilience_level' => (string) data_get($scores, 'resilience.level', 'average'),
            'perfectionism_level' => (string) data_get($scores, 'perfectionism.level', 'balanced'),
            'function_impairment_level' => (string) ($facts['function_impairment_level'] ?? 'none'),
            'function_impairment_raw' => (string) (int) ($facts['function_impairment_raw'] ?? 0),
            'dominant_trait_tag' => $this->resolveDominantTraitTag(is_array($score['report_tags'] ?? null) ? $score['report_tags'] : []),
            'PE_parental' => (string) (int) data_get($scores, 'perfectionism.sub_scores.PE_parental', 0),
            'ORG_order' => (string) (int) data_get($scores, 'perfectionism.sub_scores.ORG_order', 0),
            'PS_standards' => (string) (int) data_get($scores, 'perfectionism.sub_scores.PS_standards', 0),
            'CM_mistakes' => (string) (int) data_get($scores, 'perfectionism.sub_scores.CM_mistakes', 0),
            'DA_doubts' => (string) (int) data_get($scores, 'perfectionism.sub_scores.DA_doubts', 0),
        ]);
    }

    /**
     * @param list<string> $tags
     */
    private function resolveDominantTraitTag(array $tags): string
    {
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            if (str_starts_with($tag, 'trait:')) {
                return $tag;
            }
        }

        return '';
    }

    /**
     * @param list<mixed> $variants
     * @return list<string>
     */
    private function normalizeVariants(array $variants): array
    {
        $out = [];
        foreach ($variants as $variant) {
            $value = ReportAccess::normalizeVariant((string) $variant);
            $out[$value] = true;
        }

        return array_keys($out);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function defaultLayoutSections(): array
    {
        return [
            ['key' => 'disclaimer_top', 'source' => 'copy', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 1],
            ['key' => 'crisis_banner', 'source' => 'copy', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 0, 'max_blocks' => 1],
            ['key' => 'quick_overview', 'source' => 'blocks', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 2],
            ['key' => 'symptoms_depression', 'source' => 'blocks', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 2],
            ['key' => 'symptoms_anxiety', 'source' => 'blocks', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 2],
            ['key' => 'symptoms_ocd', 'source' => 'blocks', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 2],
            ['key' => 'stress_resilience', 'source' => 'blocks', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 3],
            ['key' => 'perfectionism_overview', 'source' => 'blocks', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 2],
            ['key' => 'paid_deep_dive', 'source' => 'blocks', 'access_level' => 'paid', 'module_code' => 'clinical_full', 'required_in_variant' => ['full'], 'min_blocks' => 0, 'max_blocks' => 8],
            ['key' => 'action_plan', 'source' => 'blocks', 'access_level' => 'paid', 'module_code' => 'clinical_action_plan', 'required_in_variant' => ['full'], 'min_blocks' => 0, 'max_blocks' => 6],
            ['key' => 'resources_footer', 'source' => 'blocks', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 3],
            ['key' => 'scoring_notes', 'source' => 'blocks', 'access_level' => 'free', 'module_code' => 'clinical_core', 'required_in_variant' => ['free', 'full'], 'min_blocks' => 1, 'max_blocks' => 2],
        ];
    }
}
