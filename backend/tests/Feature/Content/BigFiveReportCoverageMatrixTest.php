<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Attempt;
use App\Models\Result;
use App\Services\Assessment\Scorers\BigFiveScorerV3;
use App\Services\Content\BigFivePackLoader;
use App\Services\Report\BigFiveReportComposer;
use App\Services\Report\ReportAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveReportCoverageMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_big5_report_coverage_matrix_is_stable_and_non_empty(): void
    {
        $this->artisan('content:lint --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        $questions = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $norms = $loader->readCompiledJson('norms.compiled.json', 'v1');
        $policyCompiled = $loader->readCompiledJson('policy.compiled.json', 'v1');

        $questionIndex = [];
        foreach ((array) ($questions['question_index'] ?? []) as $qid => $row) {
            if (! is_array($row)) {
                continue;
            }
            $questionIndex[(int) $qid] = $row;
        }

        /** @var BigFiveScorerV3 $scorer */
        $scorer = app(BigFiveScorerV3::class);
        /** @var BigFiveReportComposer $composer */
        $composer = app(BigFiveReportComposer::class);
        $policy = is_array($policyCompiled['policy'] ?? null) ? $policyCompiled['policy'] : [];
        $compiledNorms = is_array($norms) ? $norms : [];

        $cases = [
            [
                'name' => 'zh_free_neutral',
                'locale' => 'zh-CN',
                'region' => 'CN_MAINLAND',
                'country' => 'CN_MAINLAND',
                'variant' => ReportAccess::VARIANT_FREE,
                'modules_allowed' => [ReportAccess::MODULE_BIG5_CORE],
                'answers' => $this->constantAnswers(3),
            ],
            [
                'name' => 'zh_full_high',
                'locale' => 'zh-CN',
                'region' => 'CN_MAINLAND',
                'country' => 'CN_MAINLAND',
                'variant' => ReportAccess::VARIANT_FULL,
                'modules_allowed' => [
                    ReportAccess::MODULE_BIG5_CORE,
                    ReportAccess::MODULE_BIG5_FULL,
                    ReportAccess::MODULE_BIG5_ACTION_PLAN,
                ],
                'answers' => $this->constantAnswers(5),
            ],
            [
                'name' => 'en_full_low',
                'locale' => 'en',
                'region' => 'GLOBAL',
                'country' => 'GLOBAL',
                'variant' => ReportAccess::VARIANT_FULL,
                'modules_allowed' => [
                    ReportAccess::MODULE_BIG5_CORE,
                    ReportAccess::MODULE_BIG5_FULL,
                    ReportAccess::MODULE_BIG5_ACTION_PLAN,
                ],
                'answers' => $this->constantAnswers(1),
            ],
            [
                'name' => 'en_free_zigzag',
                'locale' => 'en',
                'region' => 'GLOBAL',
                'country' => 'GLOBAL',
                'variant' => ReportAccess::VARIANT_FREE,
                'modules_allowed' => [ReportAccess::MODULE_BIG5_CORE],
                'answers' => $this->zigzagAnswers(),
            ],
        ];

        foreach ($cases as $idx => $case) {
            $score = $scorer->score(
                (array) $case['answers'],
                $questionIndex,
                $compiledNorms,
                $policy,
                [
                    'locale' => (string) $case['locale'],
                    'country' => (string) $case['country'],
                    'region' => (string) $case['region'],
                    'gender' => 'ALL',
                    'age_band' => 'all',
                    'time_seconds_total' => 600.0,
                    'duration_ms' => 600000,
                ]
            );

            $attempt = new Attempt([
                'id' => sprintf('00000000-0000-0000-0000-%012d', $idx + 101),
                'org_id' => 0,
                'scale_code' => 'BIG5_OCEAN',
                'dir_version' => 'v1',
                'locale' => (string) $case['locale'],
                'region' => (string) $case['region'],
            ]);
            $result = new Result([
                'result_json' => [
                    'normed_json' => $score,
                    'breakdown_json' => [
                        'score_result' => $score,
                    ],
                    'axis_scores_json' => [
                        'score_result' => $score,
                    ],
                ],
            ]);

            $first = $composer->composeVariant(
                $attempt,
                $result,
                (string) $case['variant'],
                ['modules_allowed' => (array) $case['modules_allowed']]
            );
            $second = $composer->composeVariant(
                $attempt,
                $result,
                (string) $case['variant'],
                ['modules_allowed' => (array) $case['modules_allowed']]
            );

            $this->assertTrue((bool) ($first['ok'] ?? false), (string) $case['name']);
            $this->assertSame(
                data_get($first, 'report.sections'),
                data_get($second, 'report.sections'),
                (string) $case['name'].' sections'
            );
            $this->assertSame(
                data_get($first, 'report.norms'),
                data_get($second, 'report.norms'),
                (string) $case['name'].' norms'
            );
            $this->assertSame(
                data_get($first, 'report.quality'),
                data_get($second, 'report.quality'),
                (string) $case['name'].' quality'
            );

            $sections = (array) data_get($first, 'report.sections', []);
            $this->assertNotEmpty($sections, (string) $case['name']);
            $keys = array_values(array_map(static fn (array $s): string => (string) ($s['key'] ?? ''), $sections));

            if ((string) $case['variant'] === ReportAccess::VARIANT_FREE) {
                $this->assertSame(['disclaimer_top', 'summary', 'domains_overview', 'disclaimer'], $keys, (string) $case['name']);
                foreach ($sections as $section) {
                    $this->assertSame('free', strtolower((string) ($section['access_level'] ?? 'free')), (string) $case['name']);
                }
            } else {
                $this->assertSame(
                    ['disclaimer_top', 'summary', 'domains_overview', 'facet_table', 'top_facets', 'facets_deepdive', 'action_plan', 'disclaimer'],
                    $keys,
                    (string) $case['name']
                );
            }

            $seenBlockIds = [];
            foreach ($sections as $section) {
                $blocks = (array) ($section['blocks'] ?? []);
                $this->assertNotEmpty($blocks, (string) $case['name'].' section='.$section['key']);
                foreach ($blocks as $block) {
                    $this->assertIsArray($block, (string) $case['name']);
                    $id = trim((string) ($block['id'] ?? ''));
                    $this->assertNotSame('', $id, (string) $case['name']);
                    $this->assertArrayNotHasKey($id, $seenBlockIds, (string) $case['name'].' duplicate block id='.$id);
                    $seenBlockIds[$id] = true;

                    $rendered = (string) ($block['title'] ?? '').' '.(string) ($block['body'] ?? '');
                    $this->assertStringNotContainsString('{{', $rendered, (string) $case['name']);
                    $this->assertStringNotContainsString('}}', $rendered, (string) $case['name']);
                }
            }
        }
    }

    /**
     * @return array<int,int>
     */
    private function constantAnswers(int $value): array
    {
        $out = [];
        for ($i = 1; $i <= 120; $i++) {
            $out[$i] = $value;
        }

        return $out;
    }

    /**
     * @return array<int,int>
     */
    private function zigzagAnswers(): array
    {
        $out = [];
        for ($i = 1; $i <= 120; $i++) {
            $out[$i] = (int) (($i % 5) + 1);
        }

        return $out;
    }
}
