<?php

declare(strict_types=1);

namespace App\Services\PublicSurface;

use App\Services\Scale\ScaleRegistry;

final class PublicGatewaySurfaceService
{
    /**
     * @var list<string>
     */
    private const HOME_HIGHLIGHTED_SLUGS = [
        'mbti-personality-test-16-personality-types',
        'big-five-personality-test-ocean-model',
        'clinical-depression-anxiety-assessment-professional-edition',
        'depression-screening-test-standard-edition',
        'iq-test-intelligence-quotient-assessment',
        'eq-test-emotional-intelligence-assessment',
    ];

    public function __construct(
        private readonly ScaleRegistry $scaleRegistry,
        private readonly LandingSurfaceContractService $landingSurfaceContractService,
        private readonly AnswerSurfaceContractService $answerSurfaceContractService,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function buildHomeSurface(string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $items = $this->buildScaleDiscoverabilityItems($locale, self::HOME_HIGHLIGHTED_SLUGS, 6);
        $featuredHref = $items[0]['href'] ?? '/'.$segment.'/tests';

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_hub',
            'entry_surface' => 'home_gateway',
            'entry_type' => 'public_home',
            'summary_blocks' => [
                [
                    'key' => 'home_primary',
                    'title' => $locale === 'zh-CN' ? '从科学测评开始' : 'Start with a scientific assessment',
                    'body' => $locale === 'zh-CN'
                        ? '用统一的公开入口连接测评、人格画像、主题内容与职业建议。'
                        : 'Use one public entry surface to connect tests, personality hubs, topic content, and career guidance.',
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_items' => $items,
            'discoverability_keys' => array_column($items, 'key'),
            'continue_reading_keys' => ['tests_index', 'personality_hub', 'topic_hub'],
            'start_test_target' => $featuredHref,
            'content_continue_target' => '/'.$segment.'/tests',
            'cta_bundle' => [
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始测评' : 'Start a test',
                    'href' => $featuredHref,
                    'kind' => 'start_test',
                ],
                [
                    'key' => 'browse_tests',
                    'label' => $locale === 'zh-CN' ? '查看全部测评' : 'Browse all tests',
                    'href' => '/'.$segment.'/tests',
                    'kind' => 'discover',
                ],
                [
                    'key' => 'browse_topics',
                    'label' => $locale === 'zh-CN' ? '查看主题聚合' : 'Browse topic hubs',
                    'href' => '/'.$segment.'/topics',
                    'kind' => 'discover',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_home_landing',
            'surface_family' => 'home',
            'primary_content_ref' => 'home',
            'related_surface_keys' => ['tests_index', 'topics_index', 'personality_index'],
            'fingerprint_seed' => [
                'locale' => $locale,
                'highlighted_keys' => array_column($items, 'key'),
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildTestsIndexSurface(string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $items = $this->buildScaleDiscoverabilityItems($locale, null, 24);
        $topItems = array_slice($items, 0, 6);
        $featuredHref = $topItems[0]['href'] ?? '/'.$segment.'/tests/mbti-personality-test-16-personality-types';

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_hub',
            'entry_surface' => 'tests_index',
            'entry_type' => 'test_directory',
            'summary_blocks' => [
                [
                    'key' => 'tests_index',
                    'title' => $locale === 'zh-CN' ? '测评目录' : 'Assessment directory',
                    'body' => $locale === 'zh-CN'
                        ? '从统一目录进入人格、临床、智力与情绪相关测评。'
                        : 'Browse personality, clinical, intelligence, and emotional assessment entry points from one directory.',
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_items' => $topItems,
            'discoverability_keys' => array_column($items, 'key'),
            'continue_reading_keys' => ['test_detail', 'topics_index', 'personality_index'],
            'start_test_target' => $featuredHref,
            'content_continue_target' => '/'.$segment.'/topics',
            'cta_bundle' => [
                [
                    'key' => 'start_test',
                    'label' => $locale === 'zh-CN' ? '开始推荐测评' : 'Start a featured test',
                    'href' => $featuredHref,
                    'kind' => 'start_test',
                ],
                [
                    'key' => 'personality_hub',
                    'label' => $locale === 'zh-CN' ? '人格画像' : 'Personality hub',
                    'href' => '/'.$segment.'/personality',
                    'kind' => 'discover',
                ],
                [
                    'key' => 'topic_hub',
                    'label' => $locale === 'zh-CN' ? '主题聚合' : 'Topic hubs',
                    'href' => '/'.$segment.'/topics',
                    'kind' => 'discover',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_tests_landing',
            'surface_family' => 'tests',
            'primary_content_ref' => 'tests_index',
            'related_surface_keys' => ['test_detail', 'topics_index', 'personality_index'],
            'fingerprint_seed' => [
                'locale' => $locale,
                'discoverability_keys' => array_column($items, 'key'),
            ],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildHelpIndexSurface(string $locale): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $pages = $this->helpPages($locale);

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_hub',
            'entry_surface' => 'help_hub',
            'entry_type' => 'support_hub',
            'summary_blocks' => [
                [
                    'key' => 'help_home',
                    'title' => $locale === 'zh-CN' ? '帮助中心' : 'Help center',
                    'body' => $locale === 'zh-CN'
                        ? '用正式入口处理订单找回、邮件偏好、退订和常见支持问题。'
                        : 'Use formal public entry points for order lookup, email preferences, unsubscribe, and common support questions.',
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_items' => array_map(fn (array $page): array => [
                'key' => $page['slug'],
                'title' => $page['title'],
                'summary' => $page['summary'],
                'href' => '/'.$segment.'/help/'.$page['slug'],
                'kind' => 'help_detail',
                'badge_label' => $locale === 'zh-CN' ? '帮助' : 'Help',
            ], $pages),
            'discoverability_keys' => array_column($pages, 'slug'),
            'continue_reading_keys' => array_column($pages, 'slug'),
            'content_continue_target' => '/'.$segment.'/help/faq',
            'cta_bundle' => [
                [
                    'key' => 'order_lookup',
                    'label' => $locale === 'zh-CN' ? '订单查询' : 'Order lookup',
                    'href' => '/'.$segment.'/orders/lookup',
                    'kind' => 'formal_entry',
                ],
                [
                    'key' => 'email_preferences',
                    'label' => $locale === 'zh-CN' ? '邮件偏好' : 'Email preferences',
                    'href' => '/'.$segment.'/email/preferences',
                    'kind' => 'formal_entry',
                ],
                [
                    'key' => 'unsubscribe',
                    'label' => $locale === 'zh-CN' ? '退订邮件' : 'Unsubscribe',
                    'href' => '/'.$segment.'/email/unsubscribe',
                    'kind' => 'formal_entry',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_help_landing',
            'surface_family' => 'help',
            'primary_content_ref' => 'help_home',
            'related_surface_keys' => array_column($pages, 'slug'),
            'fingerprint_seed' => [
                'locale' => $locale,
                'help_slugs' => array_column($pages, 'slug'),
            ],
        ]);
    }

    /**
     * @return array{landing_surface_v1:array<string,mixed>,answer_surface_v1:array<string,mixed>}|null
     */
    public function buildHelpDetailSurface(string $locale, string $slug): ?array
    {
        $normalizedSlug = trim(strtolower($slug));
        $segment = $this->frontendLocaleSegment($locale);
        $page = $this->findHelpPage($locale, $normalizedSlug);
        if ($page === null) {
            return null;
        }

        $relatedItems = array_values(array_filter(array_map(function (array $candidate) use ($page, $segment): ?array {
            if ($candidate['slug'] === $page['slug']) {
                return null;
            }

            return [
                'key' => $candidate['slug'],
                'title' => $candidate['title'],
                'summary' => $candidate['summary'],
                'href' => '/'.$segment.'/help/'.$candidate['slug'],
                'kind' => 'help_detail',
                'badge_label' => null,
            ];
        }, $this->helpPages($locale))));

        $landingSurface = $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_indexable_detail',
            'entry_surface' => 'help_detail',
            'entry_type' => 'support_article',
            'summary_blocks' => [
                [
                    'key' => 'help_answer',
                    'title' => $page['title'],
                    'body' => $page['summary'],
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_items' => $relatedItems,
            'discoverability_keys' => array_column($relatedItems, 'key'),
            'continue_reading_keys' => array_column($relatedItems, 'key'),
            'content_continue_target' => '/'.$segment.'/help',
            'cta_bundle' => [
                [
                    'key' => 'back_to_help',
                    'label' => $locale === 'zh-CN' ? '返回帮助中心' : 'Back to help center',
                    'href' => '/'.$segment.'/help',
                    'kind' => 'content_continue',
                ],
                [
                    'key' => 'order_lookup',
                    'label' => $locale === 'zh-CN' ? '订单查询' : 'Order lookup',
                    'href' => '/'.$segment.'/orders/lookup',
                    'kind' => 'formal_entry',
                ],
            ],
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_help_detail',
            'surface_family' => 'help',
            'primary_content_ref' => $page['slug'],
            'related_surface_keys' => array_column($relatedItems, 'key'),
            'fingerprint_seed' => [
                'locale' => $locale,
                'slug' => $page['slug'],
            ],
        ]);

        $answerSurface = $this->answerSurfaceContractService->build([
            'answer_scope' => 'public_indexable_detail',
            'surface_type' => 'help_detail',
            'summary_blocks' => [
                [
                    'key' => 'help_summary',
                    'title' => $page['title'],
                    'body' => $page['summary'],
                    'kind' => 'answer_first',
                ],
            ],
            'faq_blocks' => $page['faq_blocks'],
            'next_step_blocks' => $this->answerSurfaceContractService->buildNextStepBlocksFromCtas($landingSurface['cta_bundle'] ?? []),
            'evidence_refs' => ['help:'.$page['slug']],
            'public_safety_state' => 'public_indexable',
            'indexability_state' => 'indexable',
            'attribution_scope' => 'public_help_answer',
            'landing_surface_ref' => $page['slug'],
            'primary_content_ref' => $page['slug'],
            'related_surface_keys' => array_column($relatedItems, 'key'),
            'fingerprint_seed' => [
                'locale' => $locale,
                'slug' => $page['slug'],
                'faq_count' => count($page['faq_blocks']),
            ],
        ]);

        return [
            'landing_surface_v1' => $landingSurface,
            'answer_surface_v1' => $answerSurface,
        ];
    }

    /**
     * @param  list<string>|null  $preferredSlugs
     * @return list<array<string,string|null>>
     */
    private function buildScaleDiscoverabilityItems(string $locale, ?array $preferredSlugs = null, ?int $limit = null): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $rows = $this->scaleRegistry->listActivePublic(0);
        $visible = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! (bool) ($row['is_public'] ?? true) || ! (bool) ($row['is_active'] ?? true)) {
                continue;
            }

            $slug = trim((string) ($row['primary_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $visible[$slug] = $row;
        }

        $orderedRows = [];
        if (is_array($preferredSlugs) && $preferredSlugs !== []) {
            foreach ($preferredSlugs as $slug) {
                if (isset($visible[$slug])) {
                    $orderedRows[] = $visible[$slug];
                }
            }
        } else {
            foreach ($visible as $row) {
                $orderedRows[] = $row;
            }
        }

        $items = [];
        foreach ($orderedRows as $row) {
            $slug = trim((string) ($row['primary_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $seo = $this->resolveScaleSeoByLocale($row, $locale);
            $items[] = [
                'key' => $slug,
                'title' => $seo['title'] ?? strtoupper(trim((string) ($row['code'] ?? $slug))),
                'summary' => $this->resolveScaleSummaryByLocale($row, $locale) ?? $seo['description'],
                'href' => '/'.$segment.'/tests/'.$slug,
                'kind' => 'test_detail',
                'badge_label' => strtoupper(trim((string) ($row['code'] ?? ''))),
            ];

            if ($limit !== null && count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{title:?string,description:?string}
     */
    private function resolveScaleSeoByLocale(array $row, string $locale): array
    {
        $seoI18n = $this->toArray($row['seo_i18n_json'] ?? null);
        $lang = $this->localeToLanguage($locale);
        $defaultLocale = (string) ($row['default_locale'] ?? 'en');
        $defaultLang = $this->localeToLanguage($defaultLocale);

        $byLang = $this->toArray($seoI18n[$lang] ?? null);
        if ($byLang === [] && $defaultLang !== '') {
            $byLang = $this->toArray($seoI18n[$defaultLang] ?? null);
        }
        if ($byLang === []) {
            $byLang = $this->toArray($seoI18n['en'] ?? null);
        }

        $legacy = $this->toArray($row['seo_schema_json'] ?? null);

        return [
            'title' => $this->trimOrNull($byLang['title'] ?? null)
                ?? $this->trimOrNull($legacy['title'] ?? null)
                ?? $this->trimOrNull($legacy['name'] ?? null),
            'description' => $this->trimOrNull($byLang['description'] ?? null)
                ?? $this->trimOrNull($legacy['description'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function resolveScaleSummaryByLocale(array $row, string $locale): ?string
    {
        $lang = $this->localeToLanguage($locale);
        $report = $this->toArray($row['report_summary_i18n_json'] ?? null);
        $content = $this->toArray($row['content_i18n_json'] ?? null);

        $reportByLang = $this->toArray($report[$lang] ?? null);
        $contentByLang = $this->toArray($content[$lang] ?? null);

        return $this->trimOrNull($reportByLang['summary'] ?? null)
            ?? $this->trimOrNull($contentByLang['landing_copy'] ?? null)
            ?? $this->trimOrNull($contentByLang['summary'] ?? null);
    }

    /**
     * @return list<array{
     *   slug:string,
     *   title:string,
     *   summary:string,
     *   faq_blocks:list<array<string,string>>
     * }>
     */
    private function helpPages(string $locale): array
    {
        $isZh = $locale === 'zh-CN';

        return [
            [
                'slug' => 'faq',
                'title' => $isZh ? '常见问题' : 'Frequently Asked Questions',
                'summary' => $isZh
                    ? '先用正式入口处理报告找回、邮件偏好、退订和支付支持，再查看常见问题。'
                    : 'Start with the formal entry paths for report recovery, email preferences, unsubscribe, and payment support, then review the common questions.',
                'faq_blocks' => [
                    [
                        'key' => 'faq_report',
                        'question' => $isZh ? '如何找回报告？' : 'How do I recover my report?',
                        'answer' => $isZh
                            ? '先用订单号和购买邮箱进入订单查询，再确认交付状态或重发邮件。'
                            : 'Start with Order lookup using your order number and purchase email, then review delivery status or resend the email.',
                    ],
                    [
                        'key' => 'faq_unsubscribe',
                        'question' => $isZh ? '如何退订邮件？' : 'How do I unsubscribe from emails?',
                        'answer' => $isZh
                            ? '使用邮件偏好或邮件内专属退订链接，不要把退订和报告找回混为一体。'
                            : 'Use Manage email preferences or the dedicated unsubscribe link in any email instead of treating unsubscribe as report recovery.',
                    ],
                ],
            ],
            [
                'slug' => 'about',
                'title' => $isZh ? '关于费马测试' : 'About FermatMind',
                'summary' => $isZh
                    ? '了解费马测试的测评定位、内容边界和公开可读入口。'
                    : 'Learn what FermatMind assessments cover, where the content boundaries are, and which public entry points are available.',
                'faq_blocks' => [],
            ],
            [
                'slug' => 'team',
                'title' => $isZh ? '团队信息' : 'Team information',
                'summary' => $isZh
                    ? '查看团队与协作边界，了解谁在建设这些公开内容与测评入口。'
                    : 'Review the team and collaboration boundaries behind the public content and assessment entry surfaces.',
                'faq_blocks' => [],
            ],
            [
                'slug' => 'used-and-mentioned',
                'title' => $isZh ? '使用与提及' : 'Used and mentioned',
                'summary' => $isZh
                    ? '查看产品被使用、被提及与公开引用的场景。'
                    : 'Review where the product is used, mentioned, and publicly referenced.',
                'faq_blocks' => [],
            ],
            [
                'slug' => 'for-business-and-research',
                'title' => $isZh ? '企业与研究合作' : 'Business and research',
                'summary' => $isZh
                    ? '说明企业和研究使用场景与授权边界。'
                    : 'Understand business and research use cases together with the authorization boundaries.',
                'faq_blocks' => [],
            ],
            [
                'slug' => 'contact',
                'title' => $isZh ? '联系支持' : 'Contact support',
                'summary' => $isZh
                    ? '先完成正式入口，再联系支持，避免重复工单与无效往返。'
                    : 'Finish the formal recovery or email-control paths first, then contact support to avoid duplicate tickets.',
                'faq_blocks' => [
                    [
                        'key' => 'contact_when',
                        'question' => $isZh ? '什么时候应该联系支持？' : 'When should I contact support?',
                        'answer' => $isZh
                            ? '当订单查询、邮件偏好和退订路径都不能解决你的问题时，再联系支持。'
                            : 'Contact support after Order lookup, email preferences, and unsubscribe paths still cannot resolve the issue.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array{slug:string,title:string,summary:string,faq_blocks:list<array<string,string>>}|null
     */
    private function findHelpPage(string $locale, string $slug): ?array
    {
        foreach ($this->helpPages($locale) as $page) {
            if ($page['slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    private function localeToLanguage(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return 'en';
        }

        $parts = explode('-', str_replace('_', '-', $locale));

        return strtolower((string) ($parts[0] ?? 'en'));
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function trimOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
