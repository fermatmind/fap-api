<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Display\CareerContentV3FactResolver;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerPageProjector;
use PHPUnit\Framework\TestCase;

final class CareerPageProjectorTest extends TestCase
{
    private function projector(): CareerPageProjector
    {
        $package = new CareerContentV3AuthorityPackage;

        return new CareerPageProjector(new CareerContentV3CanonicalReader($package, dirname(__DIR__, 5)), $package, new CareerContentV3FactResolver);
    }

    public function test_every_locale_file_projects_without_database_display_data(): void
    {
        $root = dirname(__DIR__, 5).'/content_assets/career/current';
        $manifest = json_decode(file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $projector = $this->projector();
        foreach ($manifest['files'] as $entry) {
            $source = json_decode(file_get_contents($root.'/'.$entry['path']), true, 512, JSON_THROW_ON_ERROR);
            $page = $projector->project($source);
            self::assertSame($entry['source_content_sha256'], $page['source_content_sha256']);
            self::assertSame(array_column($source['blocks'], 'id'), array_column($page['content']['blocks'], 'id'));
            self::assertCount(5, $page['hero']['metrics']);
            self::assertArrayNotHasKey('presentation_v2', $page);
            self::assertArrayNotHasKey('page', $page);
        }
    }

    public function test_actor_pay_keeps_hourly_and_historical_project_units(): void
    {
        $page = $this->projector()->read('actors', 'zh-CN');
        self::assertSame('美国时薪中位数', $page['hero']['metrics'][0]['label']);
        self::assertSame('29.05美元/小时', $page['hero']['metrics'][0]['fact']['display_value']);
        self::assertSame('available', $page['hero']['metrics'][4]['availability']);
        self::assertSame('中国历史单项目特约演员日价', $page['hero']['metrics'][4]['label']);
        self::assertSame('300–600元/天', $page['hero']['metrics'][4]['fact']['display_value']);
        self::assertStringContainsString('不代表群众演员或全国职业收入', $page['hero']['metrics'][4]['fact']['occupation_scope']);
        self::assertStringContainsString('不换算月薪', $page['hero']['metrics'][4]['fact']['derivation']);
        self::assertSame(['r2-pay-1'], $page['hero']['metrics'][4]['fact']['source_refs']);
        self::assertStringNotContainsString('这行就稳', CareerCurrentAuthorityPackage::encodeCanonical($page));
    }

    public function test_reviewed_occupation_labels_preserve_own_and_related_code_roles(): void
    {
        $projector = $this->projector();
        foreach ([
            'actuaries' => ['15-2011.00'],
            'administrative-law-judges-adjudicators-and-hearing-officers' => ['23-1021.00'],
            'advanced-practice-psychiatric-nurses' => ['29-1141.02'],
            'aerospace-engineers' => ['17-2011.00'],
            'agricultural-and-food-scientists' => ['19-1011.00', '19-1012.00', '19-1013.00'],
            'administrative-services-managers' => ['11-3012.00'],
        ] as $slug => $codes) {
            $page = $projector->read($slug, 'zh-CN');
            self::assertSame($slug, $page['subject']['canonical_slug']);
            foreach ($codes as $code) {
                self::assertStringContainsString($code, $page['display']['components']['onet_structured_fields_block']['rows'][0]['label']);
            }
        }
        self::assertStringContainsString('11-3012.00', CareerCurrentAuthorityPackage::encodeCanonical($projector->read('document-management-specialists', 'zh-CN')));
    }

    public function test_actuary_positive_and_caution_conditions_reach_the_correct_fields(): void
    {
        $checklist = $this->projector()->read('actuaries', 'zh-CN')['display']['components']['fit_decision_checklist'];
        self::assertStringContainsString('愿意追查异常、质疑假设', $checklist['suit']);
        self::assertStringContainsString('排斥长期复算', $checklist['boundary']);
        self::assertStringNotContainsString('排斥长期复算', $checklist['suit']);
    }

    public function test_actuary_outlook_and_adjacent_rows_keep_field_semantics_and_source_binding(): void
    {
        $display = $this->projector()->read('actuaries', 'zh-CN')['display']['components'];
        $market = $display['market_signal_card'];
        foreach ([['就业人数', '约 31,200 人', '2025 年'], ['预测就业增长率', '9%', '2025—2035 年'], ['年均职位空缺', '约 1,500 个/年', '2025—2035 年']] as $i => [$metric, $value, $period]) {
            self::assertSame($metric, $market['outlook_evidence'][$i]['metric']);
            self::assertSame($value, $market['outlook_evidence'][$i]['value']);
            self::assertSame($period, $market['outlook_evidence'][$i]['horizon']);
            self::assertSame('美国', $market['outlook_evidence'][$i]['geography']);
            self::assertSame('bls-actuaries-ooh-2025', $market['outlook_evidence'][$i]['source_id']);
        }
        self::assertSame('https://www.bls.gov/ooh/math/actuaries.htm', $market['source_links'][0]['href']);
        self::assertStringContainsString('不等于净新增就业', $market['outlook_evidence'][2]['interpretation']);
        self::assertStringContainsString('78%', $market['direct_answer']);
        $names = ['financial-risk-specialists' => '金融风险专员', 'statisticians' => '统计师', 'insurance-underwriters' => '保险承保人', 'bioinformatics-technicians' => '生物信息技术员'];
        foreach ($market['transitions'] as $row) {
            self::assertSame($names[$row['target_slug']], $row['target_title']);
            self::assertSame('/zh/career/jobs/'.$row['target_slug'], $row['target_href']);
            self::assertDoesNotMatchRegularExpression('/31,200|1,500|78%|就业增长/', $row['shared_capabilities'].' '.$row['capability_gaps']);
        }
        self::assertSame(array_values($names), array_column($display['adjacent_career_comparison_table']['rows'], '职业方向'));
        self::assertStringContainsString('逐笔', $display['adjacent_career_comparison_table']['rows'][2]['更适合什么选择']);
        self::assertSame(['Big Five（英文）', 'MBTI（中文）', '九型人格（中文）', '智力与推理（中文）'], array_column($display['source_card']['secondary_links'], 'label'));
    }

    public function test_editor_comparison_tasks_and_sources_match_each_named_role(): void
    {
        $page = $this->projector()->read('editors', 'zh-CN');
        $table = $page['display']['components']['adjacent_career_comparison_table'];
        foreach ([
            ['management-analysts', '管理咨询分析师：研究组织', '13-1111.00'],
            ['web-and-digital-interface-designers', '网页与数字界面设计师：设计并测试界面', '15-1255.00'],
            ['web-administrators', '网站管理员：管理网站环境', '15-1299.01'],
            ['document-management-specialists', '文档管理专员：实施和管理企业文档系统', '15-1299.03'],
            ['library-science-teachers-postsecondary', '高校图书馆学教师：讲授图书馆学课程', '25-1082.00'],
            ['writers-and-authors', '作家与作者：原创并准备', '27-3043.00'],
        ] as $index => [$slug, $task, $code]) {
            self::assertStringContainsString($slug, $table['rows'][$index]['职业方向']);
            self::assertStringStartsWith($task, $table['rows'][$index]['核心工作与产出']);
            self::assertSame('https://www.onetonline.org/link/summary/'.$code, $table['evidence_links'][$index]['href']);
        }
        self::assertStringContainsString('编辑：规划、审阅、修订和协调待发布内容', $table['intro']);
        self::assertStringContainsString('技术写作者：把复杂技术信息写成手册', $table['intro']);
    }

    public function test_reviewed_wage_and_openings_explanations_preserve_statistics_and_periods(): void
    {
        $projector = $this->projector();
        $designer = CareerCurrentAuthorityPackage::encodeCanonical($projector->read('commercial-and-industrial-designers', 'zh-CN'));
        self::assertStringContainsString('83,910', $designer);
        self::assertStringContainsString('40.34', $designer);
        self::assertStringContainsString('不能推断均值与中位数相同', $designer);
        self::assertStringNotContainsString('即平均值与中位数在该页面上相同', $designer);
        $aerospace = CareerCurrentAuthorityPackage::encodeCanonical($projector->read('aerospace-engineers', 'zh-CN'));
        self::assertStringContainsString('平均每年约 4,500', $aerospace);
        self::assertStringContainsString('不能理解为净新增', $aerospace);
        self::assertStringNotContainsString('十年约 4,500', $aerospace);
        $aircraft = CareerCurrentAuthorityPackage::encodeCanonical($projector->read('aircraft-and-avionics-equipment-mechanics-and-technicians', 'zh-CN'));
        self::assertStringContainsString('平均每年约 11,300', $aircraft);
        self::assertStringContainsString('2025—2035', $aircraft);
        self::assertStringContainsString('年均约 13,100', $aircraft);
        self::assertStringNotContainsString('十年约 11,300', $aircraft);
        $baker = CareerCurrentAuthorityPackage::encodeCanonical($projector->read('bakers', 'zh-CN'));
        self::assertStringContainsString('第10分位（P10）', $baker);
        self::assertStringContainsString('并非工资分布的最小值', $baker);
        self::assertStringContainsString('不是入门起薪的承诺', $baker);
        self::assertStringContainsString('年均约 39,900', $baker);
        self::assertStringNotContainsString('为分布的下限', $baker);
    }

    public function test_reviewed_hero_metrics_use_their_own_statistic_and_keep_china_missing(): void
    {
        $projector = $this->projector();
        foreach ([
            'middle-school-teachers' => ['us_pay' => '64,370', 'us_growth' => '0%', 'us_employment' => '630,100', 'us_openings' => '38,800'],
            'aerospace-engineers' => ['us_pay' => '134,960', 'us_growth' => '6%', 'us_employment' => '71,600', 'us_openings' => '4,500'],
        ] as $slug => $expected) {
            $page = $projector->read($slug, 'zh-CN');
            $metrics = array_column($page['hero']['metrics'], null, 'key');
            foreach ($expected as $key => $value) {
                self::assertSame('available', $metrics[$key]['availability']);
                self::assertStringContainsString($value, $metrics[$key]['fact']['display_value']);
            }
            self::assertSame('missing', $metrics['china_pay']['availability']);
            self::assertNull($metrics['china_pay']['fact']);
            self::assertSame('missing', $page['hero']['ai']['availability']);
        }
        $aerospace = array_column($projector->read('aerospace-engineers', 'zh-CN')['hero']['metrics'], null, 'key');
        self::assertSame('2024', $aerospace['us_employment']['fact']['period']);
        self::assertSame('2024—2034', $aerospace['us_openings']['fact']['period']);
        self::assertSame('年均职位空缺', $aerospace['us_openings']['fact']['measure']);
    }

    public function test_reviewed_shared_fit_bindings_keep_positive_caution_and_definition_distinct(): void
    {
        $slugs = [
            'astronomers',
            'biomass-power-plant-managers',
            'bill-and-account-collectors',
            'barbers-hairstylists-and-cosmetologists',
            'automotive-engineers',
            'barbers',
            'athletic-trainers',
            'biofuels-production-managers',
            'automotive-service-technicians-and-mechanics',
            'audiovisual-equipment-installers-and-repairers',
            'automotive-body-and-glass-repairers',
            'biofuels-biodiesel-technology-and-product-development-managers',
            'automotive-and-watercraft-service-attendants',
            'automotive-engineering-technicians',
            'biochemists-and-biophysicists',
            'biofuels-processing-technicians',
            'atmospheric-scientists-including-meteorologists',
            'atmospheric-and-space-scientists',
            'audiologists',
            'athletes-and-sports-competitors',
            'biological-science-teachers-postsecondary',
            'audio-and-video-technicians',
            'avionics-technicians',
            'baristas',
            'bakers',
            'biomedical-engineers',
            'bailiffs',
            'bioinformatics-scientists',
            'biologists',
            'bartenders',
            'bicycle-repairers',
            'billing-and-posting-clerks',
            'baggage-porters-and-bellhops',
            'biological-technicians',
            'assemblers-and-fabricators',
            'aviation-inspectors',
            'automotive-glass-installers-and-repairers',
            'biomass-plant-technicians',
            'bioinformatics-technicians',
        ];
        foreach ($slugs as $slug) {
            $source = json_decode((string) file_get_contents(dirname(__DIR__, 5).'/content_assets/career/current/careers/'.$slug.'/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
            $page = $this->projector()->project($source);
            $fit = $page['display']['components']['fit_decision_checklist'];
            self::assertStringStartsWith('继续探索的信号：', $fit['suit'], $slug);
            self::assertStringStartsWith('先暂停投入的条件：', $fit['boundary'], $slug);
            self::assertStringStartsWith('一句话判断：', $fit['how'], $slug);
            self::assertSame('这项工作主要做什么？', $page['display']['interface']['interface.quick_decision.experiment_heading'], $slug);
            foreach (['suit' => '2', 'boundary' => '3', 'how' => '1'] as $key => $suffix) {
                self::assertSame($slug.'.quick-decision.fit-decision-checklist.'.$suffix, $source['authoring_structure']['slots']['fit_decision_checklist.'.$key]['ref']['id'], $slug);
            }
        }
    }

    public function test_missing_file_and_wrong_locale_fail_closed(): void
    {
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->projector()->read('actors', 'fr');
    }

    public function test_metric_cannot_reference_an_unknown_fact(): void
    {
        $source = json_decode(file_get_contents(dirname(__DIR__, 5).'/content_assets/career/current/careers/actors/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
        $source['hero']['metrics'][0]['fact_ref'] = 'absent';
        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->projector()->project($source);
    }

    public function test_internal_import_markers_remain_in_the_file_but_never_become_public_body(): void
    {
        $source = json_decode(file_get_contents(dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
        $page = $this->projector()->project($source);
        $publicIds = array_column(array_merge(...array_column($page['content']['blocks'], 'items')), 'id');
        $internalCount = 0;
        foreach ($source['blocks'] as $block) {
            foreach ($block['items'] as $item) {
                if (($item['visibility'] ?? 'public') === 'internal') {
                    $internalCount++;
                    self::assertNotContains($item['id'], $publicIds);
                } else {
                    self::assertContains($item['id'], $publicIds);
                }
            }
        }
        self::assertGreaterThan(0, $internalCount);
        self::assertStringNotContainsString('"paragraphs":["published"]', CareerCurrentAuthorityPackage::encodeCanonical($page));
    }
}
