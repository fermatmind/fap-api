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
    ) {
    }

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
        $landingCompiled = $this->packLoader->readCompiledJson('landing.compiled.json', $version);
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
            ? (array) data_get($reportCompiled, 'layout.sections')
            : [];
        if ($layoutSections === []) {
            $layoutSections = $this->defaultSections();
        }

        $landing = is_array($landingCompiled['landing'] ?? null) ? $landingCompiled['landing'] : [];
        $landingLocale = $this->resolveLandingLocale($landing, $locale);
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
            $moduleCode = strtolower(trim((string) ($sectionConfig['module_code'] ?? ReportAccess::MODULE_EQ_CORE)));

            if ($accessLevel === 'paid') {
                if ($variant !== ReportAccess::VARIANT_FULL) {
                    continue;
                }
                if (! in_array($moduleCode, $modulesAllowed, true)) {
                    continue;
                }
            }

            if ($source === 'copy') {
                $copySection = $this->composeCopySection($sectionKey, $locale, $landingLocale, $accessLevel, $moduleCode);
                if (is_array($copySection)) {
                    $sections[] = $copySection;
                }
                continue;
            }

            $sectionBlocks = $this->resolveSectionBlocks($reportCompiled, $locale, $sectionKey, $accessLevel, $score);
            if ($sectionBlocks === []) {
                continue;
            }

            $sections[] = [
                'key' => $sectionKey,
                'title' => $this->resolveSectionTitle($sectionKey, $locale),
                'access_level' => $accessLevel,
                'module_code' => $moduleCode,
                'blocks' => $sectionBlocks,
            ];
        }

        [$compatFree, $compatPaid] = $this->buildCompatBlocks($sections);

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'eq_60.report.v1',
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
     * @param  array<string,mixed>  $landing
     * @return array<string,mixed>
     */
    private function resolveLandingLocale(array $landing, string $locale): array
    {
        if (isset($landing[$locale]) && is_array($landing[$locale])) {
            return $landing[$locale];
        }
        if (isset($landing['zh-CN']) && is_array($landing['zh-CN'])) {
            return $landing['zh-CN'];
        }
        if (isset($landing['en']) && is_array($landing['en'])) {
            return $landing['en'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $landingLocale
     * @return array<string,mixed>|null
     */
    private function composeCopySection(
        string $sectionKey,
        string $locale,
        array $landingLocale,
        string $accessLevel,
        string $moduleCode
    ): ?array {
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
                'content' => (string) data_get($landingLocale, 'disclaimer.text', ''),
            ]],
        ];
    }

    /**
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $score
     * @return list<array<string,mixed>>
     */
    private function resolveSectionBlocks(
        array $compiled,
        string $locale,
        string $sectionKey,
        string $accessLevel,
        array $score
    ): array {
        $blockScope = $accessLevel === 'paid' ? 'paid' : 'free';
        $localeBlocks = data_get($compiled, 'blocks.'.$blockScope.'.'.$locale);
        if (! is_array($localeBlocks)) {
            $localeBlocks = (array) data_get($compiled, 'blocks.'.$blockScope.'.zh-CN', []);
        }

        $renderCtx = [
            'scores' => is_array($score['scores'] ?? null) ? $score['scores'] : [],
            'quality' => is_array($score['quality'] ?? null) ? $score['quality'] : [],
            'report_tags' => array_values(array_filter(
                array_map('strval', (array) ($score['report_tags'] ?? [])),
                static fn (string $tag): bool => $tag !== ''
            )),
        ];
        $renderCtx['report_tags_csv'] = implode(', ', $renderCtx['report_tags']);

        $blocks = [];
        foreach ($localeBlocks as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) ($row['section_key'] ?? '') !== $sectionKey) {
                continue;
            }

            $blocks[] = [
                'id' => (string) ($row['block_id'] ?? ''),
                'type' => 'markdown',
                'title' => (string) ($row['title'] ?? ''),
                'content' => $this->renderTemplate((string) ($row['body_md'] ?? ''), $renderCtx),
            ];
        }

        return $blocks;
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
                'source' => 'copy',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
            ],
            [
                'key' => 'eq_summary_free',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
            ],
            [
                'key' => 'eq_dimensions_free',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
            ],
            [
                'key' => 'eq_paywall_teaser',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_EQ_CORE,
            ],
            [
                'key' => 'eq_cross_insights',
                'source' => 'blocks',
                'access_level' => 'paid',
                'required_in_variant' => ['full'],
                'module_code' => ReportAccess::MODULE_EQ_CROSS_INSIGHTS,
            ],
            [
                'key' => 'eq_growth_plan',
                'source' => 'blocks',
                'access_level' => 'paid',
                'required_in_variant' => ['full'],
                'module_code' => ReportAccess::MODULE_EQ_GROWTH_PLAN,
            ],
        ];
    }

    private function resolveSectionTitle(string $sectionKey, string $locale): string
    {
        $titlesZh = [
            'disclaimer_top' => '免责声明',
            'eq_summary_free' => 'EQ 概览',
            'eq_dimensions_free' => '四维简评',
            'eq_paywall_teaser' => '深度报告预览',
            'eq_cross_insights' => '交叉洞察',
            'eq_growth_plan' => '成长计划',
        ];
        $titlesEn = [
            'disclaimer_top' => 'Disclaimer',
            'eq_summary_free' => 'EQ Summary',
            'eq_dimensions_free' => 'Quadrant Snapshot',
            'eq_paywall_teaser' => 'Full Report Preview',
            'eq_cross_insights' => 'Cross-Quadrant Insights',
            'eq_growth_plan' => 'Growth Plan',
        ];

        $map = $locale === 'zh-CN' ? $titlesZh : $titlesEn;

        return (string) ($map[$sectionKey] ?? $sectionKey);
    }
}
