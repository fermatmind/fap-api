<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerDisplayAssetComponentContract;
use App\Domain\Career\Display\CareerPresentationV2Contract;

final class CareerPresentationV2Compiler
{
    public const VERSION = 'career.presentation_v2.compiler.v1';

    private const ENHANCED_SLUGS = ['accountants-and-auditors'];

    /** @var list<string> */
    private const ACCOUNTANTS_OMITTED_COMPONENTS = [
        'contract_project_risk_block',
        'next_steps_block',
        'related_next_pages',
        'review_validity_card',
        'final_cta',
    ];

    /** @var array<string,array{en:string,zh-CN:string}> */
    private const ENHANCED_GROUP_LABELS = [
        'fit' => ['en' => 'Career fit guide', 'zh-CN' => '职业适配指南'],
        'risk' => ['en' => 'Work pressure, risks and boundaries', 'zh-CN' => '工作压力、风险与职业边界'],
        'path' => ['en' => 'Entry, credentials and career development', 'zh-CN' => '入行、证书与职业发展'],
        'market-signals' => ['en' => 'Career outlook and related transitions', 'zh-CN' => '职业前景与相关职业转向'],
    ];

    /** @var list<array{id:string,components:list<string>,en:string,zh-CN:string}> */
    private const GROUPS = [
        ['id' => 'overview', 'components' => ['breadcrumb', 'hero', 'primary_cta'], 'en' => 'Career overview', 'zh-CN' => '职业概览'],
        ['id' => 'quick-decision', 'components' => ['fermat_decision_card', 'fit_decision_checklist'], 'en' => 'Quick decision', 'zh-CN' => '快速判断'],
        ['id' => 'profile', 'components' => ['definition_block', 'career_ai_description_block', 'responsibilities_block', 'work_context_block', 'career_quick_answers_block', 'onet_structured_fields_block'], 'en' => 'Career profile', 'zh-CN' => '职业画像'],
        ['id' => 'direction-comparison', 'components' => ['adjacent_career_comparison_table'], 'en' => 'Career direction comparison', 'zh-CN' => '职业方向比较'],
        ['id' => 'ai-impact', 'components' => ['ai_impact_table'], 'en' => 'AI impact', 'zh-CN' => 'AI 影响'],
        ['id' => 'china-salary', 'components' => ['career_snapshot_primary_locale'], 'en' => 'Chinese mainland salary reference', 'zh-CN' => '中国大陆薪资参考'],
        ['id' => 'us-salary', 'components' => ['career_snapshot_secondary_locale'], 'en' => 'United States salary reference', 'zh-CN' => '美国薪资参考'],
        ['id' => 'fit', 'components' => ['riasec_fit_block', 'personality_fit_block'], 'en' => 'Fit map', 'zh-CN' => '适配地图'],
        ['id' => 'risk', 'components' => ['career_risk_cards'], 'en' => 'Risks and change', 'zh-CN' => '风险与变化'],
        ['id' => 'path', 'components' => ['career_path_block', 'contract_project_risk_block', 'next_steps_block'], 'en' => 'Development path', 'zh-CN' => '发展路径'],
        ['id' => 'market-signals', 'components' => ['market_signal_card'], 'en' => 'Market signals', 'zh-CN' => '市场信号'],
        ['id' => 'sources', 'components' => ['faq_block', 'related_next_pages', 'source_card', 'review_validity_card', 'boundary_notice', 'final_cta'], 'en' => 'Questions and sources', 'zh-CN' => '常见问题与资料来源'],
    ];

    private CareerCurrentAuthorityPackage $package;

    public function __construct(?CareerCurrentAuthorityPackage $package = null)
    {
        $this->package = $package ?? new CareerCurrentAuthorityPackage;
    }

    /** @return array{assets_bytes:string,manifest_template:array<string,mixed>,validated_summary:array<string,mixed>,receipt:array<string,mixed>,package_diff:array<string,mixed>} */
    public function compile(string $backendRoot): array
    {
        $authority = $this->package->load($backendRoot);
        $careerCount = count($authority['rows']);
        $assetsBytes = '';
        $presentationChanges = 0;
        $componentOrderChanges = 0;
        $componentContentChanges = 0;
        $enhancedLocalePages = 0;
        $legacyLocalePages = 0;
        foreach (array_keys($authority['rows']) as $slug) {
            $row = $authority['rows'][$slug];
            unset($authority['rows'][$slug]);
            $beforePagesHash = CareerCurrentAuthorityPackage::hashValue($row['page_payload_json']);
            $beforeOrder = $row['component_order_json'];
            if ($slug === 'accountants-and-auditors') {
                $componentContentChanges += $this->restoreAccountantsBoundaryNotice($row);
                $componentContentChanges += $this->removeAccountantsOmittedComponents($row);
            }
            $componentOrder = array_values(array_filter(
                CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS,
                static fn (string $componentId): bool => in_array($componentId, $beforeOrder, true)
                    || ($slug === 'accountants-and-auditors' && $componentId === 'boundary_notice'),
            ));
            if ($slug === 'accountants-and-auditors') {
                $componentOrder = array_values(array_filter(
                    $componentOrder,
                    static fn (string $componentId): bool => ! in_array(
                        $componentId,
                        self::ACCOUNTANTS_OMITTED_COMPONENTS,
                        true,
                    ),
                ));
            }
            if ($beforeOrder !== $componentOrder) {
                $componentOrderChanges++;
                $row['component_order_json'] = $componentOrder;
            }
            $pages = is_array($row['page_payload_json']['page'] ?? null)
                ? $row['page_payload_json']['page']
                : $row['page_payload_json'];
            $presentationV1 = data_get($row, 'metadata_json.presentation_v1.zh');
            $presentations = [];
            foreach (['en' => 'en', 'zh' => 'zh-CN'] as $pageLocale => $locale) {
                $page = $pages[$pageLocale] ?? null;
                if (! is_array($page)) {
                    throw new CareerTenBlockCompileFailure('PRESENTATION_V2_LOCALE_PAGE_INVALID');
                }
                $presentations[$pageLocale] = $this->project(
                    $slug,
                    $locale,
                    $page,
                    $componentOrder,
                    is_array($presentationV1) ? $presentationV1 : null,
                );
                if ($slug === 'accountants-and-auditors') {
                    $enhancedLocalePages++;
                } else {
                    $legacyLocalePages++;
                }
            }
            $before = $row['metadata_json']['presentation_v2'] ?? null;
            if (! is_array($before) || ! hash_equals(
                CareerCurrentAuthorityPackage::hashValue($before),
                CareerCurrentAuthorityPackage::hashValue($presentations),
            )) {
                $presentationChanges++;
            }
            $row['metadata_json']['presentation_v2'] = $presentations;
            if ($slug !== 'accountants-and-auditors'
                && ! hash_equals($beforePagesHash, CareerCurrentAuthorityPackage::hashValue($row['page_payload_json']))) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V2_CONTENT_DRIFT');
            }
            $assetsBytes .= CareerCurrentAuthorityPackage::encodeCanonical($row)."\n";
            unset($row);
        }

        return [
            'assets_bytes' => $assetsBytes,
            'manifest_template' => $authority['manifest'],
            'validated_summary' => $authority['summary'],
            'receipt' => [
                'contract_version' => 'career.presentation_v2.compile_receipt.v1',
                'compiler_version' => self::VERSION,
                'career_count' => $careerCount,
                'locale_page_count' => $careerCount * 2,
                'enhanced_locale_page_count' => $enhancedLocalePages,
                'legacy_locale_page_count' => $legacyLocalePages,
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
                'generated_at' => null,
            ],
            'package_diff' => [
                'contract_version' => 'career.presentation_v2.package_diff.v1',
                'presentation_changes' => $presentationChanges,
                'component_order_changes' => $componentOrderChanges,
                'existing_component_content_changes' => $componentContentChanges,
                'canonical_route_inventory_changed' => false,
                'discoverability_surface_changed' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $page
     * @param  list<string>  $componentOrder
     * @param  array<string,mixed>|null  $presentationV1
     * @return array<string,mixed>
     */
    public function project(string $slug, string $locale, array $page, array $componentOrder, ?array $presentationV1): array
    {
        if (! in_array($locale, ['en', 'zh-CN'], true)
            || ! CareerDisplayAssetComponentContract::supports($componentOrder)) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V2_INPUT_INVALID');
        }
        $expectedOrder = array_values(array_filter(
            CareerDisplayAssetComponentContract::SUPPORTED_COMPONENTS,
            static fn (string $componentId): bool => in_array($componentId, $componentOrder, true),
        ));
        if ($componentOrder !== $expectedOrder) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V2_COMPONENT_ORDER_INVALID');
        }

        $hero = $page['hero'] ?? null;
        if (! is_array($hero)) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V2_HERO_INVALID');
        }
        $title = $this->string($hero['h1'] ?? null) ?? $this->string($hero['title'] ?? null);
        if ($title === null) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V2_HERO_INVALID');
        }
        $cta = $page['primary_cta'] ?? null;
        $presentation = [
            'contract_version' => CareerPresentationV2Contract::CONTRACT_VERSION,
            'design_authority' => ['id' => CareerPresentationV2Contract::DESIGN_AUTHORITY_ID],
            'template_id' => CareerPresentationV2Contract::TEMPLATE_ID,
            'locale' => $locale,
            'hero' => [
                'title' => $title,
                'lead' => $this->string($hero['quick_answer'] ?? null),
                'badges' => $this->badges($presentationV1, $locale),
                'stats' => $this->stats($presentationV1, $locale, $slug, $page),
                'ai_exposure' => $this->aiExposure($presentationV1, $locale, $slug),
                'cta' => $this->cta($cta, $locale),
            ],
            'groups' => $this->groups($slug, $locale, $componentOrder),
        ];
        CareerPresentationV2Contract::assert($presentation, $componentOrder);

        return $presentation;
    }

    /** @param list<string> $componentOrder @return list<array<string,mixed>> */
    private function groups(string $slug, string $locale, array $componentOrder): array
    {
        $enhanced = in_array($slug, self::ENHANCED_SLUGS, true);
        $groups = [];
        foreach (self::GROUPS as $definition) {
            $components = array_values(array_filter(
                $definition['components'],
                static fn (string $componentId): bool => in_array($componentId, $componentOrder, true),
            ));
            if ($components === []) {
                continue;
            }
            $group = [
                'id' => $definition['id'],
                'label' => $enhanced && isset(self::ENHANCED_GROUP_LABELS[$definition['id']])
                    ? self::ENHANCED_GROUP_LABELS[$definition['id']][$locale]
                    : $definition[$locale],
                'component_ids' => $components,
                'content_state' => $enhanced ? 'enhanced' : 'legacy',
            ];
            if (! $enhanced) {
                $group['pending_enrichment'] = CareerPresentationV2Contract::PENDING_ENRICHMENT;
            }
            $groups[] = $group;
        }

        return $groups;
    }

    /** @param array<string,mixed> $row */
    private function removeAccountantsOmittedComponents(array &$row): int
    {
        $pages = &$row['page_payload_json'];
        if (is_array($pages['page'] ?? null)) {
            $pages = &$pages['page'];
        }
        if (! is_array($pages)) {
            return 0;
        }

        $changes = 0;
        foreach (['en', 'zh'] as $locale) {
            if (! is_array($pages[$locale] ?? null)) {
                continue;
            }
            foreach (self::ACCOUNTANTS_OMITTED_COMPONENTS as $componentId) {
                if (array_key_exists($componentId, $pages[$locale])) {
                    unset($pages[$locale][$componentId]);
                    $changes++;
                }
            }
        }

        return $changes;
    }

    /** @param array<string,mixed> $row */
    private function restoreAccountantsBoundaryNotice(array &$row): int
    {
        $pages = &$row['page_payload_json'];
        if (is_array($pages['page'] ?? null)) {
            $pages = &$pages['page'];
        }
        if (! is_array($pages)) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V2_ACCOUNTANTS_BOUNDARY_SOURCE_MISSING');
        }

        $changes = 0;
        foreach (['en', 'zh'] as $locale) {
            $page = $pages[$locale] ?? null;
            $caveat = is_array($page) ? ($page['fermat_decision_card']['caveat'] ?? null) : null;
            $boundaries = [];
            foreach ($pages as $candidatePage) {
                $boundary = is_array($candidatePage)
                    ? ($candidatePage['ai_impact_table']['explanation'][$locale]['boundary'] ?? null)
                    : null;
                if (is_string($boundary) && trim($boundary) !== '') {
                    $boundaries[trim($boundary)] = true;
                }
            }
            $boundary = count($boundaries) === 1 ? array_key_first($boundaries) : null;
            if ($boundary === null && is_array($page)) {
                $fallback = $page['fit_decision_checklist']['boundary']
                    ?? $page['personality_fit_block']['boundary']
                    ?? null;
                $boundary = is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
            }
            if (! is_string($caveat) || trim($caveat) === '' || ! is_string($boundary)) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V2_ACCOUNTANTS_BOUNDARY_SOURCE_MISSING');
            }
            $notices = array_values(array_unique([trim($caveat), trim($boundary)]));
            if (($pages[$locale]['boundary_notice'] ?? null) !== $notices) {
                $pages[$locale]['boundary_notice'] = $notices;
                $changes++;
            }
        }

        return $changes;
    }

    /** @param array<string,mixed>|null $presentationV1 @return list<array{key:string,text:string}> */
    private function badges(?array $presentationV1, string $locale): array
    {
        $badges = data_get($presentationV1, 'hero.badges');
        if (! is_array($badges)) {
            return [];
        }
        $result = [];
        foreach ($badges as $badge) {
            $key = is_array($badge) ? $this->string($badge['key'] ?? null) : null;
            $text = is_array($badge) ? $this->string($badge['text'] ?? null) : null;
            if ($key === null || $text === null || ($locale === 'en' && $this->containsCjk($text))) {
                continue;
            }
            $result[] = ['key' => $key, 'text' => $text];
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>|null  $presentationV1
     * @param  array<string,mixed>  $page
     * @return list<array{key:string,label:string,value:string,source_label:?string}>
     */
    private function stats(?array $presentationV1, string $locale, string $slug, array $page): array
    {
        $labels = [
            'en' => [
                'us_median_pay' => 'U.S. median annual wage',
                'us_growth' => 'U.S. employment growth',
                'employment' => 'U.S. employment',
                'annual_openings' => 'U.S. annual openings',
                'ai_exposure' => 'AI task exposure',
            ],
            'zh-CN' => [
                'us_median_pay' => '美国年薪中位数',
                'us_growth' => '美国就业增长',
                'employment' => '美国在岗人数',
                'annual_openings' => '美国年均职位空缺',
                'ai_exposure' => 'AI 任务暴露',
            ],
        ];
        $stats = data_get($presentationV1, 'hero.stats');
        if (! is_array($stats)) {
            return [];
        }
        $result = [];
        foreach ($stats as $stat) {
            $key = is_array($stat) ? $this->string($stat['key'] ?? null) : null;
            $value = is_array($stat) ? $this->string($stat['value'] ?? null) : null;
            if ($slug === 'accountants-and-auditors' && $key === 'ai_exposure') {
                continue;
            }
            if ($key === null || $value === null || ! isset($labels[$locale][$key])) {
                continue;
            }
            $sourceLabel = $key === 'ai_exposure'
                ? ($locale === 'en' ? 'FermatMind task-level rubric' : 'FermatMind 任务级 rubric')
                : ($locale === 'en' ? 'U.S. Bureau of Labor Statistics' : '美国劳工统计局');
            $result[] = [
                'key' => $key,
                'label' => $labels[$locale][$key],
                'value' => $value,
                'source_label' => $sourceLabel,
            ];
        }

        if ($slug === 'accountants-and-auditors') {
            $chinaWage = $this->accountantsChinaWageStat($page, $locale);
            if ($chinaWage !== null) {
                $result[] = $chinaWage;
            }
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $page
     * @return array{key:string,label:string,value:string,source_label:string}|null
     */
    private function accountantsChinaWageStat(array $page, string $locale): ?array
    {
        $rows = data_get($page, 'career_snapshot_primary_locale.salary.china_salary_table');
        $national = is_array($rows) ? ($rows[0] ?? null) : null;
        $description = is_array($national) ? $this->string($national['月薪参考'] ?? null) : null;
        if ($description === null
            || (! str_contains($description, '7.85 万元') && ! str_contains($description, '¥78,500'))) {
            return null;
        }

        return [
            'key' => 'china_median_pay',
            'label' => $locale === 'en'
                ? 'China related-field median annual wage'
                : '中国相关职业年工资中位数',
            'value' => '¥78,500',
            'source_label' => $locale === 'en'
                ? 'MOHRSS 2024 enterprise wage survey'
                : '人社部 2024 企业薪酬调查',
        ];
    }

    /** @param array<string,mixed>|null $presentationV1 @return array<string,mixed>|null */
    private function aiExposure(?array $presentationV1, string $locale, string $slug): ?array
    {
        $source = data_get($presentationV1, 'hero.ai_exposure');
        $value = is_array($source) ? ($source['value'] ?? null) : null;
        if (! is_int($value) || $value < 0 || $value > 10) {
            return null;
        }

        return [
            'value' => $value,
            'scale' => 10,
            'display_value' => $value.'/10',
            'label' => $slug === 'accountants-and-auditors'
                ? ($locale === 'en' ? 'AI impact level' : 'AI影响程度')
                : ($locale === 'en' ? 'AI task exposure' : 'AI 任务暴露'),
            'note' => $locale === 'en'
                ? 'Task exposure describes the range of activities AI may affect; it is not an automation rate or job-loss probability.'
                : '任务暴露表示 AI 可能影响的工作活动范围，不等于实际自动化率或岗位消失概率。',
            'source_label' => $locale === 'en' ? 'FermatMind task-level rubric' : 'FermatMind 任务级 rubric',
        ];
    }

    /** @return array{label:string,href:string}|null */
    private function cta(mixed $cta, string $locale): ?array
    {
        if (! is_array($cta)) {
            return null;
        }
        $label = $this->localizedString($cta['label'] ?? null, $locale, ' / ');
        $href = $this->localizedString($cta['href'] ?? null, $locale, ' | ');
        if ($label === null || $href === null) {
            return null;
        }

        return ['label' => $label, 'href' => $href];
    }

    private function localizedString(mixed $value, string $locale, string $separator): ?string
    {
        $value = $this->string($value);
        if ($value === null) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', explode($separator, $value)), static fn (string $part): bool => $part !== ''));
        if (count($parts) < 2) {
            return $value;
        }

        return $locale === 'en' ? $parts[0] : $parts[count($parts) - 1];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function containsCjk(string $value): bool
    {
        return preg_match('/[\x{3400}-\x{9fff}\x{f900}-\x{faff}]/u', $value) === 1;
    }
}
