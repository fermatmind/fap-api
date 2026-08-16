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

final class BigFiveReportBlocksContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_and_blocks_contract_is_stable_for_free_and_full(): void
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
            'id' => '00000000-0000-0000-0000-000000000009',
            'org_id' => 0,
            'scale_code' => 'BIG5_OCEAN',
            'dir_version' => 'v1',
            'locale' => 'zh-CN',
            'region' => 'CN_MAINLAND',
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
        $freeSections = (array) data_get($free, 'report.sections', []);
        $freeKeys = array_values(array_map(static fn (array $s): string => (string) ($s['section_key'] ?? ''), $freeSections));
        $this->assertSame(
            [
                'hero_summary',
                'domains_overview',
                'domain_deep_dive',
                'facet_details',
                'core_portrait',
                'norms_comparison',
                'action_plan',
                'methodology_and_access',
            ],
            $freeKeys
        );
        foreach ($freeSections as $section) {
            if (in_array((string) ($section['section_key'] ?? ''), ['hero_summary', 'domains_overview', 'methodology_and_access'], true)) {
                $this->assertNotSame('locked', (string) ($section['status'] ?? ''));
                $this->assertNotEmpty($section['blocks'] ?? []);
            } else {
                $this->assertSame('locked', (string) ($section['status'] ?? ''));
                $this->assertSame([], $section['blocks'] ?? null);
            }
        }
        $this->assertSame('big5.public_projection.v1', (string) data_get($free, 'report._meta.big5_public_projection_v1.schema_version'));

        $full = $composer->composeVariant($attempt, $result, ReportAccess::VARIANT_FULL, [
            'modules_allowed' => [
                ReportAccess::MODULE_BIG5_CORE,
                ReportAccess::MODULE_BIG5_FULL,
                ReportAccess::MODULE_BIG5_ACTION_PLAN,
            ],
        ]);
        $this->assertTrue((bool) ($full['ok'] ?? false));
        $fullSections = (array) data_get($full, 'report.sections', []);
        $fullKeys = array_values(array_map(static fn (array $s): string => (string) ($s['section_key'] ?? ''), $fullSections));
        $this->assertSame(
            [
                'hero_summary',
                'domains_overview',
                'domain_deep_dive',
                'facet_details',
                'core_portrait',
                'norms_comparison',
                'action_plan',
                'methodology_and_access',
            ],
            $fullKeys
        );
        $this->assertSame('big5.public_projection.v1', (string) data_get($full, 'report._meta.big5_public_projection_v1.schema_version'));

        $sectionMap = [];
        foreach ($fullSections as $section) {
            $this->assertIsArray($section);
            $sectionMap[(string) ($section['section_key'] ?? '')] = $section;
            foreach ((array) ($section['blocks'] ?? []) as $block) {
                $this->assertIsArray($block);
                $rendered = json_encode($block['resolved_copy'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->assertDoesNotMatchRegularExpression('/\{\{[^}]+\}\}/', $rendered);
            }
        }

        $this->assertCount(5, (array) (($sectionMap['domains_overview']['blocks'] ?? [])));
        $this->assertGreaterThanOrEqual(5, count((array) ($sectionMap['facet_details']['blocks'] ?? [])));
        $this->assertNotEmpty((array) ($sectionMap['methodology_and_access']['blocks'] ?? []));
    }
}
