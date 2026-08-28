<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3CompatibilityProjector;
use PHPUnit\Framework\TestCase;

final class CareerContentV3CompatibilityProjectorTest extends TestCase
{
    public function test_it_keeps_the_existing_surface_until_the_complete_fact_set_exists(): void
    {
        $surface = $this->surface();
        $content = $this->content();
        array_pop($content['fact_register']['facts']);

        self::assertSame($surface, (new CareerContentV3CompatibilityProjector)->project($surface, $content));
    }

    public function test_it_projects_one_complete_fact_set_across_all_compatibility_surfaces(): void
    {
        $projected = (new CareerContentV3CompatibilityProjector)->project($this->surface(), $this->content());

        foreach (['presentation_v1', 'presentation_v2'] as $presentation) {
            $stats = collect($projected[$presentation]['hero']['stats'])->keyBy('key');
            self::assertSame('$83,680', $stats['us_median_pay']['value']);
            self::assertSame('1,595,200 人', $stats['employment']['value']);
            self::assertSame('115,300 个', $stats['annual_openings']['value']);
            self::assertSame('bls-us-accountants-openings-2025-2035', $stats['annual_openings']['fact_ref']);
            self::assertSame('BLS OOH｜美国｜2025—2035 年｜年均岗位空缺', $stats['annual_openings']['source_label']);
        }

        $salary = data_get($projected, 'page.content.career_snapshot_secondary_locale');
        self::assertSame('$83,680', $salary['median']);
        self::assertSame('2025—2035 年美国就业前景', $salary['outlook_heading']);
        self::assertCount(8, $salary['bls_table']);
        self::assertCount(4, $salary['industry_rows']);
        self::assertStringContainsString('编辑换算', $salary['direct_answer']);

        self::assertSame(
            '美国 SOC 13-2011，2025—2035 年官方职业预测',
            data_get($projected, 'page.content.ai_impact_table.evidence_rows.0.研究对象'),
        );
        self::assertSame(
            '5%；约 115,300 个/年',
            data_get($projected, 'page.content.market_signal_card.outlook_evidence.0.value'),
        );
        self::assertSame(
            '美国就业预计增长 5%，年均约 115,300 个岗位空缺。',
            data_get($projected, 'page.content.faq_block.items.0.answer'),
        );
    }

    /** @return array<string,mixed> */
    private function surface(): array
    {
        $stats = [
            ['key' => 'us_median_pay', 'value' => '$81,680', 'source_label' => 'BLS'],
            ['key' => 'us_growth', 'value' => '5%', 'source_label' => 'BLS'],
            ['key' => 'employment', 'value' => '1,579,800 人', 'source_label' => 'BLS'],
            ['key' => 'annual_openings', 'value' => '124,200 个', 'source_label' => 'BLS'],
        ];

        return [
            'presentation_v1' => ['hero' => ['stats' => $stats]],
            'presentation_v2' => ['hero' => ['stats' => $stats]],
            'page' => ['content' => [
                'career_snapshot_secondary_locale' => [
                    'median' => '$81,680',
                    'growth' => '5%',
                    'wage_heading' => '旧工资',
                    'outlook_heading' => '2024–2034 年美国就业前景',
                    'direct_answer' => '旧答案',
                    'boundary' => '旧边界',
                    'bls_table' => [],
                    'industry_rows' => [],
                    'interpretation_rows' => [
                        ['question' => '年薪 $81,680 是入门工资吗？', 'answer' => '旧答案'],
                        ['question' => '税前月均是到手工资吗？', 'answer' => '旧答案'],
                    ],
                    'authority_sources' => '旧来源',
                ],
                'ai_impact_table' => [
                    'evidence_rows' => [[
                        '来源' => '美国劳工统计局（BLS）',
                        '研究对象' => '2024—2034 年预测',
                        '结论' => '124,200 个',
                    ]],
                    'questions' => [[
                        '问题' => '现在学会计还有前途吗？',
                        '回答' => 'BLS 2024—2034 年预计增长 5%，年均 124,200 个。',
                    ]],
                ],
                'market_signal_card' => ['outlook_evidence' => [[
                    'geography' => '美国',
                    'horizon' => '2024—2034',
                    'value' => '5%；约 124,200 个/年',
                ]]],
                'faq_block' => ['items' => [[
                    'question' => '职业前景如何？',
                    'answer' => '旧答案。',
                ]]],
            ]],
        ];
    }

    /** @return array<string,mixed> */
    private function content(): array
    {
        $definitions = [
            ['bls-us-accountants-wage-p10-2025', '$56,020', '美国', '2025 年 5 月', '工资第 10 分位数', 'source-bls-oews-2025'],
            ['bls-us-accountants-wage-p25-2025', '$67,020', '美国', '2025 年 5 月', '工资第 25 分位数', 'source-bls-oews-2025'],
            ['bls-us-accountants-wage-median-2025', '$83,680', '美国', '2025 年 5 月', '年薪中位数', 'source-bls-oews-2025'],
            ['bls-us-accountants-wage-p75-2025', '$109,810', '美国', '2025 年 5 月', '工资第 75 分位数', 'source-bls-oews-2025'],
            ['bls-us-accountants-wage-p90-2025', '$144,090', '美国', '2025 年 5 月', '工资第 90 分位数', 'source-bls-oews-2025'],
            ['bls-us-accountants-employment-2025', '1,595,200', '美国', '2025 年', '就业人数', 'source-bls-ooh-2025'],
            ['bls-us-accountants-employment-2035', '1,674,600', '美国', '2035 年', '预测就业人数', 'source-bls-ooh-2025'],
            ['bls-us-accountants-employment-net-change-2025-2035', '79,400', '美国', '2025—2035 年', '净增长人数', 'source-bls-ooh-2025'],
            ['bls-us-accountants-employment-growth-2025-2035', '5%', '美国', '2025—2035 年', '就业增长率', 'source-bls-ooh-2025'],
            ['bls-us-accountants-openings-2025-2035', '115,300', '美国', '2025—2035 年', '年均岗位空缺', 'source-bls-ooh-2025'],
            ['bls-us-accountants-industry-finance-2025', '$93,290', '美国', '2025 年 5 月', '行业年薪中位数', 'source-bls-ooh-2025'],
            ['bls-us-accountants-industry-management-2025', '$91,940', '美国', '2025 年 5 月', '行业年薪中位数', 'source-bls-ooh-2025'],
            ['bls-us-accountants-industry-government-2025', '$83,350', '美国', '2025 年 5 月', '行业年薪中位数', 'source-bls-ooh-2025'],
            ['bls-us-accountants-industry-accounting-services-2025', '$81,490', '美国', '2025 年 5 月', '行业年薪中位数', 'source-bls-ooh-2025'],
        ];
        $facts = array_map(static fn (array $definition): array => [
            'fact_id' => $definition[0],
            'display_value' => $definition[1],
            'market' => $definition[2],
            'period' => $definition[3],
            'measure' => $definition[4],
            'occupation_scope' => 'Accountants and Auditors (SOC 13-2011)',
            'source_refs' => [$definition[5]],
            'derivation' => null,
        ], $definitions);
        $facts[] = [
            'fact_id' => 'editorial-us-accountants-monthly-median-2025',
            'display_value' => '$6,973',
            'market' => '美国',
            'period' => '2025 年 5 月',
            'measure' => '税前年薪月均等值',
            'occupation_scope' => 'Accountants and Auditors (SOC 13-2011)',
            'source_refs' => ['source-bls-oews-2025'],
            'derivation' => '$83,680 ÷ 12，四舍五入',
        ];

        return [
            'locale' => 'zh-CN',
            'subject' => ['canonical_slug' => 'accountants-and-auditors'],
            'fact_register' => ['facts' => $facts],
            'blocks' => [
                ['items' => [[
                    'type' => 'faq',
                    'data' => ['entries' => [[
                        'answer' => '美国就业预计增长 5%，年均约 115,300 个岗位空缺。',
                    ]]],
                ]]],
                ['items' => [[
                    'type' => 'sources',
                    'data' => ['entries' => [
                        ['id' => 'source-bls-oews-2025', 'publisher' => 'BLS OEWS'],
                        ['id' => 'source-bls-ooh-2025', 'publisher' => 'BLS OOH'],
                    ]],
                ]]],
            ],
        ];
    }
}
