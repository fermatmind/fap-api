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

final class BigFiveDisclaimerContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_disclaimer_sections_are_always_present_in_free_and_full_variants(): void
    {
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
            if (!is_array($row)) {
                continue;
            }
            $questionIndex[(int) $qid] = $row;
        }

        $answersById = [];
        for ($i = 1; $i <= 120; $i++) {
            $answersById[$i] = 3;
        }

        /** @var BigFiveScorerV3 $scorer */
        $scorer = app(BigFiveScorerV3::class);
        $score = $scorer->score(
            $answersById,
            $questionIndex,
            is_array($norms) ? $norms : [],
            is_array($policyCompiled['policy'] ?? null) ? $policyCompiled['policy'] : [],
            [
                'locale' => 'zh-CN',
                'country' => 'CN_MAINLAND',
                'region' => 'CN_MAINLAND',
                'gender' => 'ALL',
                'age_band' => 'all',
                'time_seconds_total' => 480.0,
                'duration_ms' => 480000,
            ]
        );

        $attempt = new Attempt([
            'id' => '00000000-0000-0000-0000-000000000002',
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'locale' => 'zh-CN',
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

        /** @var BigFiveReportComposer $composer */
        $composer = app(BigFiveReportComposer::class);

        $free = $composer->composeVariant($attempt, $result, ReportAccess::VARIANT_FREE, [
            'modules_allowed' => [ReportAccess::MODULE_BIG5_CORE],
        ]);
        $this->assertTrue((bool) ($free['ok'] ?? false));

        $full = $composer->composeVariant($attempt, $result, ReportAccess::VARIANT_FULL, [
            'modules_allowed' => [
                ReportAccess::MODULE_BIG5_CORE,
                ReportAccess::MODULE_BIG5_FULL,
                ReportAccess::MODULE_BIG5_ACTION_PLAN,
            ],
        ]);
        $this->assertTrue((bool) ($full['ok'] ?? false));

        $this->assertDisclaimerContract((array) data_get($free, 'report.sections', []));
        $this->assertDisclaimerContract((array) data_get($full, 'report.sections', []));
    }

    /**
     * @param list<array<string,mixed>> $sections
     */
    private function assertDisclaimerContract(array $sections): void
    {
        $keys = array_map(
            static fn (array $section): string => (string) ($section['key'] ?? ''),
            $sections
        );

        $topIndex = array_search('disclaimer_top', $keys, true);
        $summaryIndex = array_search('summary', $keys, true);
        $bottomIndex = array_search('disclaimer', $keys, true);

        $this->assertNotFalse($topIndex);
        $this->assertNotFalse($summaryIndex);
        $this->assertNotFalse($bottomIndex);
        $this->assertLessThan((int) $summaryIndex, (int) $topIndex);
        $this->assertSame(count($keys) - 1, (int) $bottomIndex);

        foreach ($sections as $section) {
            if (!in_array((string) ($section['key'] ?? ''), ['disclaimer_top', 'disclaimer'], true)) {
                continue;
            }
            $this->assertSame('free', (string) ($section['access_level'] ?? ''));
            foreach ((array) ($section['blocks'] ?? []) as $block) {
                $this->assertIsArray($block);
                $title = (string) ($block['title'] ?? '');
                $body = (string) ($block['body'] ?? '');
                $this->assertStringNotContainsString('{{', $title . $body);
                $this->assertStringNotContainsString('}}', $title . $body);
            }
        }
    }
}

