<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationV1Compiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerPresentationV1Contract;
use Tests\TestCase;

final class CareerPresentationV1CompilerTest extends TestCase
{
    public function test_legacy_zh_projection_preserves_the_bilingual_input_and_strict_contract(): void
    {
        [$presentation, $blocks, $row] = $this->fixtureProjection();
        CareerPresentationV1Contract::assert($presentation);
        self::assertSame(['en', 'zh'], array_keys($row['page_payload_json']['page']));
        self::assertSame('Unchanged English fixture', $row['page_payload_json']['page']['en']['hero']['h1']);
        self::assertSame(8, data_get($presentation, 'hero.ai_exposure.value'));
        self::assertSame('8/10', data_get($presentation, 'hero.ai_exposure.display_value'));
        self::assertSame('fermatmind_internal_rubric', data_get($presentation, 'hero.ai_exposure.metric_kind'));
        self::assertSame(['interest', 'scene', 'risk'], array_column(data_get($presentation, 'hero.badges'), 'key'));
        self::assertSame(['$125,770', '22%', '33,600 人', '2,400 个', '8/10'], array_column(data_get($presentation, 'hero.stats'), 'value'));
    }

    public function test_legacy_projection_uses_only_explicit_source_fields(): void
    {
        [$presentation, $blocks] = $this->fixtureProjection();
        self::assertSame($blocks['identity']['title_zh'], data_get($presentation, 'hero.title_zh'));
        self::assertSame($blocks['identity']['title_en'], data_get($presentation, 'hero.title_en'));
        self::assertSame($blocks['identity']['riasec_short'], data_get($presentation, 'hero.badges.0.text'));
        self::assertSame($blocks['definition']['scene'], data_get($presentation, 'hero.badges.1.text'));
        self::assertSame($blocks['risk']['risk_badge'], data_get($presentation, 'hero.badges.2.text'));
        self::assertSame($blocks['page-meta']['hero_lead'], data_get($presentation, 'hero.lead'));
        self::assertSame($blocks['page-meta']['gauge_note'], data_get($presentation, 'hero.ai_exposure.note'));
    }

    public function test_missing_ai_score_does_not_parse_body_text_or_block_other_hero_fields(): void
    {
        $compiler = app(CareerPresentationV1Compiler::class);
        $coverage = $compiler->newCoverage();
        $missing = [];
        $row = [
            'page_payload_json' => ['page' => ['zh' => [
                'primary_cta' => ['label' => '开始测试', 'href' => '/zh/tests/holland-career-interest-test-riasec'],
                'career_snapshot_primary_locale' => ['salary' => ['china_salary_note' => '薪资仅供参考。']],
                'boundary_notice' => ['仅用于职业探索。'],
                'truth_layer' => ['ai_exposure' => null],
                'score_bundle' => ['ai_survival_score' => null],
            ]]],
        ];
        $before = $row;
        $blocks = [
            'identity' => [
                'title_zh' => '测试职业', 'title_en' => 'Test occupation', 'soc' => '11-1011',
                'onet' => '11-1011.00', 'riasec_short' => '研究型 I',
            ],
            'definition' => ['scene' => '结构化场景'],
            'risk' => ['risk_badge' => '中风险'],
            'page-meta' => [
                'hero_lead' => '有效 Hero lead。',
                'gauge_note' => '正文提到 8/10，但不得作为评分来源。',
                'snapshot_callout' => '有效快照。',
            ],
            'salary' => [
                'china_ai_row' => '8/10，较高',
            ],
        ];

        $presentation = $compiler->project('fixture', $blocks, $row, $coverage, $missing);

        self::assertNull(data_get($presentation, 'hero.ai_exposure.value'));
        self::assertNull(data_get($presentation, 'hero.ai_exposure.display_value'));
        self::assertSame('missing', data_get($presentation, 'hero.ai_exposure.availability'));
        self::assertSame('测试职业', data_get($presentation, 'hero.title_zh'));
        self::assertSame([], data_get($presentation, 'hero.stats'));
        self::assertSame($before, $row);
        self::assertNull(data_get($row, 'page_payload_json.page.zh.truth_layer.ai_exposure'));
        self::assertNull(data_get($row, 'page_payload_json.page.zh.score_bundle.ai_survival_score'));
        CareerPresentationV1Contract::assert($presentation);
    }

    public function test_standard_indicator_value_salary_schema_remains_supported_without_output_drift(): void
    {
        [$actuaries] = $this->fixtureProjection();

        self::assertSame(
            ['$125,770', '22%', '33,600 人', '2,400 个'],
            array_column(array_slice(data_get($actuaries, 'hero.stats'), 0, 4), 'value'),
        );
        self::assertSame(
            ['salary.bls_table.中位年薪', 'salary.bls_table.就业增长', 'salary.bls_table.在岗人数', 'salary.bls_table.年均职位空缺'],
            array_map(
                static fn (array $stat): string => $stat['source_keys'][0],
                array_slice(data_get($actuaries, 'hero.stats'), 0, 4),
            ),
        );
    }

    public function test_contract_rejects_silent_extra_fields(): void
    {
        [$presentation] = $this->fixtureProjection();
        $presentation['hero']['ai_exposure']['ai_survival_score'] = 8;

        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_PRESENTATION_V1_INVALID');

        CareerPresentationV1Contract::assert($presentation);
    }

    /** Minimal retired projection input; not Current occupation content. */
    private function fixtureProjection(): array
    {
        $compiler = app(CareerPresentationV1Compiler::class);
        (new \ReflectionProperty($compiler, 'registry'))->setValue($compiler, ['document' => [], 'onet' => [], 'bls' => []]);
        $blocks = [
            'identity' => ['title_zh' => '测试职业', 'title_en' => 'Fixture occupation', 'soc' => '11-1011', 'onet' => '11-1011.00', 'riasec_short' => '研究型 I', 'ai_score' => 8],
            'definition' => ['scene' => '测试工作场景'],
            'risk' => ['risk_badge' => '测试风险说明'],
            'page-meta' => ['hero_lead' => '测试导语', 'gauge_note' => '内部 rubric 测试说明', 'snapshot_callout' => '测试快照说明'],
            'salary' => ['bls_table' => [
                ['指标' => '中位年薪', '数值' => '$125,770'],
                ['指标' => '就业增长', '数值' => '22%'],
                ['指标' => '在岗人数', '数值' => '33,600 人'],
                ['指标' => '年均职位空缺', '数值' => '2,400 个'],
            ]],
        ];
        $row = ['page_payload_json' => ['page' => [
            'en' => ['hero' => ['h1' => 'Unchanged English fixture']],
            'zh' => [
                'primary_cta' => ['label' => '开始测试', 'href' => '/zh/tests/holland-career-interest-test-riasec'],
                'career_snapshot_primary_locale' => ['salary' => ['china_salary_note' => '测试薪资边界']],
                'boundary_notice' => ['仅用于测试。'],
            ],
        ]]];
        $before = $row;
        $coverage = $compiler->newCoverage();
        $missing = [];
        $presentation = $compiler->project('fixture-occupation', $blocks, $row, $coverage, $missing);
        self::assertSame($before, $row);

        return [$presentation, $blocks, $row];
    }
}
