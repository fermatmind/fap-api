<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Compilation;

use App\Domain\Career\Compilation\CareerPresentationV1Compiler;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure;
use App\Domain\Career\Display\CareerPresentationV1Contract;
use App\Domain\Career\Display\CareerSupportingEvidenceV1Contract;
use Tests\TestCase;

final class CareerPresentationV1CompilerTest extends TestCase
{
    private const SOURCE = '/Users/rainie/Desktop/1046个职业/career-pages';

    public function test_current_package_contains_strict_zh_presentations_without_english_projection_drift(): void
    {
        ini_set('memory_limit', '1024M');
        $package = app(CareerCurrentAuthorityPackage::class);
        $authority = $package->load(base_path());

        self::assertCount(1046, $authority['rows']);
        self::assertSame(2092, $authority['summary']['locale_page_count']);
        self::assertSame(26, $authority['summary']['components_per_page']);
        foreach ($authority['rows'] as $row) {
            $presentation = $row['metadata_json']['presentation_v1']['zh'] ?? null;
            self::assertIsArray($presentation);
            CareerPresentationV1Contract::assert($presentation);
            self::assertArrayHasKey('presentation_v1', $authority['rows'][$row['canonical_slug']]['metadata_json']);
            self::assertArrayNotHasKey('presentation_v1', $package->publicProjection($row, 'en'));
            self::assertArrayHasKey('presentation_v1', $package->publicProjection($row, 'zh-CN'));
        }

        $accountants = $authority['rows']['accountants-and-auditors']['metadata_json']['presentation_v1']['zh'];
        self::assertSame(8, data_get($accountants, 'hero.ai_exposure.value'));
        self::assertSame('8/10', data_get($accountants, 'hero.ai_exposure.display_value'));
        self::assertSame('fermatmind_internal_rubric', data_get($accountants, 'hero.ai_exposure.metric_kind'));
        self::assertSame(['interest', 'scene', 'risk'], array_column(data_get($accountants, 'hero.badges'), 'key'));
        $supporting = $authority['rows']['accountants-and-auditors']['metadata_json']['supporting_evidence_v1']['zh'];
        CareerSupportingEvidenceV1Contract::assert(
            $supporting,
            array_values($authority['rows']['accountants-and-auditors']['sources_json']['references']),
        );
        self::assertSame(
            ['tasks', 'skills', 'abilities', 'knowledge', 'work_context', 'job_zone'],
            array_column(data_get($supporting, 'onet.tables'), 'key'),
        );
        self::assertSame([], data_get($supporting, 'ai_cases'));
        self::assertNull(data_get($supporting, 'china_reference'));
        self::assertArrayNotHasKey('supporting_evidence_v1', $package->publicProjection(
            $authority['rows']['accountants-and-auditors'],
            'en',
        ));

        $actuaries = $authority['rows']['actuaries']['metadata_json']['presentation_v1']['zh'];
        self::assertSame(8, data_get($actuaries, 'hero.ai_exposure.value'));
        self::assertSame(
            ['$125,770', '22%', '8/10'],
            array_column(data_get($actuaries, 'hero.stats'), 'value'),
        );
        self::assertStringNotContainsString(
            '国内已上自动报表与 AI 定价',
            CareerCurrentAuthorityPackage::encodeCanonical(data_get($actuaries, 'hero.ai_exposure')),
        );
    }

    public function test_accountants_and_actuaries_fields_match_the_immutable_master_when_available(): void
    {
        if (! is_dir(self::SOURCE)) {
            self::markTestSkipped('The immutable Desktop canonical source is not mounted in CI.');
        }
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        foreach (['accountants-and-auditors', 'actuaries'] as $slug) {
            $presentation = $package['rows'][$slug]['metadata_json']['presentation_v1']['zh'];
            $identity = $this->readJson(self::SOURCE.'/'.$slug.'/identity.json');
            $definition = $this->readJson(self::SOURCE.'/'.$slug.'/definition.json');
            $risk = $this->readJson(self::SOURCE.'/'.$slug.'/risk.json');
            $pageMeta = $this->readJson(self::SOURCE.'/'.$slug.'/page-meta.json');

            self::assertSame($identity['title_zh'], data_get($presentation, 'hero.title_zh'));
            self::assertSame($identity['title_en'], data_get($presentation, 'hero.title_en'));
            self::assertSame($identity['riasec_short'], data_get($presentation, 'hero.badges.0.text'));
            self::assertSame($definition['scene'], data_get($presentation, 'hero.badges.1.text'));
            self::assertSame($risk['risk_badge'], data_get($presentation, 'hero.badges.2.text'));
            self::assertSame($pageMeta['hero_lead'], data_get($presentation, 'hero.lead'));
            self::assertSame($pageMeta['gauge_note'], data_get($presentation, 'hero.ai_exposure.note'));
        }
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
                'bls_table' => [
                    ['指标' => '中位年薪', '数值' => '$1', '说明' => '美国劳工统计局'],
                ],
            ],
        ];

        $presentation = $compiler->project('fixture', $blocks, $row, $coverage, $missing);

        self::assertNull(data_get($presentation, 'hero.ai_exposure.value'));
        self::assertNull(data_get($presentation, 'hero.ai_exposure.display_value'));
        self::assertSame('missing', data_get($presentation, 'hero.ai_exposure.availability'));
        self::assertSame('测试职业', data_get($presentation, 'hero.title_zh'));
        self::assertSame(['us_median_pay'], array_column(data_get($presentation, 'hero.stats'), 'key'));
        self::assertSame($before, $row);
        self::assertNull(data_get($row, 'page_payload_json.page.zh.truth_layer.ai_exposure'));
        self::assertNull(data_get($row, 'page_payload_json.page.zh.score_bundle.ai_survival_score'));
        CareerPresentationV1Contract::assert($presentation);
    }

    public function test_standard_indicator_value_salary_schema_remains_supported_without_output_drift(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $actuaries = $package['rows']['actuaries']['metadata_json']['presentation_v1']['zh'];

        self::assertSame(
            ['$125,770', '22%'],
            array_column(array_slice(data_get($actuaries, 'hero.stats'), 0, 2), 'value'),
        );
        self::assertSame(
            ['salary.bls_table.中位年薪', 'salary.bls_table.就业增长'],
            array_map(
                static fn (array $stat): string => $stat['source_keys'][0],
                array_slice(data_get($actuaries, 'hero.stats'), 0, 2),
            ),
        );
    }

    public function test_contract_rejects_silent_extra_fields(): void
    {
        $package = app(CareerCurrentAuthorityPackage::class)->load(base_path());
        $presentation = $package['rows']['actuaries']['metadata_json']['presentation_v1']['zh'];
        $presentation['hero']['ai_exposure']['ai_survival_score'] = 8;

        $this->expectException(CareerCurrentAuthorityPackageFailure::class);
        $this->expectExceptionMessage('CURRENT_PRESENTATION_V1_INVALID');

        CareerPresentationV1Contract::assert($presentation);
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
