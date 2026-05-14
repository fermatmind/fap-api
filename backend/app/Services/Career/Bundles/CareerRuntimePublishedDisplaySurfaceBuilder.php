<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\DTO\Career\CareerJobDetailBundle;

final class CareerRuntimePublishedDisplaySurfaceBuilder
{
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

    /**
     * @param  array<string, mixed>  $projectionItem
     * @return array<string, mixed>
     */
    public function build(CareerJobDetailBundle $bundle, string $locale, array $projectionItem): array
    {
        $publicLocale = $this->publicLocale($locale);
        $isZh = $publicLocale === 'zh-CN';
        $slug = strtolower((string) ($bundle->identity['canonical_slug'] ?? ''));
        $titleEn = trim((string) ($bundle->titles['canonical_en'] ?? $slug));
        $titleZh = trim((string) ($bundle->titles['canonical_zh'] ?? ''));
        $title = $isZh
            ? ($titleZh !== '' ? $titleZh : $titleEn.' 职业路径')
            : ($titleEn !== '' ? $titleEn : str($slug)->replace('-', ' ')->title()->toString());
        $pathPrefix = $isZh ? '/zh' : '/en';
        $path = $pathPrefix.'/career/jobs/'.$slug;
        $testPath = $pathPrefix.'/tests/holland-career-interest-test-riasec';

        return [
            'surface_version' => 'display.surface.v1',
            'asset_version' => 'v4.2',
            'template_version' => 'v4.2',
            'asset_type' => 'career_job_public_display',
            'asset_role' => 'formal_pilot_master',
            'status' => 'ready_for_pilot',
            'subject' => [
                'occupation_uuid' => (string) ($bundle->identity['occupation_uuid'] ?? ''),
                'canonical_slug' => $slug,
                'soc_code' => null,
                'onet_code' => null,
            ],
            'available_locales' => [$publicLocale],
            'claim_permissions' => [
                'integrity_state' => 'restricted',
                'allow_strong_claim' => false,
                'allow_ai_strategy' => false,
                'allow_salary_comparison' => false,
                'allow_market_signal' => false,
                'allow_local_proxy_wage' => false,
                'blocked_claims' => ['runtime_published_shell_no_strong_claims'],
                'warnings' => ['Runtime projection marks this locale published; visible copy remains a restricted navigation shell.'],
                'evidence_basis' => [
                    'runtime_projection' => 'release_gate_pass',
                    'surface_authority' => 'runtime_published_shell',
                ],
            ],
            'page' => [
                'locale' => $publicLocale,
                'content' => $this->pageContent($slug, $title, $path, $testPath, $isZh),
            ],
            'component_order' => self::COMPONENT_ORDER,
            'sources' => [
                [
                    'key' => 'runtime_publish_projection',
                    'label' => 'Career runtime publish projection',
                    'usage' => 'Runtime publication authority for this locale.',
                ],
            ],
            'structured_data_from_visible_content' => [],
            'implementation_contract' => [
                'authority' => 'runtime_publish_projection',
                'projection_state' => $projectionItem['runtime_publish_state'] ?? $projectionItem['state'] ?? null,
                'release_gate_pass' => (bool) ($projectionItem['release_gate_pass'] ?? false),
                'surface_policy' => 'restricted_runtime_published_navigation_shell',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pageContent(string $slug, string $title, string $path, string $testPath, bool $isZh): array
    {
        return [
            'path' => $path,
            'breadcrumb' => [],
            'hero' => [
                'h1' => $title,
                'title' => $title,
                'quick_answer' => $isZh
                    ? $title.' 已进入公开职业路径。先用兴趣结构判断它是否值得继续比较。'
                    : $title.' is available as a public career path. Start with interest fit before comparing options.',
            ],
            'primary_cta' => [
                'label' => $isZh ? '测量我的职业兴趣' : 'Measure my career interests',
                'href' => $testPath,
                'test_slug' => 'holland-career-interest-test-riasec',
                'subject_key' => $slug,
                'subject_kind' => 'career_job',
                'entry_surface' => 'career_job_detail',
                'target_action' => 'start_riasec_test',
                'source_page_type' => 'career_job_detail',
            ],
            'fermat_decision_card' => [
                'title' => $isZh ? '先判断匹配，再比较职业' : 'Check fit before comparing careers',
                'summary' => $isZh
                    ? '这页只提供公开路径入口和兴趣测试导航，不提供未验证的薪资、录用或发展承诺。'
                    : 'This page provides public navigation and interest-fit entry points without unverified salary, hiring, or outcome promises.',
                'caveat' => $isZh ? '最终判断需要结合你的兴趣、能力和现实约束。' : 'Final decisions need your interests, abilities, and real constraints.',
            ],
            'definition_block' => $isZh
                ? $title.' 是一个职业方向页面，用于连接职业探索和兴趣测评。'
                : $title.' is a career direction page connecting career exploration with interest assessment.',
            'fit_decision_checklist' => [
                'checks' => [
                    [
                        'title' => $isZh ? '兴趣结构' : 'Interest structure',
                        'question' => $isZh ? '你的 RIASEC 兴趣是否支持继续研究这条路径？' : 'Does your RIASEC profile support exploring this path?',
                        'note' => $isZh ? '先测兴趣，再看具体职业证据。' : 'Assess interests before reading detailed career evidence.',
                    ],
                ],
            ],
            'riasec_fit_block' => [
                'body' => [$isZh ? 'RIASEC 结果用于职业探索，不是录用或收入保证。' : 'RIASEC results guide exploration; they do not guarantee hiring or income.'],
            ],
            'next_steps_block' => [
                'steps' => [
                    [
                        'title' => $isZh ? '开始兴趣测试' : 'Start the interest test',
                        'items' => [$isZh ? '保存结果后再比较相邻职业。' : 'Save your result before comparing adjacent careers.'],
                    ],
                ],
            ],
            'faq_block' => [
                'items' => [
                    [
                        'question' => $isZh ? '这页是否代表强推荐？' : 'Is this page a strong recommendation?',
                        'answer' => $isZh ? '不是。它是职业探索入口，强推荐需要更多个人数据。' : 'No. It is an exploration entry point; strong recommendations need more personal data.',
                    ],
                ],
            ],
            'boundary_notice' => [
                'body' => $isZh ? '本页面不提供医学、法律、财务或录用保证。' : 'This page does not provide medical, legal, financial, or hiring guarantees.',
            ],
            'final_cta' => [
                'label' => $isZh ? '开始 RIASEC 测试' : 'Start the RIASEC test',
                'href' => $testPath,
            ],
        ];
    }

    private function publicLocale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
    }
}
