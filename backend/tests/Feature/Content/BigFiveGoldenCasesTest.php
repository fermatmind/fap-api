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

final class BigFiveGoldenCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_golden_cases_scores_and_report_variants_are_stable(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('norms:import --scale=BIG5_OCEAN --csv=resources/norms/big5/big5_norm_stats_seed.csv --activate=1')
            ->assertExitCode(0);

        $loader = app(BigFivePackLoader::class);
        $questions = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $norms = $loader->readCompiledJson('norms.compiled.json', 'v1');
        $policyCompiled = $loader->readCompiledJson('policy.compiled.json', 'v1');
        $golden = $loader->readCompiledJson('golden_cases.compiled.json', 'v1');

        $this->assertIsArray($questions);
        $this->assertIsArray($norms);
        $this->assertIsArray($policyCompiled);
        $this->assertIsArray($golden);

        $questionIndex = [];
        foreach ((array) ($questions['question_index'] ?? []) as $qid => $row) {
            if (!is_array($row)) {
                continue;
            }
            $questionIndex[(int) $qid] = $row;
        }

        $policy = is_array($policyCompiled['policy'] ?? null) ? $policyCompiled['policy'] : [];

        /** @var BigFiveScorerV3 $scorer */
        $scorer = app(BigFiveScorerV3::class);
        /** @var BigFiveReportComposer $composer */
        $composer = app(BigFiveReportComposer::class);

        $cases = is_array($golden['cases'] ?? null) ? $golden['cases'] : [];
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertIsArray($case);

            $answersById = [];
            foreach ((array) ($case['answers'] ?? []) as $answer) {
                if (!is_array($answer)) {
                    continue;
                }
                $qid = (int) ($answer['question_id'] ?? 0);
                $code = (int) ($answer['code'] ?? 0);
                if ($qid > 0) {
                    $answersById[$qid] = $code;
                }
            }

            $score = $scorer->score($answersById, $questionIndex, (array) $norms, $policy, [
                'locale' => (string) ($case['locale'] ?? 'zh-CN'),
                'country' => (string) ($case['country'] ?? 'CN_MAINLAND'),
                'region' => (string) ($case['country'] ?? 'CN_MAINLAND'),
                'gender' => (string) ($case['gender'] ?? 'ALL'),
                'age_band' => (string) ($case['age_band'] ?? 'all'),
                'time_seconds_total' => (float) ($case['time_seconds_total'] ?? 0),
                'duration_ms' => (int) round(((float) ($case['time_seconds_total'] ?? 0)) * 1000),
            ]);

            $this->assertArrayHasKey('raw_scores', $score);
            $this->assertCount(5, (array) ($score['raw_scores']['domains_mean'] ?? []));
            $this->assertCount(30, (array) ($score['raw_scores']['facets_mean'] ?? []));

            $expectedStatus = (string) ($case['expected_norms_status'] ?? '');
            $this->assertSame($expectedStatus, (string) ($score['norms']['status'] ?? ''));
            $expectedGroupId = ((string) ($case['locale'] ?? 'zh-CN')) === 'en'
                ? 'en_johnson_all_18-60'
                : 'zh-CN_prod_all_18-60';
            $this->assertSame($expectedGroupId, (string) ($score['norms']['group_id'] ?? ''));

            $expectedDomainBuckets = is_array($case['expected_domain_buckets'] ?? null)
                ? $case['expected_domain_buckets']
                : [];
            $this->assertSame($expectedDomainBuckets, (array) ($score['facts']['domain_buckets'] ?? []));

            $expectedTags = is_array($case['expected_tags'] ?? null) ? $case['expected_tags'] : [];
            $actualTags = is_array($score['tags'] ?? null) ? $score['tags'] : [];
            foreach ($expectedTags as $expectedTag) {
                $this->assertContains($expectedTag, $actualTags);
            }

            $attempt = new Attempt([
                'id' => '00000000-0000-0000-0000-000000000001',
                'org_id' => 0,
                'scale_code' => 'BIG5_OCEAN',
                'dir_version' => 'v1',
                'locale' => (string) ($case['locale'] ?? 'zh-CN'),
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

            $freeComposed = $composer->composeVariant($attempt, $result, ReportAccess::VARIANT_FREE, [
                'modules_allowed' => [ReportAccess::MODULE_BIG5_CORE],
            ]);
            $this->assertTrue((bool) ($freeComposed['ok'] ?? false));
            $freeSections = array_map(
                static fn (array $s): string => (string) ($s['key'] ?? ''),
                (array) data_get($freeComposed, 'report.sections', [])
            );
            $this->assertSame((array) ($case['expected_free_sections'] ?? []), $freeSections);

            $fullComposed = $composer->composeVariant($attempt, $result, ReportAccess::VARIANT_FULL, [
                'modules_allowed' => [
                    ReportAccess::MODULE_BIG5_CORE,
                    ReportAccess::MODULE_BIG5_FULL,
                    ReportAccess::MODULE_BIG5_ACTION_PLAN,
                ],
            ]);
            $this->assertTrue((bool) ($fullComposed['ok'] ?? false));
            $fullSections = array_map(
                static fn (array $s): string => (string) ($s['key'] ?? ''),
                (array) data_get($fullComposed, 'report.sections', [])
            );
            $this->assertSame((array) ($case['expected_full_sections'] ?? []), $fullSections);
        }
    }
}
