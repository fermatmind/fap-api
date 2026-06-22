<?php

declare(strict_types=1);

namespace App\Services\Career\AiImpactAssets;

final class CareerAiImpactPreviewDetailShellBuilder
{
    /**
     * @var list<string>
     */
    private const COMPONENT_ORDER = [
        'breadcrumb',
        'hero',
        'fermat_decision_card',
        'primary_cta',
        'career_snapshot_primary_locale',
        'career_snapshot_secondary_locale',
        'fit_decision_checklist',
        'riasec_fit_block',
        'personality_fit_block',
        'definition_block',
        'responsibilities_block',
        'work_context_block',
        'market_signal_card',
        'adjacent_career_comparison_table',
        'ai_impact_table',
        'career_risk_cards',
        'contract_project_risk_block',
        'next_steps_block',
        'faq_block',
        'related_next_pages',
        'source_card',
        'review_validity_card',
        'boundary_notice',
        'final_cta',
    ];

    public function __construct(
        private readonly CareerAiImpactAssetPreviewService $previewService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function build(string $slug, string $locale): ?array
    {
        $normalizedSlug = $this->normalizeSlug($slug);
        $normalizedLocale = $this->normalizeLocale($locale);
        if ($normalizedSlug === '' || $normalizedLocale === null) {
            return null;
        }

        $asset = $this->previewService->previewAsset($normalizedSlug, $normalizedLocale);
        if ($asset === null) {
            return null;
        }

        $previewPayload = $this->previewService->publicPayload($asset);
        if (($previewPayload['preview'] ?? false) !== true || ! is_array($previewPayload['ai_impact_asset_v1'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $aiImpact */
        $aiImpact = $previewPayload['ai_impact_asset_v1'];
        $occupation = is_array($aiImpact['occupation'] ?? null) ? $aiImpact['occupation'] : [];
        $titleEn = $this->nonEmptyString($occupation['title_en'] ?? null) ?? $this->titleFromSlug($normalizedSlug);
        $titleZh = $this->nonEmptyString($occupation['title_zh'] ?? null) ?? $titleEn;
        $title = $normalizedLocale === 'zh-CN' ? $titleZh : $titleEn;
        $displayLocale = $normalizedLocale === 'zh-CN' ? 'zh' : 'en';
        $path = '/'.$displayLocale.'/career/jobs/'.$normalizedSlug;
        $summary = $this->nonEmptyString($aiImpact['summary'] ?? null)
            ?? ($displayLocale === 'zh'
                ? $title.' 的 AI 影响预览已通过后端 reader-safe 投影读取。'
                : $title.' AI impact preview is available through the reader-safe backend projection.');

        return [
            'bundle_kind' => 'career_job_detail',
            'bundle_version' => 'ai_impact_preview_detail_shell.v1',
            'identity' => [
                'canonical_slug' => $normalizedSlug,
            ],
            'titles' => [
                'canonical_en' => $titleEn,
                'canonical_zh' => $titleZh,
            ],
            'locale_policy' => [
                'requested_locale' => $normalizedLocale,
                'resolved_locale' => $normalizedLocale,
            ],
            'truth_layer' => [
                'summary' => $summary,
                'source_refs' => ['career_ai_impact_asset_preview'],
            ],
            'trust_manifest' => [
                'status' => 'restricted_preview_shell',
                'authority' => 'career_ai_impact_asset_preview',
            ],
            'warnings' => [[
                'code' => 'detail_bundle_preview_shell',
                'message' => 'Career detail shell is restricted to AI Impact staging preview page readiness.',
            ]],
            'claim_permissions' => $this->claimPermissions(),
            'seo_contract' => $this->seoContract($path, $title),
            'seo_authority_v1' => [
                'seo_surface_v1' => $this->seoContract($path, $title),
                'jsonld' => [],
            ],
            'structured_data' => [],
            'display_surface_v1' => $this->displaySurface($normalizedSlug, $displayLocale, $path, $title, $summary, $aiImpact),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function displaySurface(string $slug, string $locale, string $path, string $title, string $summary, array $aiImpact): array
    {
        return [
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'subject' => [
                'canonical_slug' => $slug,
            ],
            'claim_permissions' => $this->claimPermissions(),
            'component_order' => self::COMPONENT_ORDER,
            'sources' => $this->displaySources(is_array($aiImpact['sources'] ?? null) ? $aiImpact['sources'] : []),
            'support_components' => [
                'boundary_notice' => [
                    $locale => [
                        $locale === 'zh'
                            ? '该页面承载 AI 影响预览模块；AI 分数不是个人职业结果预测。'
                            : 'This page carries the AI Impact preview module; the AI score is not an individual career outcome forecast.',
                    ],
                ],
                'review_validity' => [
                    'last_reviewed' => '2026-06-22',
                ],
            ],
            'page' => [
                $locale => [
                    'path' => $path,
                    'hero' => [
                        'h1' => $title,
                        'quick_answer' => $summary,
                        'primary_cta' => [
                            'label' => $locale === 'zh' ? '测我的职业兴趣是否匹配' : 'Check my career interest fit',
                            'href' => '/'.$locale.'/tests/holland-career-interest-test-riasec',
                        ],
                    ],
                    'sections' => [[
                        'id' => 'ai_impact_preview_anchor',
                        'component' => 'AIImpactTable',
                        'heading' => $locale === 'zh' ? 'AI 影响' : 'AI Impact',
                        'score' => $this->scoreLabel($aiImpact),
                        'body' => $summary,
                        'fermat_view' => 'FermatMind AI Impact preview',
                    ]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claimPermissions(): array
    {
        return [
            'integrity_state' => 'restricted',
            'allow_strong_claim' => false,
            'allow_ai_strategy' => true,
            'allow_salary_comparison' => false,
            'allow_market_signal' => false,
            'allow_local_proxy_wage' => false,
            'blocked_claims' => ['preview_detail_shell_restricted'],
            'warnings' => ['Restricted AI Impact preview shell; no salary, SEO schema, or production-import claims.'],
            'evidence_basis' => [
                'salary' => 'missing',
                'ai_exposure' => 'central_score',
                'market_signal' => 'missing',
                'crosswalk' => 'missing',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seoContract(string $path, string $title): array
    {
        return [
            'canonical_path' => $path,
            'title' => $title,
            'robots' => 'noindex,nofollow',
            'indexable' => false,
            'jsonld_allowed' => false,
            'sitemap_allowed' => false,
            'llms_allowed' => false,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, array<string, string>>
     */
    private function displaySources(array $sources): array
    {
        $displaySources = [];
        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                continue;
            }

            $name = $this->nonEmptyString($source['name'] ?? null);
            $url = $this->nonEmptyString($source['url'] ?? null);
            if ($name === null || $url === null) {
                continue;
            }

            $displaySources['ai_impact_source_'.($index + 1)] = [
                'label' => $name,
                'url' => $url,
                'usage' => 'AI Impact preview source.',
            ];
        }

        return $displaySources;
    }

    private function scoreLabel(array $aiImpact): string
    {
        $score = $aiImpact['ai_exposure_score']['score_1_to_10'] ?? null;
        if (is_int($score) || is_float($score) || (is_string($score) && is_numeric($score))) {
            return (string) $score.'/10';
        }

        return '';
    }

    private function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    private function normalizeLocale(string $locale): ?string
    {
        $normalized = strtolower(trim($locale));

        return match ($normalized) {
            'en' => 'en',
            'zh', 'zh-cn', 'zh_cn' => 'zh-CN',
            default => null,
        };
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function titleFromSlug(string $slug): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => ucfirst($part),
            array_filter(explode('-', $slug), static fn (string $part): bool => $part !== '')
        ));
    }
}
