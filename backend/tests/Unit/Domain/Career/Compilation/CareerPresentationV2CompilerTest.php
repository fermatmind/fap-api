<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationV2Compiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerPresentationV2Contract;
use Tests\TestCase;

final class CareerPresentationV2CompilerTest extends TestCase
{
    public function test_current_package_publishes_bilingual_v2_for_every_locale_page(): void
    {
        ini_set('memory_limit', '2048M');
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $localePages = 0;
        $enhanced = 0;
        $legacy = 0;
        foreach ($package['rows'] as $slug => $row) {
            foreach (['en', 'zh-CN'] as $locale) {
                $projection = app(CareerCurrentAuthorityPackage::class)->publicProjection($row, $locale);
                $presentation = $projection['presentation_v2'] ?? null;
                self::assertIsArray($presentation);
                CareerPresentationV2Contract::assert($presentation, $row['component_order_json']);
                self::assertSame($locale, $presentation['locale']);
                self::assertSame($row['component_order_json'], array_merge(...array_column($presentation['groups'], 'component_ids')));
                $fitGroup = collect($presentation['groups'])->firstWhere('id', 'fit');
                foreach ($presentation['groups'] as $group) {
                    if ($slug === 'accountants-and-auditors') {
                        self::assertSame('enhanced', $group['content_state']);
                        self::assertArrayNotHasKey('pending_enrichment', $group);
                        $enhanced++;
                    } else {
                        self::assertSame('legacy', $group['content_state']);
                        self::assertSame('display_placeholder', $group['pending_enrichment']);
                        $legacy++;
                    }
                }
                if ($slug === 'accountants-and-auditors') {
                    self::assertSame(
                        ['faq_block', 'source_card'],
                        collect($presentation['groups'])->firstWhere('id', 'sources')['component_ids'] ?? null,
                    );
                    foreach (['contract_project_risk_block', 'next_steps_block', 'related_next_pages', 'review_validity_card', 'boundary_notice', 'final_cta'] as $omittedComponent) {
                        self::assertNotContains($omittedComponent, $row['component_order_json']);
                        self::assertArrayNotHasKey($omittedComponent, $projection['page']['content']);
                    }
                    self::assertSame(
                        $locale === 'zh-CN' ? 'AI影响程度' : 'AI impact level',
                        $presentation['hero']['ai_exposure']['label'] ?? null,
                    );
                    self::assertSame(
                        ['us_median_pay', 'us_growth', 'employment', 'annual_openings', 'china_median_pay'],
                        array_column($presentation['hero']['stats'], 'key'),
                    );
                    self::assertSame('¥78,500', $presentation['hero']['stats'][4]['value'] ?? null);
                    self::assertSame(
                        $locale === 'zh-CN' ? '职业适配指南' : 'Career fit guide',
                        $fitGroup['label'] ?? null,
                        $slug.' '.$locale,
                    );
                    self::assertSame('career.work_risk.v1', $projection['page']['content']['career_risk_cards']['schema_version'] ?? null);
                    self::assertCount(6, $projection['page']['content']['career_risk_cards']['risks'] ?? []);
                    self::assertSame('career.career_progression.v1', $projection['page']['content']['career_path_block']['schema_version'] ?? null);
                    self::assertCount(3, $projection['page']['content']['career_path_block']['tracks'] ?? []);
                    self::assertSame('career.outlook_transitions.v1', $projection['page']['content']['market_signal_card']['schema_version'] ?? null);
                    self::assertCount(8, $projection['page']['content']['market_signal_card']['transitions'] ?? []);
                    self::assertSame(
                        $locale === 'zh-CN'
                            ? [
                                'risk' => '工作压力、风险与职业边界',
                                'path' => '入行、证书与职业发展',
                                'market-signals' => '职业前景与相关职业转向',
                            ]
                            : [
                                'risk' => 'Work pressure, risks and boundaries',
                                'path' => 'Entry, credentials and career development',
                                'market-signals' => 'Career outlook and related transitions',
                            ],
                        collect($presentation['groups'])
                            ->whereIn('id', ['risk', 'path', 'market-signals'])
                            ->pluck('label', 'id')
                            ->all(),
                    );
                    $directions = $projection['page']['content']['personality_fit_block']['directions'] ?? null;
                    self::assertIsArray($directions);
                    self::assertCount(6, $directions);
                    self::assertSame([
                        'bookkeeping-accounting-and-auditing-clerks',
                        'financial-examiners',
                        'tax-preparers',
                        'financial-analysts',
                        'fraud-examiners-investigators-and-analysts',
                        'financial-managers',
                    ], array_column(array_column($directions, 'target'), 'slug'));
                    foreach ($directions as $direction) {
                        self::assertSame(
                            '/'.($locale === 'zh-CN' ? 'zh' : 'en').'/career/jobs/'.$direction['target']['slug'],
                            $direction['target']['href'],
                        );
                        self::assertNotSame('', $direction['target']['title']);
                    }
                    $fit = $projection['page']['content']['personality_fit_block'] ?? null;
                    self::assertIsArray($fit);
                    self::assertSame(
                        $locale === 'zh-CN'
                            ? [
                                '霍兰德职业兴趣模型（RIASEC）',
                                '五因素人格模型（大五人格）',
                                '迈尔斯-布里格斯类型指标（MBTI）',
                                '九型人格（Enneagram）',
                                '智力商数（IQ）与数理推理',
                                '情绪智力（EI，常称“情商”）',
                            ]
                            : [
                                'Holland RIASEC interest model',
                                'Five-Factor Model (Big Five)',
                                'Myers-Briggs Type Indicator (MBTI)',
                                'Enneagram personality system',
                                'Intelligence quotient (IQ) and numerical reasoning',
                                'Emotional intelligence (EI)',
                            ],
                        array_column($fit['assessments'], 'label'),
                    );
                    self::assertCount(8, $fit['source_links']);
                    $riasec = $projection['page']['content']['riasec_fit_block']['fit_interest'] ?? null;
                    self::assertIsString($riasec);
                    self::assertFalse(str_ends_with($riasec, $locale === 'zh-CN' ? '如' : 'For'));
                }
                $localePages++;
            }
        }

        self::assertSame(2092, $localePages);
        self::assertGreaterThan(0, $enhanced);
        self::assertGreaterThan(0, $legacy);
    }

    public function test_projection_is_content_preserving_and_uses_language_neutral_contract_keys(): void
    {
        $authorityPackage = app(CareerCurrentAuthorityPackage::class);
        $package = $authorityPackage->load(base_path());
        $row = $package['rows']['actors'];
        $projection = $authorityPackage->publicProjection($row, 'en');
        $page = $projection['page']['content'];
        $pageHash = CareerCurrentAuthorityPackage::hashValue($page);
        $orderHash = CareerCurrentAuthorityPackage::hashValue($row['component_order_json']);

        $presentation = app(CareerPresentationV2Compiler::class)->project(
            'actors',
            'en',
            $page,
            $row['component_order_json'],
            $row['metadata_json']['presentation_v1']['zh'],
        );

        self::assertSame($pageHash, CareerCurrentAuthorityPackage::hashValue($page));
        self::assertSame($orderHash, CareerCurrentAuthorityPackage::hashValue($row['component_order_json']));
        $keys = array_keys($presentation);
        sort($keys, SORT_STRING);
        self::assertSame(
            ['contract_version', 'design_authority', 'groups', 'hero', 'locale', 'template_id'],
            $keys,
        );
        self::assertSame('Career overview', $presentation['groups'][0]['label']);
        self::assertDoesNotMatchRegularExpression(
            '/会计|审计/u',
            CareerCurrentAuthorityPackage::encodeCanonical(array_keys($presentation['hero'])),
        );
    }
}
