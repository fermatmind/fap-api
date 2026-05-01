<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Content\Sds20PackLoader;

final class Sds20ReportComposer
{
    public function __construct(
        private readonly Sds20PackLoader $packLoader,
    ) {}

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{ok:bool,report?:array<string,mixed>,error?:string,message?:string,status?:int}
     */
    public function composeVariant(Attempt $attempt, Result $result, string $variant, array $ctx = []): array
    {
        $variant = ReportAccess::normalizeVariant($variant);
        $version = trim((string) ($attempt->dir_version ?? Sds20PackLoader::PACK_VERSION));
        if ($version === '') {
            $version = Sds20PackLoader::PACK_VERSION;
        }

        $locale = $this->packLoader->normalizeLocale((string) ($attempt->locale ?? 'zh-CN'));
        $landing = $this->packLoader->loadLanding($locale, $version);
        $layout = $this->packLoader->loadReportLayout($version);
        $blocks = $this->packLoader->loadReportBlocks($locale, $version);

        $score = $this->extractScoreResult($result);
        if ($score === null) {
            return [
                'ok' => false,
                'error' => 'REPORT_SCORE_RESULT_MISSING',
                'message' => 'SDS_20 score result missing.',
                'status' => 500,
            ];
        }

        $quality = is_array($score['quality'] ?? null) ? $score['quality'] : [];
        $scores = is_array($score['scores'] ?? null) ? $score['scores'] : [];
        $reportTags = is_array($score['report_tags'] ?? null) ? $score['report_tags'] : [];
        $crisisAlert = (bool) ($quality['crisis_alert'] ?? false);
        $modulesAllowed = ReportAccess::normalizeModules(
            is_array($ctx['modules_allowed'] ?? null) ? $ctx['modules_allowed'] : []
        );

        $sectionsConfig = is_array($layout['sections'] ?? null) ? $layout['sections'] : $this->defaultSections();
        $sections = [];

        foreach ($sectionsConfig as $sectionConfig) {
            if (! is_array($sectionConfig)) {
                continue;
            }

            $key = trim((string) ($sectionConfig['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $accessLevel = strtolower(trim((string) ($sectionConfig['access_level'] ?? 'free')));
            $moduleCode = strtolower(trim((string) ($sectionConfig['module_code'] ?? ReportAccess::MODULE_SDS_CORE)));
            if ($moduleCode === '') {
                $moduleCode = ReportAccess::MODULE_SDS_CORE;
            }
            $paidModuleAllowed = $accessLevel === 'paid'
                && in_array($variant, [ReportAccess::VARIANT_PARTIAL, ReportAccess::VARIANT_FULL], true)
                && ! $crisisAlert
                && in_array($moduleCode, $modulesAllowed, true);

            $requiredInVariant = array_values(array_filter(
                array_map(static fn ($v): string => strtolower(trim((string) $v)), (array) ($sectionConfig['required_in_variant'] ?? ['free', 'full'])),
                static fn (string $v): bool => $v !== ''
            ));
            if ($requiredInVariant !== [] && ! in_array($variant, $requiredInVariant, true) && ! $paidModuleAllowed) {
                continue;
            }

            if ($accessLevel === 'paid' && ! $paidModuleAllowed) {
                continue;
            }

            $source = strtolower(trim((string) ($sectionConfig['source'] ?? 'blocks')));

            if ($source === 'copy') {
                $copySection = $this->composeCopySection($key, $locale, $landing, $accessLevel, $moduleCode, $crisisAlert);
                if (is_array($copySection)) {
                    $sections[] = $copySection;
                }

                continue;
            }

            $sectionBlocks = [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                if ((string) ($block['section_key'] ?? '') !== $key) {
                    continue;
                }

                $blockAccess = strtolower(trim((string) ($block['access_level'] ?? 'free')));
                if ($accessLevel === 'paid' && $blockAccess !== 'paid') {
                    continue;
                }
                if ($accessLevel !== 'paid' && $blockAccess === 'paid') {
                    continue;
                }

                $sectionBlocks[] = [
                    'id' => (string) ($block['block_id'] ?? ''),
                    'type' => 'markdown',
                    'title' => (string) ($block['title'] ?? ''),
                    'content' => $this->renderTemplate((string) ($block['body_md'] ?? ''), [
                        'quality' => $quality,
                        'scores' => $scores,
                        'report_tags' => $reportTags,
                    ]),
                ];
            }

            if ($sectionBlocks === []) {
                continue;
            }

            $sections[] = [
                'key' => $key,
                'title' => $this->resolveSectionTitle($key, $locale),
                'access_level' => $accessLevel,
                'module_code' => $moduleCode,
                'blocks' => $sectionBlocks,
            ];
        }

        [$compatFree, $compatPaid] = $this->buildCompatBlocks($sections);

        return [
            'ok' => true,
            'report' => [
                'schema_version' => 'sds_20.report.v1',
                'scale_code' => 'SDS_20',
                'variant' => $variant,
                'locale' => $locale,
                'sections' => $sections,
                'compat' => [
                    'free_blocks' => $compatFree,
                    'paid_blocks' => $compatPaid,
                ],
                'quality' => $quality,
                'scores' => $scores,
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
            if (! is_array($candidate)) {
                continue;
            }
            if (strtoupper((string) ($candidate['scale_code'] ?? '')) !== 'SDS_20') {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $landing
     * @return array<string,mixed>|null
     */
    private function composeCopySection(
        string $key,
        string $locale,
        array $landing,
        string $accessLevel,
        string $moduleCode,
        bool $crisisAlert
    ): ?array {
        if ($key === 'disclaimer_top') {
            return [
                'key' => 'disclaimer_top',
                'title' => $this->resolveSectionTitle('disclaimer_top', $locale),
                'access_level' => $accessLevel,
                'module_code' => $moduleCode,
                'blocks' => [[
                    'id' => 'sds_disclaimer_text',
                    'type' => 'markdown',
                    'title' => '',
                    'content' => (string) data_get($landing, 'disclaimer.text', ''),
                ]],
            ];
        }

        if ($key === 'crisis_banner') {
            if (! $crisisAlert) {
                return null;
            }

            return [
                'key' => 'crisis_banner',
                'title' => $this->resolveSectionTitle('crisis_banner', $locale),
                'access_level' => $accessLevel,
                'module_code' => $moduleCode,
                'blocks' => [[
                    'id' => 'sds_crisis_banner',
                    'type' => 'markdown',
                    'title' => '',
                    'content' => (string) data_get($landing, 'crisis_hotline', ''),
                ]],
            ];
        }

        return null;
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
                'module_code' => ReportAccess::MODULE_SDS_CORE,
            ],
            [
                'key' => 'crisis_banner',
                'source' => 'copy',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_SDS_CORE,
            ],
            [
                'key' => 'result_summary_free',
                'source' => 'blocks',
                'access_level' => 'free',
                'required_in_variant' => ['free', 'full'],
                'module_code' => ReportAccess::MODULE_SDS_CORE,
            ],
            [
                'key' => 'paid_deep_dive',
                'source' => 'blocks',
                'access_level' => 'paid',
                'required_in_variant' => ['full'],
                'module_code' => ReportAccess::MODULE_SDS_FULL,
            ],
        ];
    }

    private function resolveSectionTitle(string $sectionKey, string $locale): string
    {
        $isZh = $locale === 'zh-CN';

        return match ($sectionKey) {
            'disclaimer_top' => $isZh ? '重要说明' : 'Important Disclaimer',
            'crisis_banner' => $isZh ? '危机提示' : 'Crisis Alert',
            'result_summary_free' => $isZh ? '测评概览' : 'Result Summary',
            'paid_deep_dive' => $isZh ? '深度解析' : 'Deep Dive',
            default => $sectionKey,
        };
    }
}
